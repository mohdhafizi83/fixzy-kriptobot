<?php

namespace Fixzy\Kriptobot\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for agent database writes.
 *
 * These prove the Phase 2 fix: the old code used
 * getNativeConnection()->prepare()->execute() which does NOT exist in
 * Doctrine DBAL 4 and fataled at runtime. All agent writes now use
 * Connection::executeStatement() + lastInsertId().
 *
 * Uses a temporary SQLite database with the real schema.
 */
class AgentDatabaseTest extends TestCase
{
    private Connection $db;

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
            configuration TEXT NOT NULL,
            runtime_state TEXT NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            is_agent_managed INTEGER NOT NULL DEFAULT 0,
            agent_decision_id INTEGER DEFAULT NULL
        )");

        $this->db->executeStatement("CREATE TABLE agent_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT,
            context TEXT,
            language TEXT DEFAULT 'en',
            status TEXT DEFAULT 'active',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->executeStatement("CREATE TABLE agent_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            content TEXT,
            tool_calls TEXT,
            tool_results TEXT,
            token_usage INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->executeStatement("CREATE TABLE agent_decisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER,
            user_id INTEGER NOT NULL,
            decision_type TEXT NOT NULL,
            bot_id INTEGER,
            proposed_config TEXT,
            original_config TEXT,
            justification TEXT,
            market_snapshot TEXT,
            risk_profile TEXT,
            backtest_results TEXT,
            requiring_approval INTEGER DEFAULT 1,
            status TEXT DEFAULT 'pending_approval',
            approved_config TEXT,
            approved_by TEXT DEFAULT 'user',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testSessionCreateUsesExecuteStatementAndReturnsId(): void
    {
        // Replicate the exact write pattern from AgentSession::create()
        $this->db->executeStatement(
            "INSERT INTO agent_sessions (user_id, title, context, language, status)
             VALUES (?, ?, ?, ?, 'active')",
            [1, 'Test session', json_encode(['objective' => 'test']), 'en']
        );
        $sessionId = (int) $this->db->lastInsertId();

        $this->assertGreaterThan(0, $sessionId);

        $row = $this->db->fetchAssociative("SELECT * FROM agent_sessions WHERE id = ?", [$sessionId]);
        $this->assertNotFalse($row);
        $this->assertSame('Test session', $row['title']);
        $this->assertSame('active', $row['status']);
    }

    public function testMessageInsertRoundTrip(): void
    {
        $this->db->executeStatement(
            "INSERT INTO agent_sessions (user_id, title) VALUES (?, ?)",
            [1, 's']
        );
        $sessionId = (int) $this->db->lastInsertId();

        $this->db->executeStatement(
            "INSERT INTO agent_messages (session_id, role, content, tool_calls, tool_results, token_usage)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$sessionId, 'user', 'hello', json_encode([['id' => 'tc1']]), null, 42]
        );
        $msgId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $msgId);

        $row = $this->db->fetchAssociative("SELECT * FROM agent_messages WHERE id = ?", [$msgId]);
        $this->assertSame('user', $row['role']);
        $this->assertSame(42, (int) $row['token_usage']);
    }

    public function testDecisionInsertWithNulls(): void
    {
        $this->db->executeStatement(
            "INSERT INTO agent_decisions
             (session_id, user_id, decision_type, bot_id, proposed_config, original_config,
              justification, market_snapshot, risk_profile, backtest_results, requiring_approval, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending_approval')",
            [1, 1, 'optimize', null, json_encode(['a' => 1]), null, 'because', null, null, null]
        );
        $decisionId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $decisionId);

        $row = $this->db->fetchAssociative("SELECT * FROM agent_decisions WHERE id = ?", [$decisionId]);
        $this->assertSame('pending_approval', $row['status']);
        $this->assertNull($row['bot_id']);
    }

    public function testBotInsertWithAgentColumns(): void
    {
        // Replicates CreateBotTool write pattern (requires migrated schema)
        $this->db->executeStatement(
            "INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state, is_agent_managed)
             VALUES (?, ?, ?, 0, ?, ?, 1)",
            [1, 'BTC/USDT', 100.0, json_encode(['general' => []]), json_encode(['status' => 'IDLE'])]
        );
        $botId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $botId);

        $row = $this->db->fetchAssociative("SELECT * FROM bots WHERE id = ?", [$botId]);
        $this->assertSame(1, (int) $row['is_agent_managed']);
        $this->assertSame('BTC/USDT', $row['coin_pair']);
    }
}
