<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class CooldownRule - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class CooldownRule implements RuleInterface
{
    private int $lastTradeTimestamp;
    private int $cooldownSeconds;
    private array $lastContext = [];

    /**
     * __construct.
     * @param int $lastTradeTimestamp
     * @param int $cooldownSeconds
     */
    public function __construct(int $lastTradeTimestamp, int $cooldownSeconds)
    {
        $this->lastTradeTimestamp = $lastTradeTimestamp;
        $this->cooldownSeconds = $cooldownSeconds;
    }

    /**
     * Evaluate.
     * @return bool
     */
    public function evaluate(): bool
    {
        if ($this->lastTradeTimestamp === 0) {
            $this->lastContext = ['type' => 'cooldown', 'condition' => "{$this->cooldownSeconds}s", 'passed' => true, 'indicators' => ['time_passed' => null, 'reason' => 'first_trade']];
            return true;
        }

        $timePassed = time() - $this->lastTradeTimestamp;
        $passed = $timePassed >= $this->cooldownSeconds;

        $this->lastContext = [
            'type'       => 'cooldown',
            'condition'  => "{$this->cooldownSeconds}s",
            'passed'     => $passed,
            'indicators' => [
                'time_passed_seconds'   => $timePassed,
                'cooldown_seconds'      => $this->cooldownSeconds,
                'last_trade_timestamp'  => $this->lastTradeTimestamp,
                'remaining_seconds'     => $passed ? 0 : ($this->cooldownSeconds - $timePassed),
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
