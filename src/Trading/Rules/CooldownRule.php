<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class CooldownRule implements RuleInterface
{
    private int $lastTradeTimestamp;
    private int $cooldownSeconds;
    private array $lastContext = [];

    public function __construct(int $lastTradeTimestamp, int $cooldownSeconds)
    {
        $this->lastTradeTimestamp = $lastTradeTimestamp;
        $this->cooldownSeconds = $cooldownSeconds;
    }

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

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
