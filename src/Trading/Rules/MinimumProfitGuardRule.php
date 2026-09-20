<?php

namespace Fixzy\Kriptobot\Trading\Rules;

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

    public function getContext(): array
    {
        return $this->lastContext;
    }

    private function checkProfitReached(): bool
    {
        return $this->calcProfitPct() >= $this->minProfitPercentage;
    }

    private function calcProfitPct(): float
    {
        $priceDifference = $this->currentPrice - $this->averageEntryPrice;
        return round(($priceDifference / $this->averageEntryPrice) * 100, 4);
    }
}
