<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * BollingerRule — Bollinger Bands (SMA ± StdDev) condition evaluation.
 *
 * Supports: below_lower, touch_lower, crossing_up_lower, above_upper, touch_upper,
 * crossing_down_upper, percent_b_*, crossing middle band, and legacy format fallback.
 *
 * @package Fixzy\Kriptobot\Trading\Rules
 */
class BollingerRule implements RuleInterface
{
    private string $rawCondition;
    private array $candles;
    private int $period;
    private float $stdDevMultiplier;
    private array $lastContext = [];

    /**
     * @param string $rawCondition     UI selection (e.g. 'below_lower', 'percent_b_lt_0.2')
     * @param array  $candles          CCXT OHLCV data
     * @param int    $period           SMA period (default: 20)
     * @param float  $stdDevMultiplier Standard deviation multiplier (default: 2.0)
     */
    public function __construct(string $rawCondition, array $candles, int $period = 20, float $stdDevMultiplier = 2.0)
    {
        $this->rawCondition     = trim(strtolower($rawCondition));
        $this->candles          = $candles;
        $this->period           = $period;
        $this->stdDevMultiplier = $stdDevMultiplier;
    }

    public function evaluate(): bool
    {
        $bb = $this->calculateBollingerBands();

        if ($bb === null) {
            $this->lastContext = [
                'type'       => 'bollinger',
                'condition'  => $this->rawCondition,
                'passed'     => false,
                'indicators' => ['reason' => 'insufficient_data'],
            ];
            return false;
        }

        $price    = $bb['current_price'];
        $percentB = $bb['percent_b'];
        $prevBb   = $this->calculateBollingerBands(1);

        $passed = match ($this->rawCondition) {
            'below_lower'         => $price < $bb['lower'],
            'touch_lower'         => abs($price - $bb['lower']) <= ($bb['lower'] * 0.001),
            'crossing_up_lower'   => $prevBb && $prevBb['current_price'] < $prevBb['lower'] && $price >= $bb['lower'],
            'below_middle'        => $price < $bb['middle'],
            'crossing_up_middle'  => $prevBb && $prevBb['current_price'] < $prevBb['middle'] && $price >= $bb['middle'],
            'percent_b_lt_0'      => $percentB < 0,
            'percent_b_lt_0.2'    => $percentB < 0.2,
            'percent_b_lt_0.5'    => $percentB < 0.5,
            'above_upper'         => $price > $bb['upper'],
            'touch_upper'         => abs($price - $bb['upper']) <= ($bb['upper'] * 0.001),
            'crossing_down_upper' => $prevBb && $prevBb['current_price'] > $prevBb['upper'] && $price <= $bb['upper'],
            'above_middle'        => $price > $bb['middle'],
            'crossing_down_middle' => $prevBb && $prevBb['current_price'] > $prevBb['middle'] && $price <= $bb['middle'],
            'percent_b_gt_1'      => $percentB > 1,
            'percent_b_gt_0.8'    => $percentB > 0.8,
            'percent_b_gt_0.5'    => $percentB > 0.5,
            default               => $this->evaluateLegacy($bb, $price),
        };

        $this->lastContext = [
            'type'       => 'bollinger',
            'condition'  => $this->rawCondition,
            'passed'     => $passed,
            'indicators' => [
                'price'    => $price,
                'upper'    => $bb['upper'],
                'middle'   => $bb['middle'],
                'lower'    => $bb['lower'],
                'percent_b' => $percentB,
                'period'   => $this->period,
                'stddev'   => $this->stdDevMultiplier,
            ],
        ];

        return $passed;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }

    private function evaluateLegacy(array $bb, float $price): bool
    {
        if (strpos($this->rawCondition, 'lower') !== false) {
            if (strpos($this->rawCondition, '<') !== false || strpos($this->rawCondition, 'below') !== false) {
                return $price < $bb['lower'];
            }
            if (strpos($this->rawCondition, '>') !== false || strpos($this->rawCondition, 'above') !== false) {
                return $price > $bb['lower'];
            }
        } elseif (strpos($this->rawCondition, 'upper') !== false) {
            if (strpos($this->rawCondition, '>') !== false || strpos($this->rawCondition, 'above') !== false) {
                return $price > $bb['upper'];
            }
            if (strpos($this->rawCondition, '<') !== false || strpos($this->rawCondition, 'below') !== false) {
                return $price < $bb['upper'];
            }
        } elseif (strpos($this->rawCondition, 'middle') !== false || strpos($this->rawCondition, 'sma') !== false) {
            if (strpos($this->rawCondition, '>') !== false || strpos($this->rawCondition, 'above') !== false) {
                return $price > $bb['middle'];
            }
            if (strpos($this->rawCondition, '<') !== false || strpos($this->rawCondition, 'below') !== false) {
                return $price < $bb['middle'];
            }
        }

        return false;
    }

    /**
     * Compute Bollinger Bands: SMA, Upper, Lower, %B.
     */
    private function calculateBollingerBands(int $skipLast = 0): ?array
    {
        $closes = array_column($this->candles, 4);

        if ($skipLast > 0) {
            $closes = array_slice($closes, 0, -$skipLast);
        }

        if (count($closes) < $this->period) {
            return null;
        }

        $recentCloses = array_slice($closes, -$this->period);
        $currentPrice = end($recentCloses);
        $sma          = array_sum($recentCloses) / $this->period;

        $varianceSum = 0.0;
        foreach ($recentCloses as $close) {
            $varianceSum += pow($close - $sma, 2);
        }
        $stdDev = sqrt($varianceSum / $this->period);

        $upperBand = $sma + ($this->stdDevMultiplier * $stdDev);
        $lowerBand = $sma - ($this->stdDevMultiplier * $stdDev);

        $bandWidth = $upperBand - $lowerBand;
        $percentB  = ($bandWidth > 0) ? ($currentPrice - $lowerBand) / $bandWidth : 0.5;

        return [
            'upper'         => round($upperBand, 4),
            'middle'        => round($sma, 4),
            'lower'         => round($lowerBand, 4),
            'current_price' => round($currentPrice, 4),
            'percent_b'     => round($percentB, 4),
        ];
    }
}
