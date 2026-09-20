<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class TakeProfitRule implements RuleInterface
{
    private float $averageEntryPrice;
    private float $currentPrice;
    private float $targetProfitPercentage;
    private array $lastContext = [];

    public function __construct(float $averageEntryPrice, float $currentPrice, float $targetProfitPercentage)
    {
        $this->averageEntryPrice = $averageEntryPrice;
        $this->currentPrice = $currentPrice;
        $this->targetProfitPercentage = $targetProfitPercentage;
    }

    public function evaluate(): bool
    {
        if ($this->averageEntryPrice <= 0) {
            $this->lastContext = ['type' => 'take_profit', 'condition' => "{$this->targetProfitPercentage}%", 'passed' => false, 'indicators' => ['reason' => 'no_entry_price']];
            return false;
        }

        $priceDifference = $this->currentPrice - $this->averageEntryPrice;

        if ($priceDifference <= 0) {
            $this->lastContext = [
                'type'       => 'take_profit',
                'condition'  => "{$this->targetProfitPercentage}%",
                'passed'     => false,
                'indicators' => ['entry_price' => $this->averageEntryPrice, 'current_price' => $this->currentPrice, 'profit_percent' => 0],
            ];
            return false;
        }

        $profitPercentage = ($priceDifference / $this->averageEntryPrice) * 100;
        $passed = $profitPercentage >= $this->targetProfitPercentage;

        $this->lastContext = [
            'type'       => 'take_profit',
            'condition'  => "{$this->targetProfitPercentage}%",
            'passed'     => $passed,
            'indicators' => [
                'entry_price'    => $this->averageEntryPrice,
                'current_price'  => $this->currentPrice,
                'profit_percent' => round($profitPercentage, 4),
                'target_percent' => $this->targetProfitPercentage,
            ],
        ];

        return $passed;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
