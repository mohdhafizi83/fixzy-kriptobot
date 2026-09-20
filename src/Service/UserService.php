<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Security\EncryptionService;
use RuntimeException;

/**
 * UserService — backend-owned access to the users table (profile, environment,
 * global filters, recovery mode) and user_api_keys.
 */
class UserService
{
    private Connection $db;
    private EncryptionService $encryption;

    public function __construct(?Connection $db = null, ?EncryptionService $encryption = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->encryption = $encryption ?? new EncryptionService(
            \Fixzy\Kriptobot\Config\Config::get('AES_MASTER_KEY')
        );
    }

    /**
     * Profile fields for display (no secrets).
     */
    public function getProfile(int $userId): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT email, telegram_chat_id FROM users WHERE id = ?",
            [$userId]
        );
        if (!$row) {
            throw new RuntimeException('User not found.');
        }
        return $row;
    }

    /**
     * Update profile (email, optional password, telegram chat id).
     *
     * @return array{email: string, telegram_chat_id: string}
     */
    public function updateProfile(int $userId, string $email, string $password, string $telegramChatId): array
    {
        $email = trim($email);
        if ($email === '') {
            throw new RuntimeException('Email cannot be left empty.');
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->db->executeStatement(
                "UPDATE users SET email = ?, password_hash = ?, telegram_chat_id = ? WHERE id = ?",
                [$email, $hash, $telegramChatId, $userId]
            );
        } else {
            $this->db->executeStatement(
                "UPDATE users SET email = ?, telegram_chat_id = ? WHERE id = ?",
                [$email, $telegramChatId, $userId]
            );
        }

        return ['email' => $email, 'telegram_chat_id' => $telegramChatId];
    }

    /**
     * Environment settings: demo (testnet) mode + testnet API keys.
     *
     * @return array{is_demo_mode: bool, testnet_api_key: string}
     */
    public function getEnvironment(int $userId): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT is_demo_mode, testnet_api_key FROM users WHERE id = ?",
            [$userId]
        );
        if (!$row) {
            throw new RuntimeException('User not found.');
        }
        return [
            'is_demo_mode'    => (bool)$row['is_demo_mode'],
            'testnet_api_key' => (string)($row['testnet_api_key'] ?? ''),
        ];
    }

    /**
     * Save environment settings. Secret is only replaced when non-empty.
     */
    public function updateEnvironment(int $userId, bool $isDemo, string $testnetApiKey, string $testnetApiSecret): array
    {
        if ($testnetApiSecret !== '') {
            $encrypted = $this->encryption->encrypt($testnetApiSecret);
            $this->db->executeStatement(
                "UPDATE users SET is_demo_mode = ?, testnet_api_key = ?, testnet_api_secret_encrypted = ? WHERE id = ?",
                [$isDemo ? 1 : 0, $testnetApiKey, $encrypted, $userId]
            );
        } else {
            $this->db->executeStatement(
                "UPDATE users SET is_demo_mode = ?, testnet_api_key = ? WHERE id = ?",
                [$isDemo ? 1 : 0, $testnetApiKey, $userId]
            );
        }
        return ['is_demo_mode' => $isDemo, 'testnet_api_key' => $testnetApiKey];
    }

    /**
     * Global filters (JSON string as stored).
     */
    public function getGlobalFilters(int $userId): ?string
    {
        $val = $this->db->fetchOne("SELECT global_filters FROM users WHERE id = ?", [$userId]);
        return $val !== null && $val !== false ? (string)$val : null;
    }

    public function updateGlobalFilters(int $userId, string $filtersJson): void
    {
        // Validate JSON before persisting.
        json_decode($filtersJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON payload for global filters.');
        }
        $this->db->executeStatement(
            "UPDATE users SET global_filters = ? WHERE id = ?",
            [$filtersJson, $userId]
        );
    }

    public function getSmartRecoveryMode(int $userId): bool
    {
        return (bool)$this->db->fetchOne("SELECT smart_recovery_mode FROM users WHERE id = ?", [$userId]);
    }

    public function updateSmartRecoveryMode(int $userId, bool $enabled): void
    {
        $this->db->executeStatement(
            "UPDATE users SET smart_recovery_mode = ? WHERE id = ?",
            [$enabled ? 1 : 0, $userId]
        );
    }

    /**
     * Live exchange API keys for the user (secrets never returned).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listApiKeys(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, exchange_name, api_key FROM user_api_keys WHERE user_id = ? ORDER BY id DESC",
            [$userId]
        );
    }

    /**
     * Distinct exchange names that have a usable key (for editor dropdowns).
     *
     * @return array<int, string>
     */
    public function listAvailableExchanges(int $userId): array
    {
        return $this->db->fetchFirstColumn(
            "SELECT DISTINCT exchange_name FROM user_api_keys WHERE user_id = ? AND api_key IS NOT NULL AND api_key != ''",
            [$userId]
        );
    }

    /**
     * Add a live exchange API key (secret encrypted at rest).
     */
    public function addApiKey(int $userId, string $exchangeName, string $apiKey, string $apiSecret): int
    {
        $this->db->insert('user_api_keys', [
            'user_id'             => $userId,
            'exchange_name'       => $exchangeName,
            'api_key'             => $apiKey,
            'api_secret_encrypted' => $this->encryption->encrypt($apiSecret),
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Resolve the exchange credentials for the user's current environment.
     * Mirrors the routing logic the dashboard uses:
     *   demo mode  -> users.testnet_* (binance testnet)
     *   live mode  -> first row of user_api_keys
     *
     * @return array{exchange: string, api_key: string, api_secret: string, is_testnet: bool}
     */
    public function resolveExchangeCredentials(int $userId): array
    {
        $user = $this->db->fetchAssociative(
            "SELECT is_demo_mode, testnet_api_key, testnet_api_secret_encrypted FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $isTestnet = (bool)$user['is_demo_mode'];

        if ($isTestnet) {
            if (empty($user['testnet_api_key']) || empty($user['testnet_api_secret_encrypted'])) {
                throw new RuntimeException('Please configure your Testnet API keys first (Settings > Environment).');
            }
            return [
                'exchange'   => 'binance',
                'api_key'    => trim((string)$user['testnet_api_key']),
                'api_secret' => trim($this->decryptWithLegacyFallback((string)$user['testnet_api_secret_encrypted'])),
                'is_testnet' => true,
            ];
        }

        $key = $this->db->fetchAssociative(
            "SELECT exchange_name, api_key, api_secret_encrypted FROM user_api_keys WHERE user_id = ? ORDER BY id ASC LIMIT 1",
            [$userId]
        );
        if (!$key) {
            throw new RuntimeException('No live exchange API key connected. Add one first.');
        }
        return [
            'exchange'   => (string)$key['exchange_name'],
            'api_key'    => trim((string)$key['api_key']),
            'api_secret' => trim($this->decryptWithLegacyFallback((string)$key['api_secret_encrypted'])),
            'is_testnet' => false,
        ];
    }

    /**
     * Decrypt with the legacy base64 fallback for pre-encryption rows.
     * (Same behavior the dashboard used; kept here so pages do not need it.)
     */
    public function decryptWithLegacyFallback(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        try {
            return $this->encryption->decrypt($encoded);
        } catch (\Exception $e) {
            return trim(base64_decode($encoded));
        }
    }

    /**
     * Test connectivity of a stored API key against the exchange.
     *
     * @return array{connected: bool, message: string}
     */
    public function testApiKeyConnection(int $userId, int $apiId): array
    {
        $keyData = $this->db->fetchAssociative(
            "SELECT exchange_name, api_key, api_secret_encrypted FROM user_api_keys WHERE id = ? AND user_id = ?",
            [$apiId, $userId]
        );
        if (!$keyData) {
            return ['connected' => false, 'message' => 'API key not found.'];
        }

        try {
            $ex = new \Fixzy\Kriptobot\Trading\ExchangeService(
                (string)$keyData['exchange_name'],
                (string)$keyData['api_key'],
                $this->decryptWithLegacyFallback((string)$keyData['api_secret_encrypted']),
                false
            );
            $ex->getExchange()->fetch_balance();
            return ['connected' => true, 'message' => 'Connection successful.'];
        } catch (\Throwable $e) {
            return ['connected' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }
}
