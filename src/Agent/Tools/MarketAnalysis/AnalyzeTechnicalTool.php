<?php

namespace Fixzy\Kriptobot\Agent\Tools\MarketAnalysis;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Database\Database;

class AnalyzeTechnicalTool implements ToolInterface
{
    private BinanceDataIngester $ingester;

    public function __construct()
    {
        $conn = Database::getConnection();
        $this->ingester = new BinanceDataIngester($conn);
    }

    public function getName(): string
    {
        return 'analyze_technical';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Run technical indicator (TA) analysis for a cryptocurrency pair on specified timeframes. Supports RSI, Bollinger Bands, and QFL. Use to assess overbought/oversold conditions, volatility bands, and support/resistance levels.'
            : 'Run technical indicator analysis (TA) for a cryptocurrency pair on specified timeframes. Supports RSI, Bollinger Bands, and QFL. Use to assess overbought/oversold conditions, volatility bands, and support/resistance levels.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair' => [
                    'type'        => 'string',
                    'description' => 'Trading pair, e.g. BTC/USDT',
                ],
                'indicators' => [
                    'type'        => 'array',
                    'items'       => [
                        'type' => 'object',
                        'properties' => [
                            'type'      => ['type' => 'string', 'enum' => ['rsi', 'bollinger', 'qfl', 'all']],
                            'timeframe' => ['type' => 'string', 'enum' => ['5m', '15m', '1h', '4h', '1d']],
                            'period'    => ['type' => 'integer'],
                        ],
                    ],
                    'description' => 'List of indicators to analyze. Use "all" type to get comprehensive analysis.',
                ],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = $params['pair'] ?? 'BTC/USDT';
        $indicatorRequests = $params['indicators'] ?? [['type' => 'all', 'timeframe' => '1h']];

        try {
            $result = [
                'pair'       => strtoupper($pair),
                'timestamp'  => date('Y-m-d H:i:s'),
            ];

            foreach ($indicatorRequests as $req) {
                $type = $req['type'] ?? 'all';
                $timeframe = $req['timeframe'] ?? '1h';
                $period = $req['period'] ?? 14;

                $candles = $this->getCandles($pair, $timeframe, 100);

                if (empty($candles)) {
                    if (!isset($result[$timeframe])) {
                        $result[$timeframe] = ['error' => 'No candle data available'];
                    }
                    continue;
                }

                $closePrices = array_column($candles, 4);
                $highPrices = array_column($candles, 2);
                $lowPrices = array_column($candles, 3);

                if ($type === 'all' || $type === 'rsi') {
                    $result[$timeframe] = is_array($result[$timeframe] ?? null) ? $result[$timeframe] : [];
                    $result[$timeframe]['rsi'] = $this->calculateRsi($closePrices, $period);
                }

                if ($type === 'all' || $type === 'bollinger') {
                    $result[$timeframe]['bollinger'] = $this->calculateBollinger(
                        $closePrices, $req['period'] ?? 20, $req['stddev'] ?? 2.0
                    );
                }

                if ($type === 'all' || $type === 'qfl') {
                    $result[$timeframe]['qfl'] = $this->calculateQfl($lowPrices, $highPrices, $closePrices);
                }
            }

            return ToolResult::ok($result);

        } catch (\Throwable $e) {
            return ToolResult::fail('Technical analysis failed: ' . $e->getMessage());
        }
    }

    private function getCandles(string $pair, string $timeframe, int $limit): array
    {
        $candleMap = [
            '1m' => '1m', '5m' => '5m', '15m' => '15m',
            '1h' => '1h', '4h' => '4h', '1d' => '1d',
        ];

        $tf = $candleMap[$timeframe] ?? '1h';

        try {
            return $this->ingester->fetchCandles(
                strtoupper($pair),
                strtotime("-{$limit} hours") * 1000,
                time() * 1000,
                $tf
            );
        } catch (\Throwable $e) {
            // Fallback to CCXT live fetch
            try {
                $exchange = new \ccxt\binance(['enableRateLimit' => true]);
                $raw = $exchange->fetch_ohlcv(strtoupper($pair), $tf, $limit);
                return $raw;
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    private function calculateRsi(array $prices, int $period = 14): array
    {
        if (count($prices) < $period + 1) {
            return ['value' => null, 'error' => 'Insufficient data'];
        }

        $gains = 0;
        $losses = 0;

        for ($i = count($prices) - $period; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses -= $change;
            }
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        if ($avgLoss == 0) {
            $rsi = 100;
        } else {
            $rs = $avgGain / $avgLoss;
            $rsi = 100 - (100 / (1 + $rs));
        }

        $interpretation = 'neutral';
        if ($rsi > 70) $interpretation = 'overbought';
        elseif ($rsi < 30) $interpretation = 'oversold';
        elseif ($rsi > 60) $interpretation = 'bullish';
        elseif ($rsi < 40) $interpretation = 'bearish';

        $latestPrice = end($prices);

        return [
            'value'          => round($rsi, 2),
            'interpretation' => $interpretation,
            'period'         => $period,
            'current_price'  => $latestPrice,
        ];
    }

    private function calculateBollinger(array $prices, int $period = 20, float $stddev = 2.0): array
    {
        if (count($prices) < $period) {
            return ['error' => 'Insufficient data'];
        }

        $recentPrices = array_slice($prices, -$period);
        $sma = array_sum($recentPrices) / $period;

        $variance = 0;
        foreach ($recentPrices as $price) {
            $variance += pow($price - $sma, 2);
        }
        $std = sqrt($variance / $period);

        $upper = $sma + ($stddev * $std);
        $lower = $sma - ($stddev * $std);
        $currentPrice = end($prices);

        $percentB = $std > 0 ? ($currentPrice - $lower) / ($upper - $lower) : 0.5;

        $position = 'middle';
        if ($currentPrice > $upper) $position = 'above_upper';
        elseif ($currentPrice < $lower) $position = 'below_lower';
        elseif ($currentPrice > $sma) $position = 'above_middle';
        else $position = 'below_middle';

        $bandWidth = $sma > 0 ? (($upper - $lower) / $sma) * 100 : 0;

        return [
            'upper'      => round($upper, 8),
            'middle'     => round($sma, 8),
            'lower'      => round($lower, 8),
            'percent_b'  => round($percentB, 4),
            'position'   => $position,
            'band_width' => round($bandWidth, 2),
            'period'     => $period,
            'stddev'     => $stddev,
        ];
    }

    private function calculateQfl(array $lows, array $highs, array $closes = []): array
    {
        if (count($lows) < 10) {
            return ['error' => 'Insufficient data'];
        }

        $recentLows = array_slice($lows, -20);
        $recentHighs = array_slice($highs, -20);

        $baseLevel = min($recentLows);
        $resistanceLevel = max($recentHighs);

        $currentPrice = !empty($closes) ? end($closes) : end($lows);
        $supportDistance = $baseLevel > 0 ? (($currentPrice - $baseLevel) / $baseLevel) * 100 : 0;

        return [
            'base_level'              => round($baseLevel, 8),
            'resistance_level'        => round($resistanceLevel, 8),
            'support_distance_pct'    => round($supportDistance, 2),
            'below_base_3'            => $supportDistance <= -3,
            'below_base_5'            => $supportDistance <= -5,
            'at_base'                 => abs($supportDistance) < 1,
        ];
    }
}
