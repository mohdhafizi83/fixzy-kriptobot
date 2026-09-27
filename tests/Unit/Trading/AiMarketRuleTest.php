<?php

namespace Fixzy\Kriptobot\Tests\Unit\Trading;

use Fixzy\Kriptobot\Trading\Rules\AiMarketRule;
use PHPUnit\Framework\TestCase;

/**
 * Class AiMarketRuleTest - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class AiMarketRuleTest extends TestCase
{
    /**
     * Test Buy Matches Buy.
     * @return void
     */
    public function testBuyMatchesBuy(): void
    {
        $rule = new AiMarketRule('BUY', 'BUY');
        $this->assertTrue($rule->evaluate());
    }

    /**
     * Test Sell Matches Sell.
     * @return void
     */
    public function testSellMatchesSell(): void
    {
        $rule = new AiMarketRule('SELL', 'SELL');
        $this->assertTrue($rule->evaluate());
    }

    /**
     * Test Empty Signal Never Passes.
     * @return void
     */
    public function testEmptySignalNeverPasses(): void
    {
        foreach (['', 'EMPTY', 'HOLD', 'unknown'] as $actual) {
            $buy = new AiMarketRule('BUY', $actual);
            $this->assertFalse($buy->evaluate(), "EMPTY-like '$actual' must not pass BUY condition (bot waits)");
            $sell = new AiMarketRule('SELL', $actual);
            $this->assertFalse($sell->evaluate(), "EMPTY-like '$actual' must not pass SELL condition (bot waits)");
        }
    }

    /**
     * Test Opposite Signal Fails.
     * @return void
     */
    public function testOppositeSignalFails(): void
    {
        $this->assertFalse((new AiMarketRule('BUY', 'SELL'))->evaluate());
        $this->assertFalse((new AiMarketRule('SELL', 'BUY'))->evaluate());
    }

    /**
     * Test Case Insensitive.
     * @return void
     */
    public function testCaseInsensitive(): void
    {
        $this->assertTrue((new AiMarketRule('buy', ' BUY '))->evaluate());
    }

    /**
     * Test Context Records Expected And Actual.
     * @return void
     */
    public function testContextRecordsExpectedAndActual(): void
    {
        $rule = new AiMarketRule('BUY', '');
        $rule->evaluate();
        $ctx = $rule->getContext();
        $this->assertSame('ai_market', $ctx['type']);
        $this->assertSame('BUY', $ctx['indicators']['expected']);
        $this->assertSame('EMPTY', $ctx['indicators']['actual']);
    }
}
