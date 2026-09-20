<?php

namespace Fixzy\Kriptobot\Agent\Tools\MarketAnalysis;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Database\Database;

class AnalyzeVolatilityTool implements ToolInterface
{
    public function getName(): string
    {
        return 'analyze_volatility';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Analyze volatility for a cryptocurrency pair. Calculates ATR (Average True Range), standard deviation, and volatility levels. Useful for setting appropriate trailing stops and DCA size.'
            : 'Analyze volatility for a cryptocurrency pair. Calculates ATR (Average True Range), standard deviation, and volatility levels. Useful for setting appropriate trailing stops and DCA sizing.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair'      => ['type' => 'string', 'description' => 'Trading pair, e.g. BTC/USDT'],
                'timeframe' => ['type' => 'string', 'enum' => ['15m', '1h', '4h', '1d'], 'description' => 'Timeframe for volatility analysis'],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = strtoupper($params['pair'] ?? 'BTC/USDT');
        $timeframe = $params['timeframe'] ?? '1h';

        try {
            $conn = Database::getConnection();
            $ingester = new BinanceDataIngester($conn);

            $candles = $ingester->fetchCandles(
                $pair,
                strtotime('-72 hours') * 1000,
                time() * 1000,
                $timeframe
            );

            if (count($candles) < 14) {
                return ToolResult::fail('Insufficient candle data for volatility analysis');
            }

            $highs = array_column($candles, 2);
            $lows  = array_column($candles, 3);
            $closes = array_column($candles, 4);

            $atr = $this->calculateAtr($highs, $lows, $closes, 14);
            $currentPrice = end($closes);
            $atrPercent = $currentPrice > 0 ? ($atr / $currentPrice) * 100 : 0;

            $returns = [];
            for ($i = 1, $n = count($closes); $i < $n; $i++) {
                $returns[] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
            }

            $meanReturn = count($returns) > 0 ? array_sum($returns) / count($returns) : 0;
            $variance = 0;
            foreach ($returns as $r) {
                $variance += pow($r - $meanReturn, 2);
            }
            $stdDev = count($returns) > 1 ? sqrt($variance / (count($returns) - 1)) : 0;
            $periodsPerDay = match ($timeframe) {
                '15m' => 96, '1h' => 24, '4h' => 6, '1d' => 1,
                default => 24,
            };
            $annualizedVol = $stdDev * sqrt(365 * $periodsPerDay);

            $volatilityLevel = 'low';
            if ($atrPercent > 5) $volatilityLevel = 'very_high';
            elseif ($atrPercent > 3) $volatilityLevel = 'high';
            elseif ($atrPercent > 1.5) $volatilityLevel = 'medium';

            $recommendations = $this->getVolatilityRecommendations($volatilityLevel);

            return ToolResult::ok([
                'pair'               => $pair,
                'timeframe'          => $timeframe,
                'atr'                => round($atr, 8),
                'atr_percent'        => round($atrPercent, 3),
                'stddev_returns'     => round($stdDev, 6),
                'annualized_vol'     => round($annualizedVol * 100, 2) . '%',
                'volatility_level'   => $volatilityLevel,
                'current_price'      => $currentPrice,
                'recommendations'    => $recommendations,
                'timestamp'          => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Volatility analysis failed: ' . $e->getMessage());
        }
    }

    private function calculateAtr(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $trValues = [];
        for ($i = 1, $n = count($closes); $i < $n; $i++) {
            $tr = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
            $trValues[] = $tr;
        }

        $recentTr = array_slice($trValues, -$period);
        return count($recentTr) > 0 ? array_sum($recentTr) / count($recentTr) : 0;
    }

    private function getVolatilityRecommendations(string $level): array
    {
        return match ($level) {
            'very_high' => [
                'dca_steps'        => 'Increase to 5-6 to handle large swings',
                'trailing'         => 'Enable both trailing buy and trailing TP (0.5-0.8% deviation)',
                'cut_loss'         => 'Widen cut loss to 25-30% to avoid whipsaws',
                'bollinger_stddev' => 'Use 2.5 stddev for Bollinger bands to reduce false signals',
            ],
            'high' => [
                'dca_steps'        => 'Use 4-5 DCA steps',
                'trailing'         => 'Enable trailing TP with 0.5% deviation',
                'cut_loss'         => 'Set cut loss at 20%',
                'bollinger_stddev' => 'Use 2.0 stddev (standard)',
            ],
            'medium' => [
                'dca_steps'        => 'Use 3-4 DCA steps',
                'trailing'         => 'Optional trailing TP at 0.3%',
                'cut_loss'         => 'Set cut loss at 15%',
            ],
            'low' => [
                'dca_steps'        => 'Use 2-3 DCA steps',
                'trailing'         => 'Optional trailing at 0.2%',
                'cut_loss'         => 'Set cut loss at 10%',
                'note'             => 'Low volatility pairs may benefit from start_asap with tight TP',
            ],
            default => [],
        };
    }
}
