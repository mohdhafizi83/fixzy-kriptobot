<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * QflRule — Quickfingers Luc (QFL) Fractal Base Detection Strategy.
 *
 * @package Fixzy\Kriptobot\Trading\Rules
 */
class QflRule implements RuleInterface
{
    private string $rawCondition;
    private array $candles;
    private float $dropPercentage;
    private string $mode;
    private array $lastContext = [];

    public function __construct(string $rawCondition, array $candles)
    {
        $this->rawCondition = trim(strtolower($rawCondition));
        $this->candles      = $candles;
        $this->mode         = 'buy';
        $this->dropPercentage = 3.0;

        if ($this->rawCondition === 'original') {
            $this->dropPercentage = 3.0;
        } elseif ($this->rawCondition === 'day_trade') {
            $this->dropPercentage = 5.0;
        } elseif ($this->rawCondition === 'conservative') {
            $this->dropPercentage = 7.0;
        } elseif (preg_match('/^below_base_(\d+)$/', $this->rawCondition, $m)) {
            $this->dropPercentage = (float) $m[1];
        } elseif (in_array($this->rawCondition, ['above_base', 'above_base_3', 'above_base_5', 'above_resistance', 'approaching_resistance'])) {
            $this->mode = 'sell';
            if ($this->rawCondition === 'above_base_3') {
                $this->dropPercentage = 3.0;
            } elseif ($this->rawCondition === 'above_base_5') {
                $this->dropPercentage = 5.0;
            } else {
                $this->dropPercentage = 0.0;
            }
        }
    }

    public function evaluate(): bool
    {
        if ($this->mode === 'sell') {
            return $this->evaluateSell();
        }

        return $this->evaluateBuy();
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }

    private function evaluateBuy(): bool
    {
        $basePrice = $this->findLatestBase();

        if ($basePrice === null || empty($this->candles)) {
            $this->lastContext = [
                'type'       => 'qfl',
                'condition'  => $this->rawCondition,
                'passed'     => false,
                'indicators' => ['base_price' => null, 'reason' => 'no_base_found'],
            ];
            return false;
        }

        $currentPrice = end($this->candles)[4];
        $targetBuyPrice = $basePrice * (1 - ($this->dropPercentage / 100));
        $passed = $currentPrice <= $targetBuyPrice;

        $this->lastContext = [
            'type'       => 'qfl',
            'condition'  => $this->rawCondition,
            'passed'     => $passed,
            'indicators' => [
                'base_price'       => $basePrice,
                'current_price'    => $currentPrice,
                'target_buy_price' => $targetBuyPrice,
                'drop_percentage'  => $this->dropPercentage,
                'mode'             => $this->mode,
            ],
        ];

        return $passed;
    }

    private function evaluateSell(): bool
    {
        if (empty($this->candles)) {
            $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => false, 'indicators' => ['reason' => 'no_candles']];
            return false;
        }

        $currentPrice = end($this->candles)[4];

        if ($this->rawCondition === 'above_resistance') {
            $resistance = $this->findLatestResistance();
            if ($resistance === null) {
                $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => false, 'indicators' => ['current_price' => $currentPrice, 'resistance' => null]];
                return false;
            }
            $passed = $currentPrice > $resistance;
            $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => $passed, 'indicators' => ['current_price' => $currentPrice, 'resistance' => $resistance]];
            return $passed;
        }

        if ($this->rawCondition === 'approaching_resistance') {
            $resistance = $this->findLatestResistance();
            $base       = $this->findLatestBase();
            if ($resistance === null || $base === null) {
                $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => false, 'indicators' => ['current_price' => $currentPrice]];
                return false;
            }
            $threshold = $base + (($resistance - $base) * 0.90);
            $passed = $currentPrice >= $threshold;
            $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => $passed, 'indicators' => ['current_price' => $currentPrice, 'base' => $base, 'resistance' => $resistance, 'threshold' => $threshold]];
            return $passed;
        }

        $basePrice = $this->findLatestBase();
        if ($basePrice === null) {
            $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => false, 'indicators' => ['current_price' => $currentPrice, 'base_price' => null]];
            return false;
        }

        $targetSellPrice = $basePrice * (1 + ($this->dropPercentage / 100));
        $passed = $currentPrice >= $targetSellPrice;
        $this->lastContext = ['type' => 'qfl', 'condition' => $this->rawCondition, 'passed' => $passed, 'indicators' => ['current_price' => $currentPrice, 'base_price' => $basePrice, 'target_sell_price' => $targetSellPrice]];
        return $passed;
    }

    private function findLatestBase(): ?float
    {
        $totalCandles = count($this->candles);
        if ($totalCandles < 10) {
            return null;
        }

        for ($i = $totalCandles - 3; $i >= 2; $i--) {
            $low    = $this->candles[$i][3];
            $left1  = $this->candles[$i - 1][3];
            $left2  = $this->candles[$i - 2][3];
            $right1 = $this->candles[$i + 1][3];
            $right2 = $this->candles[$i + 2][3];

            if ($low < $left1 && $low < $left2 && $low < $right1 && $low < $right2) {
                $subsequentHigh = $this->candles[$i + 1][2];
                if ($subsequentHigh > $low) {
                    return $low;
                }
            }
        }

        return null;
    }

    private function findLatestResistance(): ?float
    {
        $totalCandles = count($this->candles);
        if ($totalCandles < 10) {
            return null;
        }

        for ($i = $totalCandles - 3; $i >= 2; $i--) {
            $high   = $this->candles[$i][2];
            $left1  = $this->candles[$i - 1][2];
            $left2  = $this->candles[$i - 2][2];
            $right1 = $this->candles[$i + 1][2];
            $right2 = $this->candles[$i + 2][2];

            if ($high > $left1 && $high > $left2 && $high > $right1 && $high > $right2) {
                return $high;
            }
        }

        return null;
    }
}
