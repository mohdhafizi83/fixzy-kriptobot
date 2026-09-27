<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class MinimumProfitGuardRule - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class MinimumProfitGuardRule implements RuleInterface
{
    private float $averageEntryPrice;
    private float $currentPrice;
    private float $minProfitPercentage;
    private bool $isEnabled;
    private int $entryTimestamp;
    private int $timeoutHours;
    private bool $isSmartModeActive;
    private array $lastContext = [];

    /**
     * __construct.
     * @param float $averageEntryPrice
     * @param float $currentPrice
     * @param float $minProfitPercentage
     * @param bool $isEnabled
     * @param int $entryTimestamp
     * @param int $timeoutHours
     * @param bool $isSmartModeActive (default: false)
     */
    public function __construct(
        float $averageEntryPrice,
        float $currentPrice,
        float $minProfitPercentage,
        bool $isEnabled,
        int $entryTimestamp,
        int $timeoutHours,
        bool $isSmartModeActive = false
    ) {
        $this->averageEntryPrice  = $averageEntryPrice;
        $this->currentPrice       = $currentPrice;
        $this->minProfitPercentage = $minProfitPercentage;
        $this->isEnabled          = $isEnabled;
        $this->entryTimestamp     = $entryTimestamp;
        $this->timeoutHours       = $timeoutHours;
        $this->isSmartModeActive  = $isSmartModeActive;
    }

    /**
     * Evaluate.
     * @return bool
     */
    public function evaluate(): bool
    {
        if ($this->averageEntryPrice <= 0) {
            $this->lastContext = ['type' => 'min_profit_guard', 'condition' => "{$this->minProfitPercentage}%", 'passed' => false, 'indicators' => ['reason' => 'no_entry_price']];
            return false;
        }

        if ($this->isSmartModeActive) {
            $passed = $this->checkProfitReached();
            $this->lastContext = ['type' => 'min_profit_guard', 'condition' => "{$this->minProfitPercentage}%", 'passed' => $passed, 'indicators' => ['smart_mode' => true, 'profit_pct' => $this->calcProfitPct()]];
            return $passed;
        }

        if (!$this->isEnabled) {
            $this->lastContext = ['type' => 'min_profit_guard', 'condition' => "{$this->minProfitPercentage}%", 'passed' => true, 'indicators' => ['disabled' => true]];
            return true;
        }

        $hoursPassed = (time() - $this->entryTimestamp) / 3600;
        if ($hoursPassed >= $this->timeoutHours) {
            $this->lastContext = ['type' => 'min_profit_guard', 'condition' => "{$this->minProfitPercentage}%", 'passed' => true, 'indicators' => ['timeout_reached' => true, 'hours_passed' => round($hoursPassed, 2), 'timeout_hours' => $this->timeoutHours]];
            return true;
        }

        $passed = $this->checkProfitReached();
        $this->lastContext = [
            'type'       => 'min_profit_guard',
            'condition'  => "{$this->minProfitPercentage}%",
            'passed'     => $passed,
            'indicators' => [
                'profit_pct'       => $this->calcProfitPct(),
                'min_profit_pct'   => $this->minProfitPercentage,
                'hours_passed'     => round($hoursPassed, 2),
                'timeout_hours'    => $this->timeoutHours,
                'entry_timestamp'  => $this->entryTimestamp,
            ],
        ];

        return $passed;
    }

    /**
     * Get Context.
     * @return array
     */
    public function getContext(): array
    {
        return $this->lastContext;
    }

    /**
     * Check Profit Reached.
     * @return bool
     */
    private function checkProfitReached(): bool
    {
        return $this->calcProfitPct() >= $this->minProfitPercentage;
    }

    /**
     * Calc Profit Pct.
     * @return float
     */
    private function calcProfitPct(): float
    {
        $priceDifference = $this->currentPrice - $this->averageEntryPrice;
        return round(($priceDifference / $this->averageEntryPrice) * 100, 4);
    }
}
