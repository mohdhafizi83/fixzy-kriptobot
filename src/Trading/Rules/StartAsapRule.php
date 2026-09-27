<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class StartAsapRule - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class StartAsapRule implements RuleInterface
{
    private array $lastContext = [];

    /**
     * Evaluate.
     * @return bool
     */
    public function evaluate(): bool
    {
        $this->lastContext = ['type' => 'start_asap', 'condition' => 'immediate', 'passed' => true, 'indicators' => []];
        return true;
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
