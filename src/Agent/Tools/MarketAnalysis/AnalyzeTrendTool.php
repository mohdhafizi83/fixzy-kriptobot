<?php

namespace Fixzy\Kriptobot\Agent\Tools\MarketAnalysis;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Database\Database;

class AnalyzeTrendTool implements ToolInterface
{
    public function getName(): string
    {
        return 'analyze_trend';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Analyze market trend direction using multiple timeframes. Identifies rising, falling, or sideways trends based on EMA, price structure (higher highs/lows), and trend strength.'
            : 'Analyze market trend direction using multiple timeframes. Identifies bullish, bearish, or sideways trends based on EMA, price structure (higher highs/lows), and trend strength.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair'       => ['type' => 'string', 'description' => 'Trading pair, e.g. BTC/USDT'],
                'timeframes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['1h', '4h', '1d']],
                    'description' => 'Timeframes to analyze (default: all three)',
                ],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = strtoupper($params['pair'] ?? 'BTC/USDT');
        $timeframes = $params['timeframes'] ?? ['1h', '4h', '1d'];

        try {
            $conn = Database::getConnection();
            $ingester = new BinanceDataIngester($conn);

            $analysis = [];

            foreach ($timeframes as $tf) {
                $lookbackHours = match ($tf) {
                    '1h' => 200,
                    '4h' => 800,
                    '1d' => 4800,
                    default => 200,
                };

                $candles = $ingester->fetchCandles(
                    $pair,
                    strtotime("-{$lookbackHours} hours") * 1000,
                    time() * 1000,
                    $tf
                );

                if (count($candles) < 50) {
                    $analysis[$tf] = ['error' => 'Insufficient data'];
                    continue;
                }

                $closes = array_column($candles, 4);
                $highs  = array_column($candles, 2);
                $lows   = array_column($candles, 3);

                $ema20 = $this->calculateEma($closes, 20);
                $ema50 = $this->calculateEma($closes, 50);
                $ema200 = $this->calculateEma($closes, 200);

                $currentPrice = end($closes);
                $trendDirection = $this->determineTrend(
                    $currentPrice, $ema20, $ema50, $ema200, $highs, $lows
                );

                $trendStrength = $this->calculateTrendStrength($closes, 20);

                $prevCloses = array_slice($closes, 0, -1);
                $previousEma20 = count($prevCloses) >= 20 ? $this->calculateEma($prevCloses, 20) : $ema20;
                $previousEma50 = count($prevCloses) >= 50 ? $this->calculateEma($prevCloses, 50) : $ema50;

                $analysis[$tf] = [
                    'current_price'   => $currentPrice,
                    'ema_20'          => round($ema20, 8),
                    'ema_50'          => round($ema50, 8),
                    'ema_200'         => round($ema200, 8),
                    'direction'       => $trendDirection,
                    'strength'        => round($trendStrength, 4),
                    'strength_label'  => $trendStrength > 5 ? 'strong' : ($trendStrength > 2 ? 'moderate' : 'weak'),
                    'above_ema20'     => $currentPrice > $ema20,
                    'above_ema50'     => $currentPrice > $ema50,
                    'golden_cross'    => $ema20 > $ema50 && $previousEma20 <= $previousEma50,
                ];
            }

            $overallTrend = $this->consolidateTrend($analysis);

            return ToolResult::ok([
                'pair'           => $pair,
                'timeframes'     => $analysis,
                'overall_trend'  => $overallTrend,
                'timestamp'      => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Trend analysis failed: ' . $e->getMessage());
        }
    }

    private function calculateEma(array $prices, int $period): float
    {
        $count = count($prices);
        if ($count < $period) return end($prices);

        $multiplier = 2 / ($period + 1);

        $sma = array_sum(array_slice($prices, 0, $period)) / $period;
        $ema = $sma;

        for ($i = $period; $i < $count; $i++) {
            $ema = ($prices[$i] - $ema) * $multiplier + $ema;
        }

        return $ema;
    }

    private function determineTrend(
        float $price,
        float $ema20,
        float $ema50,
        float $ema200,
        array $highs,
        array $lows
    ): string {
        $recentHighs = array_slice($highs, -10);
        $recentLows  = array_slice($lows, -10);

        $higherHighs = true;
        $higherLows  = true;
        for ($i = 1; $i < count($recentHighs); $i++) {
            if ($recentHighs[$i] <= $recentHighs[$i - 1]) $higherHighs = false;
            if ($recentLows[$i] <= $recentLows[$i - 1]) $higherLows = false;
        }

        if ($price > $ema20 && $ema20 > $ema50) {
            return 'bullish';
        } elseif ($price < $ema20 && $ema20 < $ema50) {
            return 'bearish';
        } elseif ($higherHighs && $higherLows) {
            return 'bullish';
        } elseif (!$higherHighs && !$higherLows) {
            return 'bearish';
        }

        return 'sideways';
    }

    private function calculateTrendStrength(array $prices, int $period): float
    {
        $count = count($prices);
        if ($count < $period + 1) return 0;

        $changes = [];
        for ($i = $count - $period; $i < $count; $i++) {
            $changes[] = ($prices[$i] - $prices[$i - 1]) / $prices[$i - 1] * 100;
        }

        $positiveChanges = array_filter($changes, fn($c) => $c > 0);
        $negativeChanges = array_filter($changes, fn($c) => $c < 0);

        $totalPositive = array_sum($positiveChanges);
        $totalNegative = abs(array_sum($negativeChanges));

        if ($totalNegative == 0) return $totalPositive;

        return $totalPositive / $totalNegative;
    }

    private function consolidateTrend(array $analysis): array
    {
        $directions = [];
        foreach ($analysis as $tf => $data) {
            if (isset($data['direction'])) {
                $directions[$tf] = $data['direction'];
            }
        }

        if (empty($directions)) {
            return ['direction' => 'unknown', 'confidence' => 'none', 'reason' => 'No valid timeframe data'];
        }

        $bullish = count(array_filter($directions, fn($d) => $d === 'bullish'));
        $bearish = count(array_filter($directions, fn($d) => $d === 'bearish'));

        if ($bullish > $bearish && $bullish >= 2) {
            return ['direction' => 'bullish', 'confidence' => 'high'];
        } elseif ($bearish > $bullish && $bearish >= 2) {
            return ['direction' => 'bearish', 'confidence' => 'high'];
        } elseif ($bullish === $bearish && $bullish > 0) {
            return ['direction' => 'mixed', 'confidence' => 'low'];
        }

        return ['direction' => 'neutral', 'confidence' => 'medium'];
    }
}
