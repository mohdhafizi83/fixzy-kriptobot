<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class LogicalAnd implements RuleInterface
{
    /** @var RuleInterface[] */
    private array $rules;
    private array $lastContext = [];

    public function __construct(RuleInterface ...$rules)
    {
        $this->rules = $rules;
    }

    public function evaluate(): bool
    {
        $subResults = [];
        $overall = true;

        foreach ($this->rules as $index => $rule) {
            $passed = $rule->evaluate();
            $ctx = $rule->getContext();

            $subResults[] = [
                'index'   => $index,
                'type'    => $ctx['type'] ?? get_class($rule),
                'passed'  => $passed,
                'context' => $ctx,
            ];

            if (!$passed) {
                $overall = false;
            }
        }

        $this->lastContext = [
            'type'       => 'logical_and',
            'condition'  => 'ALL',
            'passed'     => $overall,
            'indicators' => ['rules' => $subResults],
        ];

        return $overall;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }

    /**
     * Get the list of rules for external iteration.
     */
    public function getRules(): array
    {
        return $this->rules;
    }
}
