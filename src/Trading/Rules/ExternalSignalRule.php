<?php

namespace Fixzy\Kriptobot\Trading\Rules;

/**
 * Class ExternalSignalRule - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ExternalSignalRule implements RuleInterface
{
    private string $expectedSignal;
    private string $actualSignal;
    private array $lastContext = [];

    /**
     * __construct.
     * @param string $expectedSignal
     * @param string $actualSignal
     */
    public function __construct(string $expectedSignal, string $actualSignal)
    {
        $this->expectedSignal = strtoupper(trim($expectedSignal));
        $this->actualSignal   = strtoupper(trim($actualSignal));
    }

    /**
     * Evaluate.
     * @return bool
     */
    public function evaluate(): bool
    {
        if (empty($this->expectedSignal) || empty($this->actualSignal)) {
            $this->lastContext = ['type' => 'external_signal', 'condition' => $this->expectedSignal, 'passed' => false, 'indicators' => ['expected' => $this->expectedSignal, 'actual' => $this->actualSignal ?: 'EMPTY']];
            return false;
        }

        if ($this->expectedSignal === $this->actualSignal) {
            $this->lastContext = ['type' => 'external_signal', 'condition' => $this->expectedSignal, 'passed' => true, 'indicators' => ['expected' => $this->expectedSignal, 'actual' => $this->actualSignal]];
            return true;
        }

        $hierarchy = [
            'BUY'         => ['BUY', 'STRONG_BUY', 'LONG'],
            'STRONG_BUY'  => ['STRONG_BUY'],
            'LONG'        => ['LONG'],
            'DCA_BUY'     => ['DCA_BUY'],
            'SELL'        => ['SELL', 'STRONG_SELL', 'SHORT', 'PANIC_SELL'],
            'STRONG_SELL' => ['STRONG_SELL', 'PANIC_SELL'],
            'SHORT'       => ['SHORT'],
            'PANIC_SELL'  => ['PANIC_SELL'],
        ];

        $passed = false;
        if (isset($hierarchy[$this->expectedSignal])) {
            $passed = in_array($this->actualSignal, $hierarchy[$this->expectedSignal]);
        }

        $this->lastContext = [
            'type'       => 'external_signal',
            'condition'  => $this->expectedSignal,
            'passed'     => $passed,
            'indicators' => ['expected' => $this->expectedSignal, 'actual' => $this->actualSignal],
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
