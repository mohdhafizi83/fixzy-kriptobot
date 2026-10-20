<?php

namespace Fixzy\Kriptobot\Tests\Unit\Trading;

use Fixzy\Kriptobot\Trading\AdvisorBacktestGate;
use Fixzy\Kriptobot\Trading\AdvisorParams;
use PHPUnit\Framework\TestCase;

/**
 * Advisor-mode gates (Phase 3): parameter injection only when every gate
 * passes; the 30h backtest replay is deterministic and never worse-biased.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class AdvisorParamsTest extends TestCase
{
    /**
     * A minimal bot config.
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'dca' => ['price_drop_trigger' => 2.0, 'max_steps' => 4],
            'risk_management' => ['target_profit' => 2.0, 'trailing_tp_deviation' => 0.2],
        ];
    }

    /**
     * A fully approved cache.
     * @return array<string, mixed>
     */
    private function cache(array $overrides = []): array
    {
        return $overrides + [
            'params' => ['dca_drop_pct' => 3.0, 'max_dca_steps' => 5, 'tp_pct' => 4.0, 'trail_pct' => 1.0],
            'meets_confidence' => true,
            'params_approved' => true,
            'created_at' => time(),
        ];
    }

    public function testAllGatesPassInjectsParams(): void
    {
        $r = AdvisorParams::apply($this->config(), $this->cache(), 'advisor', 3600, time());
        $this->assertTrue($r['applied']);
        $this->assertSame(3.0, $r['config']['dca']['price_drop_trigger']);
        $this->assertSame(5, $r['config']['dca']['max_steps']);
        $this->assertSame(4.0, $r['config']['risk_management']['target_profit']);
        $this->assertSame(1.0, $r['config']['risk_management']['trailing_tp_deviation']);
        $this->assertCount(4, $r['changed']);
    }

    public function testEveryGateFailsSafeToStatic(): void
    {
        $now = time();
        $cases = [
            'mode off' => [$this->config(), $this->cache(), 'off', 3600, $now],
            'mode shadow' => [$this->config(), $this->cache(), 'shadow', 3600, $now],
            'no cache' => [$this->config(), null, 'advisor', 3600, $now],
            'scalar cache' => [$this->config(), 'garbage', 'advisor', 3600, $now],
            'no params' => [$this->config(), ['meets_confidence' => true, 'params_approved' => true, 'created_at' => $now], 'advisor', 3600, $now],
            'low confidence' => [$this->config(), $this->cache(['meets_confidence' => false]), 'advisor', 3600, $now],
            'stale' => [$this->config(), $this->cache(['created_at' => $now - 7200]), 'advisor', 3600, $now],
            'future timestamp' => [$this->config(), $this->cache(['created_at' => $now + 9999]), 'advisor', 3600, $now],
            'gate not passed' => [$this->config(), $this->cache(['params_approved' => false]), 'advisor', 3600, $now],
        ];
        foreach ($cases as $name => $args) {
            $r = AdvisorParams::apply(...$args);
            $this->assertFalse($r['applied'], $name);
            $this->assertSame(2.0, $r['config']['dca']['price_drop_trigger'], $name . ' must keep static');
        }
    }

    public function testTamperedCacheValuesRejectedByRangeCheck(): void
    {
        // apply() must not trust the cache: every value re-range-checked here.
        foreach ([
            ['tp_pct', 1e9], ['tp_pct', 0.0], ['tp_pct', -3.0],
            ['dca_drop_pct', 0.0001], ['dca_drop_pct', 999.0],
            ['max_dca_steps', 9999], ['max_dca_steps', -1],
            ['trail_pct', 1e6],
        ] as [$key, $val]) {
            // single-key cache: missing keys are skipped, present ones checked
            $r2 = AdvisorParams::apply($this->config(), $this->cache(['params' => [$key => $val]]), 'advisor', 3600, time());
            $this->assertFalse($r2['applied'], "$key=$val must be rejected by range check");
        }
        $str = AdvisorParams::apply($this->config(), $this->cache(['params' => ['tp_pct' => 'abc']]), 'advisor', 3600, time());
        $this->assertFalse($str['applied'], 'non-numeric must be rejected');
    }

    public function testActiveModeAlsoInjects(): void
    {
        $r = AdvisorParams::apply($this->config(), $this->cache(), 'active', 3600, time());
        $this->assertTrue($r['applied']);
    }

    public function testMissingConfigSectionsAreCreated(): void
    {
        $r = AdvisorParams::apply([], $this->cache(), 'advisor', 3600, time());
        $this->assertTrue($r['applied']);
        $this->assertSame(3.0, $r['config']['dca']['price_drop_trigger']);
    }

    public function testStaticParamsDefaults(): void
    {
        $s = AdvisorParams::staticParams([]);
        $this->assertSame(2.0, $s['dca_drop_pct']);
        $this->assertSame(4, $s['max_dca_steps']);
        $this->assertSame(2.0, $s['tp_pct']);
    }

    public function testParamsHashStableAndDistinct(): void
    {
        $a = ['dca_drop_pct' => 2.0, 'max_dca_steps' => 4, 'tp_pct' => 2.0, 'trail_pct' => 0.2];
        $b = $a;
        $b['tp_pct'] = 2.1;
        $this->assertSame(AdvisorParams::paramsHash($a), AdvisorParams::paramsHash($a));
        $this->assertNotSame(AdvisorParams::paramsHash($a), AdvisorParams::paramsHash($b));
    }

    /**
     * Candle series helper: linear walk.
     * @return list<array<int, float>>
     */
    private function candles(array $closes): array
    {
        $out = [];
        foreach ($closes as $i => $c) {
            $out[] = [1700000000000 + $i * 3600000.0, $c, $c * 1.001, $c * 0.999, $c, 1.0];
        }
        return $out;
    }

    public function testReplayDeterministicAndProfitableOnZigZag(): void
    {
        // zig-zag down 3% up 5% repeatedly: tp 4% with dca should realize profit
        $closes = [];
        $price = 100.0;
        for ($i = 0; $i < 30; $i++) {
            $price *= ($i % 2 === 0) ? 0.97 : 1.05;
            $closes[] = round($price, 4);
        }
        $params = ['dca_drop_pct' => 2.0, 'max_dca_steps' => 4, 'tp_pct' => 4.0, 'trail_pct' => 1.0];
        $r1 = AdvisorBacktestGate::replay($this->candles($closes), $params);
        $r2 = AdvisorBacktestGate::replay($this->candles($closes), $params);
        $this->assertSame($r1['pnl_pct'], $r2['pnl_pct']); // deterministic
        $this->assertGreaterThan(0.0, $r1['pnl_pct']);
        $this->assertGreaterThanOrEqual(0.0, $r1['max_dd_pct']);
    }

    public function testEvaluateApprovesNotWorseParams(): void
    {
        $candles = $this->candles([100, 99, 98, 99, 101, 103, 102, 104, 106, 105, 107, 109]);
        $static = ['dca_drop_pct' => 2.0, 'max_dca_steps' => 4, 'tp_pct' => 2.0, 'trail_pct' => 0.2];
        $same = AdvisorBacktestGate::evaluate($candles, $static, $static);
        $this->assertTrue($same['approved']); // identical params can never be worse
        $this->assertSame($same['new_pnl_pct'], $same['static_pnl_pct']);

        // absurd params (tp 20% on a 30h window) should not beat static here
        $absurd = ['dca_drop_pct' => 0.5, 'max_dca_steps' => 8, 'tp_pct' => 20.0, 'trail_pct' => 5.0];
        $bad = AdvisorBacktestGate::evaluate($candles, $absurd, $static);
        $this->assertFalse($bad['approved']);
    }

    public function testEvaluateFailsClosedOnDegenerateInput(): void
    {
        // F3 contract: no evidence => NO approval, ever.
        $r = AdvisorBacktestGate::evaluate([], ['tp_pct' => 2, 'dca_drop_pct' => 2], ['tp_pct' => 2, 'dca_drop_pct' => 2]);
        $this->assertFalse($r['approved']);
        $r2 = AdvisorBacktestGate::replay($this->candles([0, 0, 0]), ['dca_drop_pct' => 2, 'tp_pct' => 2]);
        $this->assertSame(0.0, $r2['pnl_pct']);
        // degenerate params (tp<=0) on a short window must not auto-approve
        $r3 = AdvisorBacktestGate::evaluate($this->candles([1, 2, 3, 4, 5, 6]), ['dca_drop_pct' => 2, 'tp_pct' => 0], ['dca_drop_pct' => 2, 'tp_pct' => 2]);
        $this->assertFalse($r3['approved']);
    }
}
