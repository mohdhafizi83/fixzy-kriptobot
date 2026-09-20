<?php

namespace Fixzy\Kriptobot\Agent\Tools\Data;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Database\Database;

class GetHistoricalDataTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_historical_data';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Get historical OHLCV data for a cryptocurrency pair. Useful for backtesting and historical performance analysis. Check data availability before running backtests.'
            : 'Get historical OHLCV data for a cryptocurrency pair. Useful for backtesting and historical performance analysis. Check data availability before running backtests.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair'      => ['type' => 'string', 'description' => 'Trading pair, e.g. BTC/USDT'],
                'timeframe' => ['type' => 'string', 'enum' => ['5m', '15m', '1h', '4h', '1d'], 'description' => 'Candle timeframe'],
                'from_date' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                'to_date'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                'action'    => ['type' => 'string', 'enum' => ['check', 'fetch_summary', 'fetch_recent'], 'description' => 'Data action'],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = strtoupper($params['pair'] ?? 'BTC/USDT');
        $timeframe = $params['timeframe'] ?? '1h';
        $action = $params['action'] ?? 'check';

        try {
            $conn = Database::getConnection();
            $ingester = new BinanceDataIngester($conn);

            if ($action === 'check') {
                $fromDate = $params['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
                $toDate   = $params['to_date'] ?? date('Y-m-d');

                $fromMs = strtotime($fromDate . ' 00:00:00') * 1000;
                $toMs   = strtotime($toDate . ' 23:59:59') * 1000;

                $candles = $ingester->fetchCandles($pair, $fromMs, $toMs, $timeframe);

                return ToolResult::ok([
                    'pair'          => $pair,
                    'timeframe'     => $timeframe,
                    'from_date'     => $fromDate,
                    'to_date'       => $toDate,
                    'candles_count' => count($candles),
                    'available'     => count($candles) >= 50,
                    'message'       => count($candles) >= 50
                        ? 'Data sufficient for backtesting'
                        : 'Insufficient data. Need at least 50 candles. Consider ingesting data first.',
                ]);
            }

            if ($action === 'fetch_recent') {
                $candles = $ingester->fetchCandles(
                    $pair,
                    strtotime('-72 hours') * 1000,
                    time() * 1000,
                    $timeframe
                );

                $recent = array_slice($candles, -10);
                $summary = array_map(fn($c) => [
                    'time'  => date('Y-m-d H:i', (int)$c[0] / 1000),
                    'open'  => (float)$c[1],
                    'high'  => (float)$c[2],
                    'low'   => (float)$c[3],
                    'close' => (float)$c[4],
                    'volume' => (float)$c[5],
                ], $recent);

                return ToolResult::ok([
                    'pair'         => $pair,
                    'timeframe'    => $timeframe,
                    'recent_candles' => $summary,
                    'total_available' => count($candles),
                ]);
            }

            return ToolResult::fail("Unknown action: {$action}");

        } catch (\Throwable $e) {
            return ToolResult::fail('Historical data fetch failed: ' . $e->getMessage());
        }
    }
}
