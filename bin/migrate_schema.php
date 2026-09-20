<?php

/**
 * Fixzy Kriptobot — Schema Migration
 *
 * Idempotent: safe to run multiple times. Adds any columns/tables that the
 * current code requires but an older database may be missing.
 *
 * Usage: php bin/migrate_schema.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Fixzy\Kriptobot\Config\Config::load();

use Fixzy\Kriptobot\Database\Database;

$conn = Database::getConnection();

/**
 * Add a column to a table if it does not already exist.
 */
function addColumnIfMissing(\Doctrine\DBAL\Connection $conn, string $table, string $column, string $definition): void
{
    $existing = array_map(
        static fn (\Doctrine\DBAL\Schema\Column $c): string => $c->getName(),
        $conn->createSchemaManager()->listTableColumns($table)
    );
    if (in_array($column, $existing, true)) {
        echo "  - {$table}.{$column} already exists, skipping\n";
        return;
    }
    $conn->executeStatement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    echo "  + Added {$table}.{$column}\n";
}

echo "Running schema migration...\n";

// --- Fresh install: create the full schema from database/schema.sql ----------
$tables = $conn->createSchemaManager()->listTableNames();
if (!in_array('bots', array_map('strtolower', $tables), true)) {
    $schemaFile = dirname(__DIR__) . '/database/schema.sql';
    if (!is_file($schemaFile)) {
        fwrite(STDERR, "Fresh install requires database/schema.sql but it is missing.\n");
        exit(1);
    }
    echo "  * Fresh database detected — creating full schema...\n";
    $sql = file_get_contents($schemaFile);
    // Execute statement-by-statement (SQLite does not accept multi-statement exec).
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $conn->executeStatement($stmt);
        }
    }
    // Seed the default single user (web session is hardcoded to user id 1).
    $conn->executeStatement(
        "INSERT INTO users (id, email, password_hash, is_demo_mode) VALUES (1, 'admin@kriptobot.local', '', 1)"
    );
    echo "  * Seeded default user admin@kriptobot.local (id 1)\n";
}

// --- bots: agent management columns (required by Agent tools & ApprovalManager) ---
addColumnIfMissing($conn, 'bots', 'is_agent_managed', 'INTEGER NOT NULL DEFAULT 0');
addColumnIfMissing($conn, 'bots', 'agent_decision_id', 'INTEGER DEFAULT NULL');

// --- users: safety & notification columns ---
addColumnIfMissing($conn, 'users', 'is_demo_mode', 'INTEGER NOT NULL DEFAULT 1');
addColumnIfMissing($conn, 'users', 'testnet_api_key', 'TEXT DEFAULT NULL');
addColumnIfMissing($conn, 'users', 'testnet_api_secret_encrypted', 'TEXT DEFAULT NULL');
addColumnIfMissing($conn, 'users', 'global_filters', 'TEXT DEFAULT NULL');
addColumnIfMissing($conn, 'users', 'smart_recovery_mode', 'INTEGER NOT NULL DEFAULT 0');
addColumnIfMissing($conn, 'users', 'telegram_chat_id', 'VARCHAR(50) DEFAULT NULL');

echo "Migration complete.\n";
