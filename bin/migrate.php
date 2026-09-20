<?php
// File: bin/migrate.php

require_once dirname(__DIR__) . '/vendor/autoload.php';
// Alternatively: require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Fixzy\Kriptobot\Database\DatabaseManager;

// 1. Determine the database folder path at the project root
$dbDirectory = dirname(__DIR__) . '/database';

// 2. Create the folder automatically if it does not exist yet (error prevention)
if (!is_dir($dbDirectory)) {
    mkdir($dbDirectory, 0777, true);
    echo "Folder 'database' created successfully.\n";
}

// 3. Database configuration (SQLite)
$config = [
    'DB_DRIVER' => 'pdo_sqlite',
    'DB_PATH'   => $dbDirectory . '/kriptobot.sqlite',
];

echo "Connecting to the database...\n";

$dbManager = new DatabaseManager($config);
$connection = $dbManager->getConnection();
$schemaManager = $connection->createSchemaManager();

// 2. New schema definition
$schema = new Schema();

// --- TABLE: users ---
$users = $schema->createTable('users');
$users->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
$users->addColumn('email', Types::STRING, ['length' => 255]);
$users->addColumn('password_hash', Types::STRING, ['length' => 255]);
$users->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['default' => 'CURRENT_TIMESTAMP']);
$users->setPrimaryKey(['id']);
$users->addUniqueIndex(['email']);

// --- TABLE: user_api_keys ---
// Stores Binance/KuCoin APIs encrypted
$apiKeys = $schema->createTable('user_api_keys');
$apiKeys->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
$apiKeys->addColumn('user_id', Types::INTEGER);
$apiKeys->addColumn('exchange_name', Types::STRING, ['length' => 50]); // binance, kucoin
$apiKeys->addColumn('api_key', Types::STRING, ['length' => 255]);
$apiKeys->addColumn('api_secret_encrypted', Types::TEXT); // Data encrypted by EncryptionService
$apiKeys->setPrimaryKey(['id']);
$apiKeys->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

// --- TABLE: bots ---
// Specific configuration for each user's bot
$bots = $schema->createTable('bots');
$bots->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
$bots->addColumn('user_id', Types::INTEGER);
$bots->addColumn('coin_pair', Types::STRING, ['length' => 20]); // E.g.: BTC/USDT
$bots->addColumn('allocated_capital', Types::FLOAT, ['default' => 0.0]); // Initial Capital
$bots->addColumn('status', Types::BOOLEAN, ['default' => false]); // ON / OFF

// New JSON columns (Replaces the rigid dca_steps and profit_target_pct)
$bots->addColumn('configuration', Types::JSON); // JSON: Stores Rules, Cooldown, UI settings
$bots->addColumn('runtime_state', Types::JSON); // JSON: Bot memory, Smart Mode, Holdings

$bots->setPrimaryKey(['id']);
$bots->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

// --- TABLE: trade_logs ---
// Audit trail (Log for AI decisions and Executive execution)
$logs = $schema->createTable('trade_logs');
$logs->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
$logs->addColumn('bot_id', Types::INTEGER);
$logs->addColumn('action', Types::STRING, ['length' => 20]); // BUY, SELL, HOLD, TAKE_PROFIT, CUT_LOSS
$logs->addColumn('price', Types::FLOAT, ['notnull' => false]);
$logs->addColumn('amount', Types::FLOAT, ['notnull' => false]);

// Replace the TEXT 'reason' column with a more comprehensive JSON 'audit_data' column
$logs->addColumn('audit_data', Types::JSON); // JSON: Stores multiple metrics at once (AI Signal, TV, etc.)

$logs->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['default' => 'CURRENT_TIMESTAMP']);
$logs->setPrimaryKey(['id']);
$logs->addForeignKeyConstraint('bots', ['bot_id'], ['id'], ['onDelete' => 'CASCADE']);

// --- TABLE: audit_logs (COMPREHENSIVE AUDIT LOG) ---
$auditLogs = $schema->createTable('audit_logs');
$auditLogs->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
$auditLogs->addColumn('bot_id', Types::INTEGER);
$auditLogs->addColumn('user_id', Types::INTEGER);
$auditLogs->addColumn('event_type', Types::STRING, ['length' => 50]); // RULE_EVAL, REJECTION, AI_DECISION, SIGNAL, ERROR, STATE_CHANGE, TRAILING
$auditLogs->addColumn('event_summary', Types::STRING, ['length' => 255, 'notnull' => false]);
$auditLogs->addColumn('context_data', Types::JSON); // JSON: Full event context
$auditLogs->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['default' => 'CURRENT_TIMESTAMP']);
$auditLogs->setPrimaryKey(['id']);
$auditLogs->addIndex(['bot_id', 'created_at']);
$auditLogs->addIndex(['user_id', 'created_at']);
$auditLogs->addIndex(['event_type']);
$auditLogs->addForeignKeyConstraint('bots', ['bot_id'], ['id'], ['onDelete' => 'CASCADE']);
$auditLogs->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);

// 3. Execute the schema against the database
echo "Checking the database schema...\n";
$sqls = $schema->toSql($connection->getDatabasePlatform());

foreach ($sqls as $sql) {
    try {
        $connection->executeStatement($sql);
        echo "Executed successfully: " . substr($sql, 0, 50) . "...\n";
    } catch (Exception $e) {
        // Ignore the error if the table already exists, or show a warning
        echo "Notice (table may already exist): " . $e->getMessage() . "\n";
    }
}

echo "Database Migration Complete!\n";