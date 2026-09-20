<?php

namespace Fixzy\Kriptobot\Backtest;

use Doctrine\DBAL\Connection;
use Exception;
use RuntimeException;

/**
 * BinanceDataIngester
 *
 * Responsible for downloading, extracting, and storing
 * 1-minute OHLCV data from Binance Vision Public Data into the database.
 *
 * Source: https://data.binance.vision/?prefix=data/spot/daily/klines/{SYMBOL}/1m/
 *
 * Safety & Limitations Considered:
 * - HTTP 403/404: File does not exist for the given date (today / future)
 * - HTTP 429/503: Binance Vision may throttle sequential requests
 * - Timeout: ZIP files can reach 1-3MB, slow connections may time out
 * - Disk Space: Temporary ZIP and CSV files are stored in /tmp/ and removed after import
 * - Data Integrity: Binance CSVs may contain empty rows or headers, which we skip
 * - Duplicate Prevention: UPSERT (INSERT IGNORE) ensures no duplicated data
 * - Rate Limiting: Each request is spaced out with sleep() to avoid IP bans
 */
class BinanceDataIngester
{
    private const BASE_URL = 'https://data.binance.vision/data/spot/daily/klines';
    private const TIMEFRAME = '1m';

    // Rest period between each download (seconds) - to avoid IP bans
    private const SLEEP_BETWEEN_REQUESTS = 2;

    // Maximum number of retries if a download fails
    private const MAX_RETRIES = 3;

    // Download timeout in seconds (3MB file @ 100KB/s = ~30s)
    private const DOWNLOAD_TIMEOUT = 90;

    private Connection $db;
    private string $tmpDir;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kriptobot_backtest';

