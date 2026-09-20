<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Backtest\BacktestEngine;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Trading\Rules\ExecutionGuardService;

/**
 * BacktestService — owns backtest data ingestion + simulation for the API.
 *
 * Previously this logic (including CREATE TABLE statements) lived directly in
 * public/api/backtest_run.php. Moved here so no SQL lives in public/.
 */
class BacktestService
{
    private Connection $db;
    private BinanceDataIngester $ingester;
    private BacktestEngine $engine;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->ingester = new BinanceDataIngester($this->db);
        $this->engine = new BacktestEngine(
            $this->ingester,
            new AuditLogger($this->db),
            new ExecutionGuardService($this->db)
        );
        $this->ensureSchema();
    }

    /**
     * Create backtest tables if they do not exist (idempotent, SQLite-safe).
     */
    public function ensureSchema(): void
    {
        $this->db->executeStatement("CREATE TABLE IF NOT EXISTS historical_ohlcv (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT NOT NULL,
            open_time INTEGER NOT NULL,
            open REAL NOT NULL,
            high REAL NOT NULL,
            low REAL NOT NULL,
            close REAL NOT NULL,
            volume REAL NOT NULL,
            close_time INTEGER NOT NULL
        )");
        $this->db->executeStatement("CREATE UNIQUE INDEX IF NOT EXISTS uq_symbol_time ON historical_ohlcv (symbol, open_time)");
        $this->db->executeStatement("CREATE TABLE IF NOT EXISTS backtest_ingestion_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            symbol TEXT NOT NULL,
            date TEXT NOT NULL,
            candles_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'success',
            message TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->executeStatement("CREATE UNIQUE INDEX IF NOT EXISTS uq_symbol_date ON backtest_ingestion_log (symbol, date)");
    }

    /**
     * Data availability status for a symbol.
     */
    public function status(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return ['error' => 'Symbol required'];
        }
        return $this->ingester->getDataStatus($symbol);
    }

    /**
     * Ingest historical candles from Binance Vision.
     */
    public function ingest(string $symbol, string $fromDate, string $toDate): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return ['error' => 'Symbol required'];
        }
        set_time_limit(300);
        return $this->ingester->ingest($symbol, $fromDate, $toDate, false);
    }

    /**
     * Run a backtest simulation.
     *
     * @param array<string, mixed> $input API payload
     * @return array<string, mixed>
     */
    public function run(array $input, int $userId, int $botId = 0): array
    {
        $symbol    = strtoupper(trim((string)($input['symbol'] ?? '')));
        $fromDate  = $input['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $toDate    = $input['to_date']   ?? date('Y-m-d', strtotime('-1 day'));
        $timeframe = $input['timeframe'] ?? '1h';

        if ($symbol === '') {
            return ['error' => 'Symbol required'];
        }

        $status = $this->ingester->getDataStatus($symbol);
        if (empty($status['has_data'])) {
            return ['error' => "No data available for {$symbol}. Please import data first."];
        }

        // Conditions: full bot format (all_conditions) or manual condition_type/value.
        $conditions = [];
        if (!empty($input['all_conditions']) && is_array($input['all_conditions'])) {
            $conditions = $input['all_conditions'];
        } elseif (!empty($input['condition_type'])) {
            $types  = (array)$input['condition_type'];
            $values = (array)($input['condition_value'] ?? []);
            foreach ($types as $idx => $type) {
                if (!empty($type)) {
                    $conditions[] = [
                        'type'      => $type,
                        'value'     => $values[$idx] ?? '',
                        'timeframe' => $input['timeframe'] ?? '1h',
                        'period'    => (int)($input['rsi_period'] ?? 14),
                        'stddev'    => (float)($input['bb_stddev'] ?? 2.0),
                    ];
                }
            }
        }

        $config = [
            'symbol'                 => $symbol,
            'from_date'              => $fromDate,
            'to_date'                => $toDate,
            'timeframe'              => $timeframe,
            'start_conditions'       => $conditions,
            'allocated_capital'      => (float)($input['allocated_capital'] ?? 100),
            'target_profit'          => (float)($input['target_profit'] ?? 2.0),
            'tp_type'                => $input['tp_type'] ?? 'average_price',
            'cut_loss'               => (float)($input['cut_loss'] ?? 0),
            'max_dca_steps'          => (int)($input['max_dca_steps'] ?? 4),
            'price_drop_trigger'     => (float)($input['price_drop_trigger'] ?? 2.0),
            'step_scale'             => (float)($input['step_scale'] ?? 1.0),
            'volume_scale'           => (float)($input['volume_scale'] ?? 1.5),
            'trailing_buy_deviation' => (float)($input['trailing_buy_deviation'] ?? 0),
            'trailing_tp_deviation'  => (float)($input['trailing_tp_deviation'] ?? 0),
            'fee_rate'               => (float)($input['fee_rate'] ?? 0.001),
        ];

        set_time_limit(120);
        return $this->engine->run($config, $userId, $botId);
    }
}
