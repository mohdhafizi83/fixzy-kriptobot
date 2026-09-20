#!/usr/bin/env php
<?php

/**
 * Fixzy Kriptobot - Binance Historical Data Ingester CLI
 *
 * Usage:
 *   php bin/ingest_data.php --symbol=BTCUSDT --days=30
 *   php bin/ingest_data.php --symbol=WLDUSDT --days=60 --verbose
 *   php bin/ingest_data.php --symbol=ETHUSDT --status
 *
 * Options:
 *   --symbol=XXXX   (Required) Trading pair name, e.g.: BTCUSDT, WLDUSDT
 *   --days=N        (Optional) Number of days back. Default: 30
 *   --status        (Optional) Only show data status without downloading
 *   --verbose       (Optional) Show detailed output (default: enabled)
 *   --quiet         (Optional) Silence output (useful for cron jobs)
 */

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/vendor/autoload.php';

use Fixzy\Kriptobot\Backtest\BinanceDataIngester;

// ─── Parse CLI arguments ──────────────────────────────────────────────────────
$opts = getopt('', ['symbol:', 'days:', 'status', 'verbose', 'quiet']);

$symbol  = strtoupper(trim($opts['symbol'] ?? ''));
$days    = (int)($opts['days'] ?? 30);
$status  = isset($opts['status']);
$verbose = !isset($opts['quiet']); // Verbose by default unless --quiet is used

// Input validation
if (empty($symbol)) {
    echo "❌ ERROR: Please specify the pair name with --symbol=BTCUSDT\n";
    echo "Usage examples:\n";
    echo "  php bin/ingest_data.php --symbol=BTCUSDT --days=30\n";
    echo "  php bin/ingest_data.php --symbol=WLDUSDT --status\n";
    exit(1);
}

if ($days < 1 || $days > 365) {
    echo "❌ ERROR: --days must be between 1 and 365.\n";
    exit(1);
}

// ─── Bootstrap ──────────────────────────────────────────────────────────────
try {
    $services = require ROOT_DIR . '/src/bootstrap.php';
    $db = $services['db'];
} catch (Exception $e) {
    echo "❌ Database connection error: " . $e->getMessage() . "\n";
    exit(1);
}

// ─── Ensure database tables exist ─────────────────────────────────────────
try {
    $db->executeStatement("
        CREATE TABLE IF NOT EXISTS `historical_ohlcv` (
          `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `symbol`     VARCHAR(20)     NOT NULL,
          `open_time`  BIGINT UNSIGNED NOT NULL,
          `open`       DECIMAL(20, 8)  NOT NULL,
          `high`       DECIMAL(20, 8)  NOT NULL,
          `low`        DECIMAL(20, 8)  NOT NULL,
          `close`      DECIMAL(20, 8)  NOT NULL,
          `volume`     DECIMAL(30, 8)  NOT NULL,
          `close_time` BIGINT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_symbol_time` (`symbol`, `open_time`),
          INDEX `idx_symbol_time` (`symbol`, `open_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->executeStatement("
        CREATE TABLE IF NOT EXISTS `backtest_ingestion_log` (
          `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `symbol`        VARCHAR(20)  NOT NULL,
          `date`          DATE         NOT NULL,
          `candles_count` INT UNSIGNED NOT NULL DEFAULT 0,
          `status`        ENUM('success', 'failed', 'skipped') NOT NULL DEFAULT 'success',
          `message`       TEXT         DEFAULT NULL,
          `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_symbol_date` (`symbol`, `date`),
          INDEX `idx_symbol` (`symbol`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    echo "⚠️  Warning (Table creation): " . $e->getMessage() . "\n";
}

// ─── Run ingestion ──────────────────────────────────────────────────────
$ingester = new BinanceDataIngester($db);

if ($status) {
    // Status mode: only show information without downloading
    echo "\n📊 Data Status for [{$symbol}]:\n";
    echo str_repeat('─', 50) . "\n";

    $info = $ingester->getDataStatus($symbol);

    if (!$info['has_data']) {
        echo "   No data in the database for [{$symbol}].\n";
        echo "   Run: php bin/ingest_data.php --symbol={$symbol} --days=30\n";
    } else {
        echo "   ✔ Days available  : " . $info['days_available'] . " days\n";
        echo "   ✔ Total candles   : " . number_format($info['total_candles']) . "\n";
        echo "   ✔ Earliest data   : " . $info['earliest_date'] . "\n";
        echo "   ✔ Latest data     : " . $info['latest_date'] . "\n";
    }
    echo "\n";
    exit(0);
}

// Safety warning for a large number of days
if ($days > 90 && $verbose) {
    echo "\n⚠️  WARNING: You requested {$days} days of data.\n";
    echo "   This will take a long time. Make sure you do not get your IP banned.\n";
    echo "   Fixzy Kriptobot will pause 2 seconds between each download.\n";
    echo "   Estimated time: " . ceil($days * 2 / 60) . " minutes.\n";
    echo "\n   Continue? (y/n): ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "Cancelled.\n";
        exit(0);
    }
}

echo "\n";
$startTime = microtime(true);

try {
    $result = $ingester->ingest($symbol, date('Y-m-d', strtotime("-{$days} days")), date('Y-m-d'), $verbose);
    $elapsed = round(microtime(true) - $startTime, 1);

    if ($verbose) {
        echo "\n⏱  Time taken: {$elapsed} seconds\n";

        if (!empty($result['errors'])) {
            echo "\n⚠️  Errors that occurred:\n";
            foreach ($result['errors'] as $err) {
                echo "   • {$err}\n";
            }
        }
    }

    exit($result['days_failed'] > 0 ? 1 : 0);

} catch (Exception $e) {
    echo "❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