        // Ensure the temporary directory exists
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0755, true);
        }
    }

    /**
     * Main entry point for the ingestion process.
     *
     * @param string $symbol   Trading pair name, e.g. 'BTCUSDT', 'WLDUSDT'
     * @param string $fromDate Start date (Y-m-d)
     * @param string $toDate   End date (Y-m-d)
     * @param bool   $verbose  Show detailed output
     * @return array           Process summary statistics
     */
    public function ingest(string $symbol, string $fromDate, string $toDate, bool $verbose = true): array
    {
        $symbol = strtoupper(trim($symbol));
        $this->log("🚀 Starting ingestion process for [{$symbol}] from {$fromDate} to {$toDate}...", $verbose);

        $stats = [
            'symbol'        => $symbol,
            'from_date'     => $fromDate,
            'to_date'       => $toDate,
            'days_success'  => 0,
            'days_skipped'  => 0,
            'days_failed'   => 0,
            'total_candles' => 0,
            'errors'        => [],
        ];

        $dates = $this->generateDateRange($fromDate, $toDate);

        foreach ($dates as $date) {
            $result = $this->processDate($symbol, $date, $verbose);

            if ($result['status'] === 'success') {
                $stats['days_success']++;
                $stats['total_candles'] += $result['candles'];
            } elseif ($result['status'] === 'skipped') {
                $stats['days_skipped']++;
            } else {
                $stats['days_failed']++;
                $stats['errors'][] = "[{$date}] " . $result['message'];
            }

            // Rest between each request to avoid rate limiting / bans
            if ($result['status'] !== 'skipped') {
                sleep(self::SLEEP_BETWEEN_REQUESTS);
            }
        }

        $this->log("\n✅ Done! Summary:", $verbose);
        $this->log("   ✔ Success : {$stats['days_success']} days ({$stats['total_candles']} candles)", $verbose);
        $this->log("   ⏭ Skipped : {$stats['days_skipped']} days (already in DB)", $verbose);
        $this->log("   ✗ Failed  : {$stats['days_failed']} days", $verbose);

        return $stats;
    }

    /**
     * Process one date: download ZIP, extract CSV, import into DB.
     */
    private function processDate(string $symbol, string $date, bool $verbose): array
    {
        // First check the log: has this date already been imported successfully?
        if ($this->isAlreadyIngested($symbol, $date)) {
            $this->log("   ⏭ [{$date}] Already in database, skipped.", $verbose);
            return ['status' => 'skipped', 'candles' => 0, 'message' => ''];
        }

        $this->log("   📥 [{$date}] Downloading...", $verbose);

        // Build URL: BTCUSDT/1m/BTCUSDT-1m-2024-01-15.zip
        $filename = "{$symbol}-" . self::TIMEFRAME . "-{$date}.zip";
        $url = self::BASE_URL . "/{$symbol}/" . self::TIMEFRAME . "/{$filename}";
        $zipPath = $this->tmpDir . DIRECTORY_SEPARATOR . $filename;

        // Download the file with retries
        $downloaded = $this->downloadWithRetry($url, $zipPath, $verbose);

        if (!$downloaded) {
            $this->logIngestion($symbol, $date, 0, 'failed', "Download failed after " . self::MAX_RETRIES . " attempts.");
            return ['status' => 'failed', 'candles' => 0, 'message' => "Download failed for {$date}"];
        }

        // Extract the CSV file from the ZIP
        $csvPath = $this->extractZip($zipPath, $verbose);

        // Remove the ZIP file after extraction (save disk space)
        @unlink($zipPath);

        if (!$csvPath) {
            $this->logIngestion($symbol, $date, 0, 'failed', "Failed to extract ZIP.");
            return ['status' => 'failed', 'candles' => 0, 'message' => "Failed to extract ZIP for {$date}"];
        }

        // Import the CSV into the database
        $candleCount = $this->importCsv($symbol, $csvPath, $verbose);

        // Remove the CSV file after import (save disk space)
        @unlink($csvPath);

        if ($candleCount < 0) {
            $this->logIngestion($symbol, $date, 0, 'failed', "Failed to import CSV into database.");
            return ['status' => 'failed', 'candles' => 0, 'message' => "Failed to import for {$date}"];
        }

        // Record success in the log
        $this->logIngestion($symbol, $date, $candleCount, 'success', null);
        $this->log("   ✔ [{$date}] Success! {$candleCount} candles imported.", $verbose);

        return ['status' => 'success', 'candles' => $candleCount, 'message' => ''];
    }

    /**
     * Download a file from a URL with retries on failure.
     * Handles HTTP 403, 404, 429, timeouts, and connection errors.
     */
    private function downloadWithRetry(string $url, string $destPath, bool $verbose): bool
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 1) {
                $wait = $attempt * 5; // Backoff: 5s, 10s, 15s
                $this->log("      ↻ Retry #{$attempt} in {$wait} seconds...", $verbose);
                sleep($wait);
            }

            $result = $this->curlDownload($url, $destPath);

            if ($result['success']) {
                return true;
            }

            $httpCode = $result['http_code'];

            // HTTP 404: File does not exist (date not available yet / wrong pair name)
            // No need to retry
            if ($httpCode === 404) {
                $this->log("      ⚠ HTTP 404: File does not exist for this date (maybe not uploaded yet). Skip.", $verbose);
                return false;
            }

            // HTTP 403: Binance may be restricting access from our IP
            // Wait longer before retrying
            if ($httpCode === 403) {
                $this->log("      ⚠ HTTP 403: Access denied. Waiting 30 seconds...", $verbose);
                sleep(30);
                continue;
            }

            // HTTP 429: Rate limited - wait longer
            if ($httpCode === 429) {
                $this->log("      ⚠ HTTP 429: Too Many Requests! Waiting 60 seconds...", $verbose);
                sleep(60);
                continue;
            }

            // Other errors (connection timeout, DNS failure, etc.)
            $this->log("      ✗ Failed (HTTP {$httpCode}): {$result['error']}", $verbose);
        }

        return false;
    }

    /**
     * Perform the actual download using cURL.
     * More reliable than file_get_contents for large files.
     */
    private function curlDownload(string $url, string $destPath): array
    {
        $fp = fopen($destPath, 'wb');
        if (!$fp) {
            return ['success' => false, 'http_code' => 0, 'error' => 'Failed to open file for writing'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'FixzyKriptobot-DataIngester/1.0',
            // Enable progress checking (avoid stuck downloads)
            CURLOPT_NOPROGRESS     => false,
        ]);

        $success  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        // If the HTTP code is not 200, delete the possibly incomplete file
        if ($httpCode !== 200) {
            @unlink($destPath);
            return ['success' => false, 'http_code' => $httpCode, 'error' => $error];
        }

        return ['success' => true, 'http_code' => 200, 'error' => ''];
    }

    /**
     * Extract the CSV file from the ZIP.
     * Binance Vision ZIPs usually contain only one CSV file.
     * @return string|null Path to the extracted CSV file, or null on failure
     */
    private function extractZip(string $zipPath, bool $verbose): ?string
    {
        if (!extension_loaded('zip')) {
            // Fallback: use the unzip command line if the PHP zip extension is unavailable
            return $this->extractZipCli($zipPath, $verbose);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->log("      ✗ Failed to open ZIP file.", $verbose);
            return null;
        }

        // Find the CSV file in the ZIP (usually just one)
        $csvFile = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.csv')) {
                $csvFile = $name;
                break;
            }
        }

        if (!$csvFile) {
            $zip->close();
            $this->log("      ✗ No CSV file in the ZIP.", $verbose);
            return null;
        }

        $extractPath = $this->tmpDir . DIRECTORY_SEPARATOR . basename($csvFile);
        $zip->extractTo($this->tmpDir, $csvFile);
        $zip->close();

        return file_exists($extractPath) ? $extractPath : null;
    }

    /**
     * Fallback ZIP extraction using the command line (if the PHP zip extension is missing)
     */
    private function extractZipCli(string $zipPath, bool $verbose): ?string
    {
        $cmd = "unzip -o " . escapeshellarg($zipPath) . " -d " . escapeshellarg($this->tmpDir) . " 2>&1";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->log("      ✗ Failed to extract ZIP via CLI: " . implode("\n", $output), $verbose);
            return null;
        }

        // Find the CSV file in the command output
        foreach ($output as $line) {
            if (str_contains($line, '.csv')) {
                preg_match('/inflating:\s+(.+\.csv)/', $line, $matches);
                if (!empty($matches[1])) {
                    $path = trim($matches[1]);
                    return file_exists($path) ? $path : null;
                }
            }
        }

        return null;
    }

    /**
     * Import the Binance CSV file into the database using batch INSERT.
     *
     * Binance 1m OHLCV CSV format:
     * open_time, open, high, low, close, volume, close_time,
     * quote_asset_volume, number_of_trades, taker_buy_base, taker_buy_quote, ignore
     *
     * @return int Number of candles successfully imported, -1 on failure
     */
    private function importCsv(string $symbol, string $csvPath, bool $verbose): int
    {
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return -1;
        }

        $count = 0;
        $batchSize = 500; // Import 500 rows at a time for optimum performance
        $batch = [];

        $this->db->beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty rows or headers (Binance sometimes has a text header)
                if (!isset($row[0]) || !is_numeric($row[0])) {
                    continue;
                }

                $openTime  = (int)$row[0];
                $closeTime = (int)$row[6];
                
                // Normalize to milliseconds if in microseconds (16 digits)
                if ($openTime > 9999999999999) {
                    $openTime  = (int)($openTime / 1000);
                    $closeTime = (int)($closeTime / 1000);
                }

                $batch[] = [
                    'symbol'     => $symbol,
                    'open_time'  => $openTime,
                    'open'       => (float)$row[1],
                    'high'       => (float)$row[2],
                    'low'        => (float)$row[3],
                    'close'      => (float)$row[4],
                    'volume'     => (float)$row[5],
                    'close_time' => $closeTime,
                ];

                // Flush the batch when the optimum size is reached
                if (count($batch) >= $batchSize) {
                    $count += $this->batchInsert($batch);
                    $batch = [];
                }
            }

            // Flush the remaining rows
            if (!empty($batch)) {
                $count += $this->batchInsert($batch);
            }

            $this->db->commit();
            fclose($handle);
            return $count;

        } catch (Exception $e) {
            $this->db->rollBack();
            fclose($handle);
            return -1;
        }
    }

    /**
     * Insert a batch of records into the database.
     * Uses INSERT IGNORE to avoid data duplication.
     */
    private function batchInsert(array $batch): int
    {
        if (empty($batch)) {
            return 0;
        }

        // Build manual SQL for batch INSERT IGNORE (faster than a DBAL loop)
        $placeholders = [];
        $values = [];

        foreach ($batch as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            $values[] = $row['symbol'];
            $values[] = $row['open_time'];
            $values[] = $row['open'];
            $values[] = $row['high'];
            $values[] = $row['low'];
            $values[] = $row['close'];
            $values[] = $row['volume'];
            $values[] = $row['close_time'];
        }

        $sql = "INSERT IGNORE INTO `historical_ohlcv`
                (`symbol`, `open_time`, `open`, `high`, `low`, `close`, `volume`, `close_time`)
                VALUES " . implode(', ', $placeholders);

        return (int)$this->db->executeStatement($sql, $values);
    }

    /**
     * Generate the list of dates from $fromDate through $toDate.
     * Today's and future data is not fetched because Binance has not uploaded it yet.
     */
    private function generateDateRange(string $fromDate, string $toDate): array
    {
        $dates = [];
        $start = new \DateTime($fromDate);
        $end = new \DateTime($toDate);
        
        $yesterday = new \DateTime('yesterday');
        if ($end > $yesterday) {
            $end = $yesterday;
        }

        $current = clone $start;
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Check whether a specific date has already been imported successfully.
     */
    private function isAlreadyIngested(string $symbol, string $date): bool
    {
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) FROM `backtest_ingestion_log`
             WHERE `symbol` = ? AND `date` = ? AND `status` = 'success'",
            [$symbol, $date]
        );

        return (int)$count > 0;
    }

    /**
     * Record the ingestion status in the log table.
     */
    private function logIngestion(string $symbol, string $date, int $count, string $status, ?string $message): void
    {
        // Use INSERT ... ON DUPLICATE KEY UPDATE in case the record already exists
        $this->db->executeStatement(
            "INSERT INTO `backtest_ingestion_log` (`symbol`, `date`, `candles_count`, `status`, `message`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               `candles_count` = VALUES(`candles_count`),
               `status` = VALUES(`status`),
               `message` = VALUES(`message`),
               `created_at` = NOW()",
            [$symbol, $date, $count, $status, $message]
        );
    }

    /**
     * Helper for outputting messages to the screen.
     */
    private function log(string $message, bool $verbose): void
    {
        if ($verbose) {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Get data status information for a pair.
     * Useful for the backtesting UI (shows how many days of data are in the DB).
     */
    public function getDataStatus(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));

        $result = $this->db->fetchAssociative(
            "SELECT
                COUNT(DISTINCT DATE(FROM_UNIXTIME(open_time / 1000))) AS days_available,
                MIN(open_time) AS earliest_ms,
                MAX(open_time) AS latest_ms,
                COUNT(*) AS total_candles
             FROM `historical_ohlcv`
             WHERE `symbol` = ?",
            [$symbol]
        );

        if (!$result || (int)$result['total_candles'] === 0) {
            return [
                'symbol' => $symbol,
                'has_data' => false,
                'days_available' => 0,
                'total_candles' => 0,
                'earliest_date' => null,
                'latest_date' => null,
            ];
        }

        return [
            'symbol' => $symbol,
            'has_data' => true,
            'days_available' => (int)$result['days_available'],
            'total_candles' => (int)$result['total_candles'],
            'earliest_date' => date('Y-m-d H:i', (int)$result['earliest_ms'] / 1000),
            'latest_date' => date('Y-m-d H:i', (int)$result['latest_ms'] / 1000),
        ];
    }

    /**
     * Fetch OHLCV data from the database for use in the Backtest Engine.
     * Data is returned in the same format as CCXT fetch_ohlcv().
     *
     * @param string $symbol   Trading pair
     * @param int    $from     Unix timestamp (ms) - start
     * @param int    $to       Unix timestamp (ms) - end
     * @param string $tf       Requested timeframe: '1m', '5m', '15m', '1h', '4h'
     * @return array           Array of [timestamp, open, high, low, close, volume]
     */
    public function fetchCandles(string $symbol, int $from, int $to, string $tf = '1m'): array
    {
        $symbol = strtoupper(str_replace('/', '', trim($symbol)));

        // For 1m data, fetch directly
        if ($tf === '1m') {
            $rows = $this->db->fetchAllAssociative(
                "SELECT `open_time`, `open`, `high`, `low`, `close`, `volume`
                 FROM `historical_ohlcv`
                 WHERE `symbol` = ? AND `open_time` >= ? AND `open_time` <= ?
                 ORDER BY `open_time` ASC",
                [$symbol, $from, $to]
            );

            return array_map(fn($r) => [
                (int)$r['open_time'],
                (float)$r['open'],
                (float)$r['high'],
                (float)$r['low'],
                (float)$r['close'],
                (float)$r['volume'],
            ], $rows);
        }

        // For larger timeframes, aggregate from the 1m data
        return $this->aggregateCandles($symbol, $from, $to, $tf);
    }

    /**
     * Aggregate 1m data into larger timeframes using SQL.
     * This allows a single 1m dataset to be used to test multiple timeframe strategies.
     */
    private function aggregateCandles(string $symbol, int $from, int $to, string $tf): array
    {
        // Set the number of minutes for each timeframe
        $minutes = match($tf) {
            '5m'  => 5,
            '15m' => 15,
            '30m' => 30,
            '1h'  => 60,
            '2h'  => 120,
            '4h'  => 240,
            '1d'  => 1440,
            default => 60, // Default to 1h
        };

        $intervalMs = $minutes * 60 * 1000; // Convert to milliseconds

        // Aggregate using SQL GROUP BY based on time blocks
        $rows = $this->db->fetchAllAssociative(
            "SELECT
                FLOOR(`open_time` / ?) * ? AS candle_time,
                (SELECT `open` FROM `historical_ohlcv` h2
                 WHERE h2.`symbol` = h.`symbol`
                   AND FLOOR(h2.`open_time` / ?) * ? = FLOOR(h.`open_time` / ?) * ?
                 ORDER BY h2.`open_time` ASC LIMIT 1) AS `open`,
                MAX(`high`) AS `high`,
                MIN(`low`) AS `low`,
                (SELECT `close` FROM `historical_ohlcv` h3
                 WHERE h3.`symbol` = h.`symbol`
                   AND FLOOR(h3.`open_time` / ?) * ? = FLOOR(h.`open_time` / ?) * ?
                 ORDER BY h3.`open_time` DESC LIMIT 1) AS `close`,
                SUM(`volume`) AS `volume`
             FROM `historical_ohlcv` h
             WHERE `symbol` = ? AND `open_time` >= ? AND `open_time` <= ?
             GROUP BY candle_time
             ORDER BY candle_time ASC",
            [$intervalMs, $intervalMs, $intervalMs, $intervalMs, $intervalMs, $intervalMs,
             $intervalMs, $intervalMs, $intervalMs, $intervalMs, $symbol, $from, $to]
        );

        return array_map(fn($r) => [
            (int)$r['candle_time'],
            (float)$r['open'],
            (float)$r['high'],
            (float)$r['low'],
            (float)$r['close'],
            (float)$r['volume'],
        ], $rows);
    }
}
