<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class LogicalOr implements RuleInterface
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

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
