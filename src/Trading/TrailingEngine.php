<?php

namespace Fixzy\Kriptobot\Trading;

/**
 * TrailingEngine — Price tracking engine for Trailing Buy and Trailing Sell strategies.
 *
 * Pure functions: no side effects, no API calls, no internal state.
 * Each call receives the current price, a watermark, and a deviation percentage, and returns
 * an action decision (WAIT, BUY, or SELL) along with the updated watermark.
 *
 * Used by bot_daemon.php for:
 *   - Trailing Buy (entry on a bounce from the lowest price)
 *   - Trailing DCA (safety order on a bounce)
 *   - Trailing Take Profit (exit on a drop from the peak)
 *   - Trailing Stop Loss (exit on a drop from the peak after TP)
 *
 * @package Fixzy\Kriptobot\Trading
 */
class TrailingEngine
{
    /**
     * Evaluate Trailing Buy — wait for the price to fall to its low, then bounce back up.
     *
     * Logic:
     *   1. If the watermark is not set yet (≤ 0) or the price is lower → record the new low, WAIT
     *   2. If the price bounces above watermark + deviation% → BUY
     *   3. Otherwise → WAIT (price is between the watermark and the trigger)
     *
     * @param float $currentPrice      Current market price
     * @param float $lowWatermark      Lowest recorded price so far (0 if not set yet)
     * @param float $trailingDeviation Rise percentage from the low point to trigger (e.g. 0.5 for 0.5%)
     *
     * @return array{action: 'WAIT'|'BUY', new_low: float}
     *
     * @example
     * // Current price $100, watermark $95, deviation 1%
     * $result = $engine->evaluateTrailingBuy(100, 95, 1.0);
     * // If 100 >= 95 * 1.01 (= 95.95) → BUY
     * // If 100 < 95 → WAIT, new_low = 100
     * // If 95 < 100 < 95.95 → WAIT, new_low = 95
     */
    public function evaluateTrailingBuy(float $currentPrice, float $lowWatermark, float $trailingDeviation): array
    {
        if ($lowWatermark <= 0 || $currentPrice < $lowWatermark) {
            return [
                'action'  => 'WAIT',
                'new_low' => $currentPrice,
            ];
        }

        $triggerPrice = $lowWatermark * (1 + ($trailingDeviation / 100));

        if ($currentPrice >= $triggerPrice) {
            return [
                'action'  => 'BUY',
                'new_low' => $lowWatermark,
            ];
        }

        return [
            'action'  => 'WAIT',
            'new_low' => $lowWatermark,
        ];
    }

    /**
     * Evaluate Trailing Take Profit / Stop Loss — track the price to its peak, sell when it drops.
     *
     * Logic:
     *   1. If the price is higher than the watermark → record the new peak, WAIT
     *   2. If the price falls beyond watermark - deviation% → SELL
     *   3. Otherwise → WAIT (price is between the trigger and the watermark)
     *
     * @param float $currentPrice      Current market price
     * @param float $highWatermark     Highest recorded price so far
     * @param float $trailingDeviation Drop percentage from the peak to trigger (e.g. 0.2 for 0.2%)
     *
     * @return array{action: 'WAIT'|'SELL', new_high: float}
     *
     * @example
     * // Current price $105, watermark $100, deviation 0.5%
     * $result = $engine->evaluateTrailingSell(105, 100, 0.5);
     * // If 105 > 100 → WAIT, new_high = 105
     * // If 99 <= 105 * 0.995 (= 104.475) → SELL
     * // If 104.475 < 105 < 105 → WAIT, new_high = 105 (impossible, just an illustrative example)
     */
    public function evaluateTrailingSell(float $currentPrice, float $highWatermark, float $trailingDeviation): array
    {
        if ($currentPrice > $highWatermark) {
            return [
                'action'   => 'WAIT',
                'new_high' => $currentPrice,
            ];
        }

        $triggerPrice = $highWatermark * (1 - ($trailingDeviation / 100));

        if ($currentPrice <= $triggerPrice) {
            return [
                'action'   => 'SELL',
                'new_high' => $highWatermark,
            ];
        }

        return [
            'action'   => 'WAIT',
            'new_high' => $highWatermark,
        ];
    }
}
