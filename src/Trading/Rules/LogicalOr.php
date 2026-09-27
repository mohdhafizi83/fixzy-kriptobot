<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class LogicalOr - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class LogicalOr implements RuleInterface
{
    /** @var RuleInterface[] */
    private array $rules;
    private array $lastContext = [];

    /**
     * __construct.
     * @param RuleInterface... $rules
     */
    public function __construct(RuleInterface ...$rules)
    {
        $this->rules = $rules;
    }

    /**
     * Evaluate.
     * @return bool
     */
    public function evaluate(): bool
    {
        $subResults = [];
        $overall = false;

        foreach ($this->rules as $index => $rule) {
            $passed = $rule->evaluate();
            $ctx = $rule->getContext();

            $subResults[] = [
                'index'   => $index,
                'type'    => $ctx['type'] ?? get_class($rule),
                'passed'  => $passed,
                'context' => $ctx,
            ];

            if ($passed) {
                $overall = true;
            }
        }

        $this->lastContext = [
            'type'       => 'logical_or',
            'condition'  => 'ANY',
            'passed'     => $overall,
            'indicators' => ['rules' => $subResults],
        ];

        return $overall;
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
