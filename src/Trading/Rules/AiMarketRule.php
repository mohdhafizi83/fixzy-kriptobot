<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * AiMarketRule — matches a pre-computed AI market-analysis signal (BUY/SELL/EMPTY)
 * against the expected value of an `ai_market` condition.
 *
 * The AI signal itself is produced by \Fixzy\Kriptobot\Intelligence\AiAnalyzer::analyzeMarket()
 * and cached by the daemon per timeframe (the rule itself performs no API calls).
 *
 * Semantics:
 *  - expected BUY  passes when the AI signal is BUY
 *  - expected SELL passes when the AI signal is SELL
 *  - EMPTY signal never passes (condition not met → bot waits)
 */
class AiMarketRule implements RuleInterface
{
    private string $expectedSignal;
    private string $actualSignal;
    private array $lastContext = [];

    public function __construct(string $expectedSignal, string $actualSignal)
    {
        $this->expectedSignal = strtoupper(trim($expectedSignal));
        $this->actualSignal   = strtoupper(trim($actualSignal));
    }

    public function evaluate(): bool
    {
        $passed = $this->actualSignal !== '' && $this->actualSignal === $this->expectedSignal;

        $this->lastContext = [
            'type'       => 'ai_market',
            'condition'  => $this->expectedSignal,
            'passed'     => $passed,
            'indicators' => [
                'expected' => $this->expectedSignal,
                'actual'   => $this->actualSignal !== '' ? $this->actualSignal : 'EMPTY',
            ],
        ];

        return $passed;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
