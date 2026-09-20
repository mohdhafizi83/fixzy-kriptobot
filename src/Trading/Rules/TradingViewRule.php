<?php

namespace Fixzy\Kriptobot\Trading\Rules;

class TradingViewRule implements RuleInterface
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
            $this->lastContext = ['type' => 'tv_webhook', 'condition' => $this->expectedSignal, 'passed' => false, 'indicators' => ['expected' => $this->expectedSignal, 'actual' => 'EMPTY']];
            return false;
        }

        if ($this->expectedSignal === $this->actualSignal) {
            $this->lastContext = ['type' => 'tv_webhook', 'condition' => $this->expectedSignal, 'passed' => true, 'indicators' => ['expected' => $this->expectedSignal, 'actual' => $this->actualSignal]];
            return true;
        }

        $hierarchy = [
            'BUY'         => ['BUY', 'STRONG_BUY', 'LONG', 'ENTER_LONG'],
            'STRONG_BUY'  => ['STRONG_BUY'],
            'LONG'        => ['LONG', 'ENTER_LONG'],
            'ENTER_LONG'  => ['ENTER_LONG'],
            'DCA_BUY'     => ['DCA_BUY'],
            'SELL'        => ['SELL', 'STRONG_SELL', 'SHORT', 'EXIT_LONG', 'PANIC_SELL'],
            'STRONG_SELL' => ['STRONG_SELL', 'PANIC_SELL'],
            'SHORT'       => ['SHORT', 'EXIT_LONG'],
            'EXIT_LONG'   => ['EXIT_LONG'],
            'PANIC_SELL'  => ['PANIC_SELL'],
        ];

        $passed = false;
        if (isset($hierarchy[$this->expectedSignal])) {
            $passed = in_array($this->actualSignal, $hierarchy[$this->expectedSignal]);
        }

        $this->lastContext = [
            'type'       => 'tv_webhook',
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
