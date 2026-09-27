<?php

namespace Fixzy\Kriptobot\Tests\Unit;

use Fixzy\Kriptobot\Trading\Rules\MinimumProfitGuardRule;
use PHPUnit\Framework\TestCase;

/**
 * Class MinimumProfitGuardRuleTest - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class MinimumProfitGuardRuleTest extends TestCase
{
    /**
     * Test No Entry Price Fails.
     * @return void
     */
    public function testNoEntryPriceFails(): void
    {
        $rule = new MinimumProfitGuardRule(0.0, 100.0, 1.0, true, time(), 24);
        $this->assertFalse($rule->evaluate());
        $this->assertSame('no_entry_price', $rule->getContext()['indicators']['reason']);
    }

    /**
     * Test Disabled Guard Always Passes.
     * @return void
     */
    public function testDisabledGuardAlwaysPasses(): void
    {
        $rule = new MinimumProfitGuardRule(100.0, 90.0, 5.0, false, time(), 24);
        $this->assertTrue($rule->evaluate());
        $this->assertTrue($rule->getContext()['indicators']['disabled']);
    }

    /**
     * Test Profit Reached Passes.
     * @return void
     */
    public function testProfitReachedPasses(): void
    {
        // Entry 100, current 106 => +6% >= 5%
        $rule = new MinimumProfitGuardRule(100.0, 106.0, 5.0, true, time(), 24);
        $this->assertTrue($rule->evaluate());
    }

    /**
     * Test Profit Not Reached Blocks Before Timeout.
     * @return void
     */
    public function testProfitNotReachedBlocksBeforeTimeout(): void
    {
        // Entry 100, current 102 => +2% < 5%, entered 1h ago, timeout 24h
        $rule = new MinimumProfitGuardRule(100.0, 102.0, 5.0, true, time() - 3600, 24);
        $this->assertFalse($rule->evaluate());
    }

    /**
     * Test Timeout Overrides Profit Check.
     * @return void
     */
    public function testTimeoutOverridesProfitCheck(): void
    {
        // Only +2% profit but held 25h with 24h timeout => pass
        $rule = new MinimumProfitGuardRule(100.0, 102.0, 5.0, true, time() - 25 * 3600, 24);
        $this->assertTrue($rule->evaluate());
        $this->assertTrue($rule->getContext()['indicators']['timeout_reached']);
    }

    /**
     * Test Smart Mode Checks Profit Only.
     * @return void
     */
    public function testSmartModeChecksProfitOnly(): void
    {
        // Smart mode: timeout irrelevant, profit decides
        $rule = new MinimumProfitGuardRule(100.0, 102.0, 5.0, true, time() - 100 * 3600, 24, true);
        $this->assertFalse($rule->evaluate());

        $rule2 = new MinimumProfitGuardRule(100.0, 106.0, 5.0, true, time() - 100 * 3600, 24, true);
        $this->assertTrue($rule2->evaluate());
    }

    /**
     * Test Negative Profit Blocks.
     * @return void
     */
    public function testNegativeProfitBlocks(): void
    {
        $rule = new MinimumProfitGuardRule(100.0, 95.0, 1.0, true, time() - 60, 24);
        $this->assertFalse($rule->evaluate());
    }

    /**
     * Test Exact Profit Boundary Passes.
     * @return void
     */
    public function testExactProfitBoundaryPasses(): void
    {
        // +5.0000% exactly meets 5% threshold (>=)
        $rule = new MinimumProfitGuardRule(100.0, 105.0, 5.0, true, time() - 60, 24);
        $this->assertTrue($rule->evaluate());
    }
}
