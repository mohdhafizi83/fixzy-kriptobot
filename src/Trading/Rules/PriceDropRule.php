<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class PriceDropRule - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class PriceDropRule implements RuleInterface
{
    private float $averageEntryPrice;
    private float $currentPrice;
    private float $dropPercentageRequired;
    private array $lastContext = [];

    /**
     * __construct.
     * @param float $averageEntryPrice
     * @param float $currentPrice
     * @param float $dropPercentageRequired
     */
    public function __construct(float $averageEntryPrice, float $currentPrice, float $dropPercentageRequired)
    {
        $this->averageEntryPrice = $averageEntryPrice;
        $this->currentPrice = $currentPrice;
        $this->dropPercentageRequired = $dropPercentageRequired;
    }

    /**
     * Evaluate.
     * @return bool
     */
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

    /**
     * Get Context.
     * @return array
     */
    public function getContext(): array
    {
        return $this->lastContext;
    }
}
