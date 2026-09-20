<?php

namespace Fixzy\Kriptobot\Tests\Unit;

use Fixzy\Kriptobot\Trading\Rules\MinimumProfitGuardRule;
use PHPUnit\Framework\TestCase;

class MinimumProfitGuardRuleTest extends TestCase
{
    public function testNoEntryPriceFails(): void
    {
        $rule = new MinimumProfitGuardRule(0.0, 100.0, 1.0, true, time(), 24);
        $this->assertFalse($rule->evaluate());
        $this->assertSame('no_entry_price', $rule->getContext()['indicators']['reason']);
    }

    public function testDisabledGuardAlwaysPasses(): void
    {
        $rule = new MinimumProfitGuardRule(100.0, 90.0, 5.0, false, time(), 24);
        $this->assertTrue($rule->evaluate());
        $this->assertTrue($rule->getContext()['indicators']['disabled']);
    }

    public function testProfitReachedPasses(): void
    {
        // Entry 100, current 106 => +6% >= 5%
        $rule = new MinimumProfitGuardRule(100.0, 106.0, 5.0, true, time(), 24);
        $this->assertTrue($rule->evaluate());
    }

    public function testProfitNotReachedBlocksBeforeTimeout(): void
    {
        // Entry 100, current 102 => +2% < 5%, entered 1h ago, timeout 24h
        $rule = new MinimumProfitGuardRule(100.0, 102.0, 5.0, true, time() - 3600, 24);
        $this->assertFalse($rule->evaluate());
    }

    public function testTimeoutOverridesProfitCheck(): void
    {
        // Only +2% profit but held 25h with 24h timeout => pass
        $rule = new MinimumProfitGuardRule(100.0, 102.0, 5.0, true, time() - 25 * 3600, 24);
        $this->assertTrue($rule->evaluate());
        $this->assertTrue($rule->getContext()['indicators']['timeout_reached']);
    }

    public function testSmartModeChecksProfitOnly(): void
    {
        // Smart mode: timeout irrelevant, profit decides
        $rule = new MinimumProfitGuardRule(100.0, 102.0, 5.0, true, time() - 100 * 3600, 24, true);
        $this->assertFalse($rule->evaluate());

        $rule2 = new MinimumProfitGuardRule(100.0, 106.0, 5.0, true, time() - 100 * 3600, 24, true);
        $this->assertTrue($rule2->evaluate());
    }

    public function testNegativeProfitBlocks(): void
    {
        $rule = new MinimumProfitGuardRule(100.0, 95.0, 1.0, true, time() - 60, 24);
        $this->assertFalse($rule->evaluate());
    }

    public function testExactProfitBoundaryPasses(): void
    {
        // +5.0000% exactly meets 5% threshold (>=)
        $rule = new MinimumProfitGuardRule(100.0, 105.0, 5.0, true, time() - 60, 24);
        $this->assertTrue($rule->evaluate());
    }
}
