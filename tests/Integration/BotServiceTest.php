<?php

namespace Fixzy\Kriptobot\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Service\BotService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for BotService (Phase 1 API-first layer).
 *
 * Proves:
 *  - create/update round-trip through the service
 *  - partial updates deep-merge over the existing configuration
 *    (omitted sections like base_order/dca are NOT wiped)
 *  - csrf_token and runtime_state never leak into the stored configuration
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class BotServiceTest extends TestCase
{
    private Connection $db;
    private BotService $service;

    /**
     * Set Up.
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->db->executeStatement("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            password_hash TEXT
        )");
        $this->db->executeStatement("INSERT INTO users (email) VALUES ('test@kriptobot.local')");

        $this->db->executeStatement("CREATE TABLE bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            coin_pair TEXT NOT NULL,
            allocated_capital REAL NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 0,
            configuration TEXT,
            runtime_state TEXT,
            parent_id INTEGER,
            is_agent_managed INTEGER DEFAULT 0,
            agent_decision_id INTEGER
        )");

        $this->service = new BotService($this->db);
    }

    /**
     * Test Create Stores Config And Initial Runtime State.
     * @return void
     */
    public function testCreateStoresConfigAndInitialRuntimeState(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'csrf_token' => 'should-be-stripped',
            'runtime_state' => ['should' => 'not-persist'],
            'general' => [
                'name' => 'Test Bot',
                'exchange' => 'binance',
                'custom_pairs' => 'BTC/USDT',
                'capital' => 100,
            ],
            'base_order' => ['order_type' => 'market'],
        ]);

        $this->assertFalse($res['created'] === false);
        $bot = $this->service->get(1, $res['bot_id']);
        $this->assertSame('Test Bot', $bot['configuration']['general']['name']);
        $this->assertArrayNotHasKey('csrf_token', $bot['configuration']);
        $this->assertArrayNotHasKey('runtime_state', $bot['configuration']);
        $this->assertSame('IDLE', $bot['runtime_state']['status']);
        $this->assertSame('BTC/USDT', $bot['coin_pair']);
        $this->assertSame(1, $bot['status']);
    }

    /**
     * Test Partial Update Deep Merges Over Existing Config.
     * @return void
     */
    public function testPartialUpdateDeepMergesOverExistingConfig(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'Original', 'exchange' => 'binance', 'custom_pairs' => 'ETH/USDT', 'capital' => 50],
            'base_order' => ['order_type' => 'market', 'conditions' => [['type' => 'rsi_14', 'value' => '< 30']]],
            'dca' => ['max_steps' => 5, 'price_drop_trigger' => 2],
            'risk_management' => ['target_profit' => 1, 'min_guard_enabled' => true],
        ]);
        $botId = $res['bot_id'];

        // Partial update: only rename + change one dca field.
        $this->service->save(1, $botId, [
            'is_enabled' => true,
            'general' => ['name' => 'Renamed', 'exchange' => 'binance', 'custom_pairs' => 'ETH/USDT', 'capital' => 60],
            'dca' => ['price_drop_trigger' => 3],
        ]);

        $bot = $this->service->get(1, $botId);
        $cfg = $bot['configuration'];

        // Updated fields
        $this->assertSame('Renamed', $cfg['general']['name']);
        $this->assertSame(60.0, (float)$cfg['general']['capital']);
        $this->assertSame(3, $cfg['dca']['price_drop_trigger']);
        // Untouched sections survive the partial update
        $this->assertSame(5, $cfg['dca']['max_steps']);
        $this->assertSame('market', $cfg['base_order']['order_type']);
        $this->assertSame([['type' => 'rsi_14', 'value' => '< 30']], $cfg['base_order']['conditions']);
        $this->assertTrue($cfg['risk_management']['min_guard_enabled']);
        $this->assertSame(1, $cfg['risk_management']['target_profit']);
    }

    /**
     * Test List Overrides Replace Lists Wholesale.
     * @return void
     */
    public function testListOverridesReplaceListsWholesale(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'L', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
            'base_order' => ['conditions' => [['type' => 'a'], ['type' => 'b'], ['type' => 'c']]],
        ]);
        $botId = $res['bot_id'];

        $this->service->save(1, $botId, [
            'is_enabled' => true,
            'general' => ['name' => 'L', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
            'base_order' => ['conditions' => [['type' => 'x']]],
        ]);

        $bot = $this->service->get(1, $botId);
        // Lists are replaced, not appended.
        $this->assertSame([['type' => 'x']], $bot['configuration']['base_order']['conditions']);
    }

    /**
     * Test Auto Capital Stored As Zero.
     * @return void
     */
    public function testAutoCapitalStoredAsZero(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'Auto', 'custom_pairs' => 'BTC/USDT', 'capital' => 'AUTO'],
        ]);
        $bot = $this->service->get(1, $res['bot_id']);
        $this->assertSame(0.0, (float)$bot['allocated_capital']);
        $this->assertSame('AUTO', $bot['configuration']['general']['capital']);
    }

    /**
     * Test Update Other Users Bot Throws.
     * @return void
     */
    public function testUpdateOtherUsersBotThrows(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'Mine', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->service->save(999, $res['bot_id'], [
            'is_enabled' => true,
            'general' => ['name' => 'Hacked', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
        ]);
    }

    /**
     * Regression: payloads without ai_copilot store no such key — old bots
     * keep byte-identical configuration behaviour.
     * @return void
     */
    public function testConfigWithoutAiCopilotIsUntouched(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'Legacy', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
            'base_order' => ['order_type' => 'market'],
        ]);
        $bot = $this->service->get(1, $res['bot_id']);
        $this->assertArrayNotHasKey('ai_copilot', $bot['configuration']);
    }

    /**
     * A valid ai_copilot section is normalized and persisted.
     * @return void
     */
    public function testValidAiCopilotIsNormalizedAndStored(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'AI Bot', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
            'ai_copilot' => [
                'enabled' => true,
                'trust_level' => 'shadow',
                'min_confidence' => 0.75,
            ],
        ]);
        $bot = $this->service->get(1, $res['bot_id']);
        $ai = $bot['configuration']['ai_copilot'];
        $this->assertTrue($ai['enabled']);
        $this->assertSame('shadow', $ai['trust_level']);
        $this->assertSame(0.75, $ai['min_confidence']);
        // Missing keys filled from safe defaults
        $this->assertSame('1h', $ai['decision_timeframe']);
        $this->assertSame(3600, $ai['cache_ttl_seconds']);
        // JSON round-trip stores 3.0 as int 3 — compare numerically.
        $this->assertSame(3.0, (float)$ai['escalation']['flash_move_pct']);
    }

    /**
     * An invalid ai_copilot section is rejected with a clear error and the
     * bot row is left untouched.
     * @return void
     */
    public function testInvalidAiCopilotSaveThrowsAndKeepsOldConfig(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'Guarded', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
            'ai_copilot' => ['enabled' => false, 'trust_level' => 'off'],
        ]);
        $botId = $res['bot_id'];

        try {
            $this->service->save(1, $botId, [
                'is_enabled' => true,
                'general' => ['name' => 'Guarded', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
                'ai_copilot' => ['enabled' => true, 'trust_level' => 'yolo'],
            ]);
            $this->fail('Expected RuntimeException for invalid ai_copilot config.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('trust_level', $e->getMessage());
        }

        // Original configuration survived the rejected save.
        $bot = $this->service->get(1, $botId);
        $this->assertSame('off', $bot['configuration']['ai_copilot']['trust_level']);
    }

    /**
     * A corrupt ai_copilot section stored by a non-save() writer must not
     * lock the user out: unrelated saves still work and the stored section
     * is normalized to safe values.
     * @return void
     */
    public function testCorruptStoredAiSectionDoesNotLockOutSaves(): void
    {
        $res = $this->service->save(1, null, [
            'is_enabled' => true,
            'general' => ['name' => 'Corrupt', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
        ]);
        $botId = $res['bot_id'];
        // Simulate another writer storing garbage directly (bypasses save()).
        $this->db->update('bots', [
            'configuration' => json_encode([
                'general' => ['name' => 'Corrupt'],
                'ai_copilot' => ['enabled' => true, 'trust_level' => 'garbage'],
            ]),
        ], ['id' => $botId]);

        // A normal save that never touches ai_copilot must still succeed.
        $this->service->save(1, $botId, [
            'is_enabled' => true,
            'general' => ['name' => 'Renamed Anyway', 'custom_pairs' => 'BTC/USDT', 'capital' => 10],
        ]);
        $bot = $this->service->get(1, $botId);
        $this->assertSame('Renamed Anyway', $bot['configuration']['general']['name']);
        // Garbage section normalized to safe off-state.
        $this->assertSame('off', $bot['configuration']['ai_copilot']['trust_level']);
        $this->assertFalse($bot['configuration']['ai_copilot']['enabled']);
    }
}
