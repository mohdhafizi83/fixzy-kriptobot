<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class RuleInterface - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
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
