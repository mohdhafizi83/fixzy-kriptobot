<?php

namespace Fixzy\Kriptobot\Agent;

class StrategyRecommender
{
    /**
     * Recommend entry strategies based on market conditions.
     */
    public function recommend(array $marketAnalysis, string $riskProfile, string $lang = 'en'): array
    {
        $trend = $marketAnalysis['trend'] ?? 'neutral';
        $volatility = $marketAnalysis['volatility_level'] ?? 'medium';
        $rsi = $marketAnalysis['rsi'] ?? 50;

        $recommendations = [];

        // Trend-based recommendations
        if ($trend === 'bullish') {
            $recommendations[] = [
                'strategy'    => 'rsi_dip_buying',
                'conditions'  => [
                    ['type' => 'rsi', 'value' => '< 30', 'timeframe' => '1h', 'period' => 14],
                ],
                'reason_en'   => 'In a bullish trend, buy RSI dips below 30 for optimal entries on pullbacks.',
                'reason_ms'   => 'In a bullish trend, buy RSI dips below 30 for optimal entries on pullbacks.',
                'priority'    => 1,
            ];
        } elseif ($trend === 'bearish') {
            $recommendations[] = [
                'strategy'    => 'deep_oversold_only',
                'conditions'  => [
                    ['type' => 'rsi', 'value' => '< 20', 'timeframe' => '1h', 'period' => 14],
                    ['type' => 'bollinger', 'value' => 'below_lower', 'timeframe' => '1h', 'period' => 20, 'stddev' => 2.0],
                ],
                'reason_en'   => 'In a bearish trend, only enter on extreme oversold conditions with Bollinger band confirmation.',
                'reason_ms'   => 'In a bearish trend, only enter on extreme oversold conditions with Bollinger band confirmation.',
                'priority'    => 1,
            ];
        } else {
            $recommendations[] = [
                'strategy'    => 'mean_reversion',
                'conditions'  => [
                    ['type' => 'bollinger', 'value' => 'below_lower', 'timeframe' => '1h', 'period' => 20, 'stddev' => 2.0],
                    ['type' => 'qfl', 'value' => 'original', 'timeframe' => '1h'],
                ],
                'reason_en'   => 'In a sideways market, use Bollinger bands and QFL base detection for mean reversion entries.',
                'reason_ms'   => 'In a sideways market, use Bollinger bands and QFL base detection for mean reversion entries.',
                'priority'    => 1,
            ];
        }

        // Volatility-based adjustments
        if ($volatility === 'high') {
            $recommendations[] = [
                'strategy'    => 'wider_bollinger',
                'conditions'  => [
                    ['type' => 'bollinger', 'value' => 'below_lower', 'timeframe' => '1h', 'period' => 20, 'stddev' => 2.5],
                ],
                'reason_en'   => 'High volatility detected. Use wider Bollinger bands (2.5 stddev) to avoid false signals.',
                'reason_ms'   => 'High volatility detected. Use wider Bollinger bands (2.5 stddev) to avoid false signals.',
                'priority'    => 2,
            ];
            $recommendations[] = [
                'strategy'    => 'enable_trailing',
                'adjustment'  => ['trailing_buy_enabled' => true, 'trailing_buy_deviation' => 0.5],
                'reason_en'   => 'Enable trailing buy to wait for price stabilization before entry in volatile conditions.',
                'reason_ms'   => 'Enable trailing buy to wait for price stabilization before entry in volatile conditions.',
                'priority'    => 2,
            ];
        }

        // Risk profile specific adjustments
        if ($riskProfile === 'conservative') {
            $recommendations[] = [
                'strategy'    => 'dual_confirmation',
                'adjustment'  => ['require_multiple_signals' => true],
                'reason_en'   => 'For conservative risk, require at least 2 technical signals to agree before entry.',
                'reason_ms'   => 'For conservative risk, require at least 2 technical signals to agree before entry.',
                'priority'    => 3,
            ];
        }

        // Sort by priority
        usort($recommendations, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $recommendations;
    }

    /**
     * Select optimal pair strategy based on user objective.
     */
    public function selectPairStrategy(string $objective, array $marketData = []): string
    {
        $objective = strtolower($objective);

        if (strpos($objective, 'diversif') !== false || strpos($objective, 'diverse') !== false) {
            return 'top_10_volume';
        }

        if (strpos($objective, 'volatile') !== false || strpos($objective, 'aggressive') !== false) {
            return 'top_10_volatility';
        }

        if (strpos($objective, 'single') !== false || strpos($objective, 'one') !== false || strpos($objective, 'specific') !== false) {
            return 'single';
        }

        if (strpos($objective, 'top') !== false || strpos($objective, 'market') !== false || strpos($objective, 'main') !== false) {
            return 'top_50_global';
        }

        return 'custom_list';
    }
}
