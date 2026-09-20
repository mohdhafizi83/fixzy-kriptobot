<?php

namespace Fixzy\Kriptobot\Trading;

use Fixzy\Kriptobot\Trading\Rules\RuleInterface;

class PartialSellEngine
{
    private array $steps = [];
    private bool $isEnabled;

    /**
     * @param bool $isEnabled UI toggle to enable the Staged Sell feature
     */
    public function __construct(bool $isEnabled = true)
    {
        $this->isEnabled = $isEnabled;
    }

    /**
     * Add a sell stage/condition to the engine
     * * @param string $name Stage name (e.g. 'TP_1', 'RSI_SELL')
     * @param RuleInterface $rule Condition logic block
     * @param bool $isExecuted Status from the database (has this stage already been sold before?)
     */
    public function addStep(string $name, RuleInterface $rule, bool $isExecuted = false): void
    {
        $this->steps[] = [
            'name'        => $name,
            'rule'        => $rule,
            'is_executed' => $isExecuted
        ];
    }

    /**
     * Evaluate all stages and return partial sell instructions
     * * @return array List of stages that passed, with the percentage of assets to sell
     */
    public function evaluateFractions(): array
    {
        // If this feature is disabled in the UI, return an empty array 
        // (the bot falls back to the regular Sell All logic)
        if (!$this->isEnabled || empty($this->steps)) {
            return [];
        }

        // Smart calculation: split into equal parts based on the number of steps
        // Example: 4 steps = 1/4 = 0.25 (25% of the ORIGINAL TOTAL ASSETS)
        $totalSteps = count($this->steps);
        $fractionPerStep = 1.0 / $totalSteps; 
        
        $triggersToExecute = [];

        foreach ($this->steps as $index => $step) {
            // Only evaluate if it has never been executed
            if (!$step['is_executed']) {
                if ($step['rule']->evaluate()) {
                    $triggersToExecute[] = [
                        'step_name' => $step['name'],
                        'fraction'  => $fractionPerStep
                    ];
                }
            }
        }

        return $triggersToExecute;
    }
}