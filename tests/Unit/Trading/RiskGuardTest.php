<?php

namespace Fixzy\Kriptobot\Tests\Unit\Trading;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fixzy\Kriptobot\Trading\RiskGuard;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the hard risk layer (Phase 1). Every guard from
 * PROJECT_BRIEF section 6 gets its own test: kill switch, exposure caps,
 * cut-loss cooldown, order sanity, AI DCA clamp.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class RiskGuardTest extends TestCase
{
    private Connection $db;
    private RiskGuard $guard;

    /**
     * Set Up.
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)");
        $this->db->executeStatement("INSERT INTO users (id, email) VALUES (1, 't@t.local')");
        $this->db->executeStatement("CREATE TABLE bots (
            id INTEGER PRIMARY KEY, user_id INTEGER, coin_pair TEXT,
            allocated_capital REAL DEFAULT 0, status INTEGER DEFAULT 1,
            runtime_state TEXT DEFAULT '{}', configuration TEXT DEFAULT '{}'
        )");
        $this->db->executeStatement("CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY, bot_id INTEGER, user_id INTEGER,
            event_type TEXT, event_summary TEXT, context_data TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->executeStatement("CREATE TABLE app_settings (
            setting_key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '',
            encrypted INTEGER NOT NULL DEFAULT 0, updated_at TEXT DEFAULT ''
        )");
        $this->guard = new RiskGuard($this->db);
    }

    /**
     * Seed one bot row.
     * @return void
     */
    private function seedBot(int $id, string $pair, float $capital, array $state = []): void
    {
        $this->db->insert('bots', [
            'id' => $id, 'user_id' => 1, 'coin_pair' => $pair,
            'allocated_capital' => $capital, 'status' => 1,
            'runtime_state' => json_encode($state),
        ]);
    }

    /**
     * Seed a realized sell event.
     * @return void
     */
    private function seedSell(string $event, float $usdtValue, float $pnlPct, string $when = 'now'): void
    {
        $ts = $when === 'now' ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', strtotime($when));
        $this->db->insert('audit_logs', [
            'bot_id' => 1, 'user_id' => 1, 'event_type' => $event,
            'context_data' => json_encode(['usdt_value' => $usdtValue, 'pnl_percent' => $pnlPct]),
            'created_at' => $ts,
        ]);
    }

    public function testDailyRealizedNetSumsSellEventsOnly(): void
    {
        // +10% on $110 sell => +10 profit; -5% on $95 sell => -5 profit.
        $this->seedSell('TAKE_PROFIT', 110.0, 10.0);
        $this->seedSell('CUT_LOSS', 95.0, -5.0);
        $this->db->insert('audit_logs', [
            'bot_id' => 1, 'user_id' => 1, 'event_type' => 'BASE_BUY',
            'context_data' => json_encode(['usdt_value' => 50.0]),
        ]);
        $this->seedSell('CUSTOM_SELL', 100.0, 100.0); // malformed-ish but valid: doubles cost
        $net = $this->guard->dailyRealizedNetUsdt(1, (int)strtotime('today UTC'));
        // 110*10/110 + 95*(-5)/95 + 100*100/200 = 10 - 5 + 50 = 55
        $this->assertSame(55.0, $net);
    }

    public function testDailyRealizedNetIgnoresYesterdayAndGarbage(): void
    {
        $this->seedSell('TAKE_PROFIT', 110.0, 10.0, 'yesterday');
        $this->db->insert('audit_logs', [
            'bot_id' => 1, 'user_id' => 1, 'event_type' => 'CUT_LOSS',
            'context_data' => 'not-json',
        ]);
        $this->db->insert('audit_logs', [
            'bot_id' => 1, 'user_id' => 1, 'event_type' => 'CUT_LOSS',
            'context_data' => json_encode(['usdt_value' => 10.0, 'pnl_percent' => -100.0]),
        ]);
        $this->assertSame(0.0, $this->guard->dailyRealizedNetUsdt(1, (int)strtotime('today UTC')));
    }

    public function testCapitalBaseSumsEnabledBotsWithFallback(): void
    {
        $this->seedBot(1, 'BTC/USDT', 300.0);
        $this->seedBot(2, 'ETH/USDT', 200.0);
        $this->assertSame(500.0, $this->guard->capitalBaseUsdt(1, ['max_total_capital' => 9999.0]));
        // All AUTO (0) → fall back to agent max_total_capital.
        $this->db->executeStatement("UPDATE bots SET allocated_capital = 0");
        $this->assertSame(9999.0, $this->guard->capitalBaseUsdt(1, ['max_total_capital' => 9999.0]));
    }

    public function testKillSwitchTripBlocksAndClearUnblocks(): void
    {
        $this->seedBot(1, 'BTC/USDT', 1000.0);
        $agent = ['max_capital_per_bot' => 1000.0, 'max_total_capital' => 10000.0];
        $price = static fn(string $s): ?float => 100.0;

        $state = $this->guard->killSwitchState(1, $agent);
        $this->assertFalse($state['tripped']);
        $this->assertSame(1000.0, $state['base_usdt']);
        $this->assertSame(20.0, $state['limit_usdt']); // 2% default

        $until = $this->guard->tripKillSwitch(1);
        $this->assertGreaterThan(time() + 23 * 3600, $until);
        $this->assertTrue($this->guard->killSwitchState(1, $agent)['tripped']);

        $check = $this->guard->checkNewEntry(1, 'BTC/USDT', 50.0, $agent, $price);
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('Kill switch', $check['reason']);

        $this->guard->clearKillSwitch(1);
        $this->assertFalse($this->guard->killSwitchState(1, $agent)['tripped']);
        $this->assertTrue($this->guard->checkNewEntry(1, 'BTC/USDT', 50.0, $agent, $price)['ok']);
    }

    public function testKillSwitchTripsWhenDailyLossExceedsLimit(): void
    {
        $this->seedBot(1, 'BTC/USDT', 1000.0); // base 1000, limit 2% = $20
        $this->seedSell('CUT_LOSS', 940.0, -6.0); // realized loss = 940*(-6)/94 = -60
        $ks = $this->guard->killSwitchState(1, []);
        $this->assertSame(-60.0, $ks['loss_usdt']);
        $this->assertTrue($ks['loss_usdt'] <= -$ks['limit_usdt']);
    }

    public function testExposureCapsBlockNewEntry(): void
    {
        // Bot holding 9 coins @100 = $900 exposure on BTC.
        $this->seedBot(1, 'BTC/USDT', 1000.0, ['current_holdings' => 9.0]);
        $agent = ['max_capital_per_bot' => 1000.0, 'max_total_capital' => 1000.0];
        $price = static fn(string $s): ?float => 100.0;

        // $900 + $150 > $1000 per-coin cap → block.
        $check = $this->guard->checkNewEntry(1, 'BTC/USDT', 150.0, $agent, $price);
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('Per-coin cap', $check['reason']);

        // $900 + $50 fits per-coin but total cap $1000 also fits → ok.
        $this->assertTrue($this->guard->checkNewEntry(1, 'BTC/USDT', 50.0, $agent, $price)['ok']);

        // Total cap: second coin on another bot pushes total over.
        $this->seedBot(2, 'ETH/USDT', 500.0, ['current_holdings' => 1.0]); // +$100
        $agentBig = ['max_capital_per_bot' => 1000.0, 'max_total_capital' => 950.0];
        $check2 = $this->guard->checkNewEntry(1, 'SOL/USDT', 10.0, $agentBig, $price);
        $this->assertFalse($check2['ok']);
        $this->assertStringContainsString('Total cap', $check2['reason']);
    }

    public function testFailClosedOnMissingPriceButDegradesWithoutHub(): void
    {
        $this->seedBot(1, 'BTC/USDT', 1000.0);
        $agent = ['max_capital_per_bot' => 1000.0, 'max_total_capital' => 10000.0];
        $noPrice = static fn(string $s): ?float => null;

        $blocked = $this->guard->checkNewEntry(1, 'BTC/USDT', 50.0, $agent, $noPrice, true);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('HOLD', $blocked['reason']);

        // No price hub at all → do not block everything (backward compatible).
        $this->assertTrue($this->guard->checkNewEntry(1, 'BTC/USDT', 50.0, $agent, $noPrice, false)['ok']);
    }

    public function testDcaHeadroomUsesPerCoinCapOnly(): void
    {
        $this->seedBot(1, 'BTC/USDT', 1000.0, ['current_holdings' => 9.8]);
        $agent = ['max_capital_per_bot' => 1000.0, 'max_total_capital' => 100.0]; // total cap tiny on purpose
        $price = static fn(string $s): ?float => 100.0;
        // $980 + $40 > $1000 per-coin → DCA blocked even though kill switch is off.
        $this->assertFalse($this->guard->checkDcaHeadroom(1, 'BTC/USDT', 40.0, $agent, $price)['ok']);
        $this->assertTrue($this->guard->checkDcaHeadroom(1, 'BTC/USDT', 40.0, ['max_capital_per_bot' => 2000.0], $price)['ok']);
    }

    public function testCutLossCooldownWindow(): void
    {
        $this->assertSame(0, $this->guard->cutLossCooldownRemainingSec([]));
        $this->assertSame(0, $this->guard->cutLossCooldownRemainingSec(['cut_loss_at' => 0]));
        $recent = $this->guard->cutLossCooldownRemainingSec(['cut_loss_at' => time() - 60]);
        $this->assertGreaterThan(3 * 3600, $recent);
        $this->assertLessThanOrEqual(4 * 3600, $recent);
        $this->assertSame(0, $this->guard->cutLossCooldownRemainingSec(['cut_loss_at' => time() - 5 * 3600]));
    }

    public function testOrderSanity(): void
    {
        $this->assertTrue($this->guard->sanityCheckOrder(100.0, 100.5, 50.0)['ok']);
        // 5% deviation > 2% default.
        $bad = $this->guard->sanityCheckOrder(105.0, 100.0, 50.0);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('deviates', $bad['reason']);
        $this->assertFalse($this->guard->sanityCheckOrder(100.0, null, 4.99)['ok']);   // below min
        $this->assertFalse($this->guard->sanityCheckOrder(-1.0, 100.0, 50.0)['ok']);   // bad price
        $this->assertTrue($this->guard->sanityCheckOrder(100.0, null, 50.0)['ok']);    // no mid → pass
    }

    public function testClampDcaSteps(): void
    {
        $this->assertSame(4, $this->guard->clampDcaSteps('off', 4));
        $this->assertSame(0, $this->guard->clampDcaSteps('off', 0));   // 0 = DCA disabled, stays
        $this->assertSame(0, $this->guard->clampDcaSteps('advisor', -3));
        $this->assertSame(8, $this->guard->clampDcaSteps('advisor', 20)); // AI mode hard cap
        $this->assertSame(5, $this->guard->clampDcaSteps('shadow', 5));
    }

    public function testAppSettingsOverrideWins(): void
    {
        $this->db->insert('app_settings', ['setting_key' => 'risk_guard.daily_loss_pct', 'value' => '0.5', 'encrypted' => 0, 'updated_at' => '']);
        $this->assertSame(0.5, $this->guard->setting('daily_loss_pct'));
        // Garbage value falls back to default.
        $this->db->insert('app_settings', ['setting_key' => 'risk_guard.kill_switch_hours', 'value' => 'abc', 'encrypted' => 0, 'updated_at' => '']);
        $this->assertSame(24.0, $this->guard->setting('kill_switch_hours'));
    }
}
