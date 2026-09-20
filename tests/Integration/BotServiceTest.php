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
 */
class BotServiceTest extends TestCase
{
    private Connection $db;
    private BotService $service;

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
}
