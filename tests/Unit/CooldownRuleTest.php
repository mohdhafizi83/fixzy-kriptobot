<?php

namespace Fixzy\Kriptobot\Tests\Unit;

use Fixzy\Kriptobot\Trading\Rules\CooldownRule;
use PHPUnit\Framework\TestCase;

/**
 * Class CooldownRuleTest - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class CooldownRuleTest extends TestCase
{
    /**
     * Test First Trade Always Passes.
     * @return void
     */
    public function testFirstTradeAlwaysPasses(): void
    {
        $rule = new CooldownRule(0, 3600);
        $this->assertTrue($rule->evaluate());
        $this->assertSame('first_trade', $rule->getContext()['indicators']['reason']);
    }

    /**
     * Test Within Cooldown Blocks.
     * @return void
     */
    public function testWithinCooldownBlocks(): void
    {
        $rule = new CooldownRule(time() - 60, 3600);
        $this->assertFalse($rule->evaluate());
    }

    /**
     * Test After Cooldown Passes.
     * @return void
     */
    public function testAfterCooldownPasses(): void
    {
        $rule = new CooldownRule(time() - 3601, 3600);
        $this->assertTrue($rule->evaluate());
    }

    /**
     * Test Zero Cooldown Always Passes.
     * @return void
     */
    public function testZeroCooldownAlwaysPasses(): void
    {
        $rule = new CooldownRule(time(), 0);
        $this->assertTrue($rule->evaluate());
    }
}
