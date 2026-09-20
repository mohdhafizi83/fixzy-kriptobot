<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class PriceDropRule implements RuleInterface
{
    private float $averageEntryPrice;
    private float $currentPrice;
    private float $dropPercentageRequired;
    private array $lastContext = [];

    public function __construct(float $averageEntryPrice, float $currentPrice, float $dropPercentageRequired)
    {
        $this->averageEntryPrice = $averageEntryPrice;
        $this->currentPrice = $currentPrice;
        $this->dropPercentageRequired = $dropPercentageRequired;
    }

    public function evaluate(): bool
    {
        if ($this->averageEntryPrice <= 0) {
            $this->lastContext = ['type' => 'price_drop', 'condition' => "drop_{$this->dropPercentageRequired}%", 'passed' => false, 'indicators' => ['reason' => 'no_entry_price']];
            return false;
        }

        $priceDifference = $this->averageEntryPrice - $this->currentPrice;

        if ($priceDifference <= 0) {
            $this->lastContext = ['type' => 'price_drop', 'condition' => "drop_{$this->dropPercentageRequired}%", 'passed' => false, 'indicators' => ['entry_price' => $this->averageEntryPrice, 'current_price' => $this->currentPrice, 'drop_percent' => 0]];
            return false;
        }

        $dropPercentage = ($priceDifference / $this->averageEntryPrice) * 100;
        $passed = $dropPercentage >= $this->dropPercentageRequired;

        $this->lastContext = [
            'type'       => 'price_drop',
            'condition'  => "drop_{$this->dropPercentageRequired}%",
            'passed'     => $passed,
            'indicators' => [
                'entry_price'    => $this->averageEntryPrice,
                'current_price'  => $this->currentPrice,
                'drop_percent'   => round($dropPercentage, 2),
                'required_percent' => $this->dropPercentageRequired,
            ],
        ];

        return $passed;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
