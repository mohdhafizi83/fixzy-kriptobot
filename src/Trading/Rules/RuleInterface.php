<?php

namespace Fixzy\Kriptobot\Trading\Rules;

interface RuleInterface
{
    /**
     * Evaluate whether this rule is satisfied (True) or not (False).
     */
    public function evaluate(): bool;

    /**
     * Get the latest evaluation context for audit logging.
     * Must be called after evaluate().
     *
     * @return array ['type' => string, 'condition' => string, 'passed' => bool, 'indicators' => array]
     */
    public function getContext(): array;
}
