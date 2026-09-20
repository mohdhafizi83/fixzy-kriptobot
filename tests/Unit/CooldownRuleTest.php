<?php

namespace Fixzy\Kriptobot\Tests\Unit;

use Fixzy\Kriptobot\Trading\Rules\CooldownRule;
use PHPUnit\Framework\TestCase;

class CooldownRuleTest extends TestCase
{
    public function testFirstTradeAlwaysPasses(): void
    {
        $rule = new CooldownRule(0, 3600);
        $this->assertTrue($rule->evaluate());
        $this->assertSame('first_trade', $rule->getContext()['indicators']['reason']);
    }

    public function testWithinCooldownBlocks(): void
    {
        $rule = new CooldownRule(time() - 60, 3600);
        $this->assertFalse($rule->evaluate());
    }

    public function testAfterCooldownPasses(): void
    {
        $rule = new CooldownRule(time() - 3601, 3600);
        $this->assertTrue($rule->evaluate());
    }

    public function testZeroCooldownAlwaysPasses(): void
    {
        $rule = new CooldownRule(time(), 0);
        $this->assertTrue($rule->evaluate());
    }
}
