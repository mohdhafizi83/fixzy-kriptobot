<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * RsiRule — Relative Strength Index (Wilder's Smoothing).
 *
 * Supports standard comparisons (< 30, > 70, etc.) and crossing detection
 * (crossing_up_30, crossing_down_70) like 3Commas.
 *
 * @package Fixzy\Kriptobot\Trading\Rules
 */
class RsiRule implements RuleInterface
{
    private string $rawCondition;
    private array $candles;
    private int $period;
    private array $lastContext = [];

    /**
     * @param string $rawCondition Format: '< 30', '> 70', 'crossing_up_30', etc.
     * @param array  $candles      CCXT OHLCV data
     * @param int    $period       RSI period (default: 14)
     */
    public function __construct(string $rawCondition, array $candles, int $period = 14)
    {
        $this->rawCondition = trim($rawCondition);
        $this->candles      = $candles;
        $this->period       = $period;
    }

    public function evaluate(): bool
    {
        $rsi = $this->calculateWildersRsi();

        if ($rsi === null) {
            $this->lastContext = [
                'type'       => 'rsi',
                'condition'  => $this->rawCondition,
                'passed'     => false,
                'indicators' => ['rsi' => null, 'period' => $this->period, 'reason' => 'insufficient_data'],
            ];
            return false;
        }

        $prevRsi = null;
        if (preg_match('/^crossing_(up|down)_(\d+)$/', $this->rawCondition, $crossMatch)) {
            $direction = $crossMatch[1];
            $threshold = (float) $crossMatch[2];

            $prevRsi = $this->calculateWildersRsi(1);
            if ($prevRsi === null) {
                $this->lastContext = [
                    'type'       => 'rsi',
                    'condition'  => $this->rawCondition,
                    'passed'     => false,
                    'indicators' => ['rsi' => $rsi, 'prev_rsi' => null, 'period' => $this->period, 'reason' => 'prev_rsi_null'],
                ];
                return false;
            }

            $passed = false;
            if ($direction === 'up') {
                $passed = $prevRsi < $threshold && $rsi >= $threshold;
            } else {
                $passed = $prevRsi > $threshold && $rsi <= $threshold;
            }

            $this->lastContext = [
                'type'       => 'rsi',
                'condition'  => $this->rawCondition,
                'passed'     => $passed,
                'indicators' => [
                    'rsi'       => $rsi,
                    'prev_rsi'  => $prevRsi,
                    'period'    => $this->period,
                    'threshold' => $threshold,
                    'direction' => $direction,
                ],
            ];
            return $passed;
        }

        if (preg_match('/^([<>=]+)\s*([\d\.]+)$/', $this->rawCondition, $matches)) {
            $operator    = $matches[1];
            $targetValue = (float) $matches[2];

            $passed = match ($operator) {
                '<'  => $rsi < $targetValue,
                '<=' => $rsi <= $targetValue,
                '>'  => $rsi > $targetValue,
                '>=' => $rsi >= $targetValue,
                '=', '==' => $rsi == $targetValue,
                default => false,
            };

            $this->lastContext = [
                'type'       => 'rsi',
                'condition'  => $this->rawCondition,
                'passed'     => $passed,
                'indicators' => [
                    'rsi'       => $rsi,
                    'period'    => $this->period,
                    'operator'  => $operator,
                    'target'    => $targetValue,
                ],
            ];
            return $passed;
        }

        $this->lastContext = [
            'type'       => 'rsi',
            'condition'  => $this->rawCondition,
            'passed'     => false,
            'indicators' => ['rsi' => $rsi, 'period' => $this->period, 'reason' => 'unknown_format'],
        ];
        return false;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }

    /**
     * Calculate RSI using Wilder's Smoothing.
     *
     * @param int $skipLast Number of last candles to skip (for crossing detection)
     *
     * @return float|null RSI value or null if data is insufficient
     */
    private function calculateWildersRsi(int $skipLast = 0): ?float
    {
        $closes = array_column($this->candles, 4);

        if ($skipLast > 0) {
            $closes = array_slice($closes, 0, -$skipLast);
        }

        if (count($closes) <= $this->period) {
            return null;
        }

        $gains  = 0;
        $losses = 0;

        for ($i = 1; $i <= $this->period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            if ($change > 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        $avgGain = $gains / $this->period;
        $avgLoss = $losses / $this->period;

        for ($i = $this->period + 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain   = $change > 0 ? $change : 0;
            $loss   = $change < 0 ? abs($change) : 0;

            $avgGain = (($avgGain * ($this->period - 1)) + $gain) / $this->period;
            $avgLoss = (($avgLoss * ($this->period - 1)) + $loss) / $this->period;
        }

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs  = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return round($rsi, 2);
    }
}
