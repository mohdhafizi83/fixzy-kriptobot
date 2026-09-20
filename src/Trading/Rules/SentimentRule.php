<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class SentimentRule implements RuleInterface
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
        if (empty($this->actualSignal)) {
            $this->lastContext = ['type' => 'sentiment', 'condition' => $this->expectedSignal, 'passed' => false, 'indicators' => ['expected' => $this->expectedSignal, 'actual' => 'EMPTY']];
            return false;
        }

        if ($this->expectedSignal === $this->actualSignal) {
            $this->lastContext = ['type' => 'sentiment', 'condition' => $this->expectedSignal, 'passed' => true, 'indicators' => ['expected' => $this->expectedSignal, 'actual' => $this->actualSignal]];
            return true;
        }

        $hierarchy = [
            'BULLISH'         => ['BULLISH', 'VERY_BULLISH'],
            'VERY_BULLISH'    => ['VERY_BULLISH'],
            'NEUTRAL_BULLISH' => ['NEUTRAL_BULLISH', 'BULLISH', 'VERY_BULLISH'],
            'BEARISH'         => ['BEARISH', 'VERY_BEARISH', 'BEARISH_CRASH'],
            'VERY_BEARISH'    => ['VERY_BEARISH', 'BEARISH_CRASH'],
            'BEARISH_CRASH'   => ['BEARISH_CRASH'],
            'NEUTRAL_BEARISH' => ['NEUTRAL_BEARISH', 'BEARISH', 'VERY_BEARISH', 'BEARISH_CRASH'],
            'FEAR'            => ['FEAR', 'FEAR_EXTREME'],
            'FEAR_EXTREME'    => ['FEAR_EXTREME'],
            'GREED'           => ['GREED', 'GREED_EXTREME'],
            'GREED_EXTREME'   => ['GREED_EXTREME'],
        ];

        $passed = false;
        if (isset($hierarchy[$this->expectedSignal])) {
            $passed = in_array($this->actualSignal, $hierarchy[$this->expectedSignal]);
        }

        $this->lastContext = [
            'type'       => 'sentiment',
            'condition'  => $this->expectedSignal,
            'passed'     => $passed,
            'indicators' => ['expected' => $this->expectedSignal, 'actual' => $this->actualSignal],
        ];

        return $passed;
    }

    public function getContext(): array
    {
        return $this->lastContext;
    }
}
