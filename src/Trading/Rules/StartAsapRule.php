<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class StartAsapRule implements RuleInterface
{
    private array $lastContext = [];

    public function evaluate(): bool
    {
        $this->lastContext = ['type' => 'start_asap', 'condition' => 'immediate', 'passed' => true, 'indicators' => []];
        return true;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
