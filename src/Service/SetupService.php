<?php

declare(strict_types=1);

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Database\Database;
use RuntimeException;

/**
 * SetupService — first-run bootstrap for a fresh install.
 *
 * Responsibilities:
 *  - Detect whether setup is still required (admin password never set).
 *  - Auto-generate AES_MASTER_KEY into .env when missing (so non-technical
 *    users never have to touch the file).
 *  - Apply the setup wizard results (credentials + optional testnet keys).
 *
 * The setup page self-locks: once password_hash is non-empty, setupRequired()
 * returns false and the wizard refuses to run again (password changes then go
 * through Settings / CLI password:reset only).
 */
class SetupService
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * True when the admin account exists but has no password set yet.
     */
    public function setupRequired(): bool
    {
        try {
            $hash = $this->db->fetchOne(
                "SELECT password_hash FROM users WHERE id = ?", [Database::USER_ID]
            );
        } catch (\Throwable $e) {
            // No database yet — treat as "needs setup" only if the user row
            // cannot be read at all; migrate_schema creates it.
            return true;
        }
        return $hash === false || $hash === null || $hash === '';
    }

    /**
     * Ensure AES_MASTER_KEY exists. When missing, generate a fresh 32-byte
     * key and persist it to .env (base64-prefixed).
     *
     * @return array{created: bool, error: string} error '' on success.
     */
    public function ensureMasterKey(): array
    {
        if (Config::rawEnv('AES_MASTER_KEY') !== '') {
            return ['created' => false, 'error' => ''];
        }

        $key = 'base64:' . base64_encode(random_bytes(32));
        $envFile = getenv('KRIPTOBOT_ENV_FILE') ?: dirname(__DIR__, 2) . '/.env';

        try {
            if (!file_exists($envFile)) {
                $example = $envFile . '.example';
                if (file_exists($example)) {
                    if (!@copy($example, $envFile)) {
                        return ['created' => false, 'error' => 'Cannot create .env (permissions).'];
                    }
                } else {
                    if (@file_put_contents($envFile, '') === false) {
                        return ['created' => false, 'error' => 'Cannot create .env (permissions).'];
                    }
                }
            }

            $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
            $replaced = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*AES_MASTER_KEY\s*=/', $line)) {
                    $lines[$i] = 'AES_MASTER_KEY=' . $key;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $lines[] = 'AES_MASTER_KEY=' . $key;
            }

            if (@file_put_contents($envFile, implode("\n", $lines) . "\n") === false) {
                return ['created' => false, 'error' => 'Cannot write .env (permissions).'];
            }

            // Reload so this process sees the new key immediately.
            Config::reload();

            return ['created' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ensure the database schema + default user row exist (runs the standard
     * migration once when the users table is missing). Safe to call repeatedly.
     */
    public function ensureSchema(): void
    {
        $needs = false;
        try {
            $tables = array_map('strtolower', $this->db->createSchemaManager()->listTableNames());
            $needs = !in_array('users', $tables, true);
        } catch (\Throwable $e) {
            $needs = true;
        }
        if ($needs) {
            require_once dirname(__DIR__, 2) . '/bin/migrate_schema.php';
            // Silent when invoked from the web (setup.php) — CLI keeps the progress output.
            runKriptobotMigration($this->db, PHP_SAPI !== 'cli');
        }
    }

    /**
     * Apply the first-run wizard.
     *
     * @throws RuntimeException when setup was already completed or input invalid.
     */
    public function completeSetup(
        string $email,
        string $password,
        string $confirmPassword,
        string $testnetApiKey = '',
        string $testnetApiSecret = ''
    ): void {
        if (!$this->setupRequired()) {
            throw new RuntimeException('Setup has already been completed. Manage your account from Settings.');
        }

        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        if ($password !== $confirmPassword) {
            throw new RuntimeException('Passwords do not match.');
        }
        if (($testnetApiKey === '') !== ($testnetApiSecret === '')) {
            throw new RuntimeException('Testnet API key and secret must be provided together (or both left empty).');
        }

        // Make sure the schema exists even if migrate_schema.php was never run.
        $this->ensureSchema();

        // Master key must exist before we encrypt anything.
        $mk = $this->ensureMasterKey();
        if ($mk['error'] !== '') {
            throw new RuntimeException('Cannot prepare encryption key: ' . $mk['error']);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        if ($testnetApiKey !== '') {
            $enc = new \Fixzy\Kriptobot\Security\EncryptionService(Config::get('AES_MASTER_KEY'));
            $this->db->executeStatement(
                "UPDATE users SET email = ?, password_hash = ?, is_demo_mode = 1,
                 testnet_api_key = ?, testnet_api_secret_encrypted = ? WHERE id = ?",
                [$email, $hash, $testnetApiKey, $enc->encrypt($testnetApiSecret), Database::USER_ID]
            );
        } else {
            $this->db->executeStatement(
                "UPDATE users SET email = ?, password_hash = ?, is_demo_mode = 1 WHERE id = ?",
                [$email, $hash, Database::USER_ID]
            );
        }
    }
}
