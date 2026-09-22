<?php

/**
 * Fixzy Kriptobot — Schema Migration
 *
 * Idempotent: safe to run multiple times. Adds any columns/tables that the
 * current code requires but an older database may be missing.
 *
 * Usage: php bin/migrate_schema.php
 * Also callable programmatically via runKriptobotMigration($conn).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

\Fixzy\Kriptobot\Config\Config::load();

use Fixzy\Kriptobot\Database\Database;

if (!function_exists('addColumnIfMissing')) {
    /**
     * Add a column to a table if it does not already exist.
     */
    function addColumnIfMissing(\Doctrine\DBAL\Connection $conn, string $table, string $column, string $definition, bool $quiet = false): void
    {
        $existing = array_map(
            static fn (\Doctrine\DBAL\Schema\Column $c): string => $c->getName(),
            $conn->createSchemaManager()->listTableColumns($table)
        );
        if (in_array($column, $existing, true)) {
            if (!$quiet) {
                echo "  - {$table}.{$column} already exists, skipping\n";
            }
            return;
        }
        $conn->executeStatement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        if (!$quiet) {
            echo "  + Added {$table}.{$column}\n";
        }
    }
}

if (!function_exists('runKriptobotMigration')) {
    function runKriptobotMigration(\Doctrine\DBAL\Connection $conn, bool $quiet = false): void
    {
        if (!$quiet) {
            echo "Running schema migration...\n";
        }

        // --- Fresh install: create the full schema from database/schema.sql ----
        $tables = $conn->createSchemaManager()->listTableNames();
        if (!in_array('bots', array_map('strtolower', $tables), true)) {
            $schemaFile = dirname(__DIR__) . '/database/schema.sql';
            if (!is_file($schemaFile)) {
                fwrite(STDERR, "Fresh install requires database/schema.sql but it is missing.\n");
                exit(1);
            }
            if (!$quiet) {
                echo "  * Fresh database detected — creating full schema...\n";
            }
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
            if (!$quiet) {
                echo "  * Seeded default user admin@kriptobot.local (id 1)\n";
            }
        }

        // --- bots: agent management columns (required by Agent tools & ApprovalManager) ---
        addColumnIfMissing($conn, 'bots', 'is_agent_managed', 'INTEGER NOT NULL DEFAULT 0', $quiet);
        addColumnIfMissing($conn, 'bots', 'agent_decision_id', 'INTEGER DEFAULT NULL', $quiet);

        // --- users: safety & notification columns ---
        addColumnIfMissing($conn, 'users', 'is_demo_mode', 'INTEGER NOT NULL DEFAULT 1', $quiet);
        addColumnIfMissing($conn, 'users', 'testnet_api_key', 'TEXT DEFAULT NULL', $quiet);
        addColumnIfMissing($conn, 'users', 'testnet_api_secret_encrypted', 'TEXT DEFAULT NULL', $quiet);
        addColumnIfMissing($conn, 'users', 'global_filters', 'TEXT DEFAULT NULL', $quiet);
        addColumnIfMissing($conn, 'users', 'smart_recovery_mode', 'INTEGER NOT NULL DEFAULT 0', $quiet);
        addColumnIfMissing($conn, 'users', 'telegram_chat_id', 'VARCHAR(50) DEFAULT NULL', $quiet);

        // --- app_settings: user-managed config (Web UI / CLI setup), DB wins over .env ---
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                encrypted INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        if (!$quiet) {
            echo "  - app_settings ready\n";
            echo "Migration complete.\n";
        }
    }
}

// Run only when executed directly as a CLI script; when required as a library
// (e.g. from SetupService), the caller invokes runKriptobotMigration() itself.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    runKriptobotMigration(Database::getConnection());
}
