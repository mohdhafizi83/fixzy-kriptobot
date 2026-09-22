<?php

declare(strict_types=1);

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Security\EncryptionService;

/**
 * SettingService — DB-backed application settings (app_settings table).
 *
 * Lets every configuration value that a user may need (Telegram, AI, webhook
 * secrets, ...) be edited from the Web UI / CLI without touching .env.
 *
 * Resolution order for Config::get():
 *   1. app_settings row (this service, DB wins)
 *   2. .env value
 *   3. Config default
 *
 * Secret values are encrypted at rest with AES_MASTER_KEY (encrypted=1).
 * The table is created lazily so a fresh/old database never breaks callers.
 */
class SettingService
{
    /**
     * Whitelist of setting keys manageable through this service.
     * 'secret' keys are encrypted at rest and masked in UI responses.
     */
    public const KEYS = [
        'TELEGRAM_BOT_TOKEN'          => 'secret',
        'TELEGRAM_CHAT_ID'           => 'plain',
        'TELEGRAM_WEBHOOK_SECRET'    => 'secret',
        'TRADINGVIEW_WEBHOOK_SECRET' => 'secret',
        'AI_API_KEY'                 => 'secret',
        'AI_BASE_URL'                => 'plain',
        'AI_MODEL'                   => 'plain',
        'CRYPTOPANIC_API_KEY'        => 'secret',
    ];

    private Connection $db;
    private ?EncryptionService $encryption = null;
    private bool $ready = false;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    private function encryption(): EncryptionService
    {
        if ($this->encryption === null) {
            $this->encryption = new EncryptionService(Config::get('AES_MASTER_KEY'));
        }
        return $this->encryption;
    }

    /**
     * Ensure the app_settings table exists (idempotent, lazy).
     */
    private function ensureTable(): void
    {
        if ($this->ready) {
            return;
        }
        $this->db->executeStatement(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                encrypted INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->ready = true;
    }

    public static function isManagedKey(string $key): bool
    {
        return isset(self::KEYS[$key]);
    }

    /**
     * Get the raw (decrypted) value of a setting, or '' when unset/empty.
     */
    public function get(string $key): string
    {
        $this->ensureTable();
        $row = $this->db->fetchAssociative(
            "SELECT value, encrypted FROM app_settings WHERE setting_key = ?",
            [$key]
        );
        if ($row === false || $row['value'] === '') {
            return '';
        }
        if ((int)$row['encrypted'] === 1) {
            try {
                return $this->encryption()->decrypt((string)$row['value']);
            } catch (\Throwable $e) {
                // Wrong/rotated master key — treat as unset rather than crash.
                return '';
            }
        }
        return (string)$row['value'];
    }

    /**
     * Set a setting. Empty string deletes the row (falls back to .env/default).
     * Passing null for a secret keeps the existing stored value (UI "unchanged").
     */
    public function set(string $key, ?string $value): void
    {
        if (!self::isManagedKey($key)) {
            throw new \InvalidArgumentException("Unmanaged setting key: {$key}");
        }
        $this->ensureTable();

        if ($value === null) {
            return; // "leave unchanged"
        }
        $value = trim($value);
        if ($value === '') {
            $this->db->delete('app_settings', ['setting_key' => $key]);
            Config::invalidateDbOverlay();
            return;
        }

        $isSecret = self::KEYS[$key] === 'secret';
        $stored = $isSecret ? $this->encryption()->encrypt($value) : $value;

        $exists = $this->db->fetchOne(
            "SELECT setting_key FROM app_settings WHERE setting_key = ?", [$key]
        );
        if ($exists !== false && $exists !== null) {
            $this->db->update('app_settings', [
                'value'       => $stored,
                'encrypted' => $isSecret ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['setting_key' => $key]);
        } else {
            $this->db->insert('app_settings', [
                'setting_key' => $key,
                'value'       => $stored,
                'encrypted'   => $isSecret ? 1 : 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
        Config::invalidateDbOverlay();
    }

    /**
     * Masked view of all managed settings for the UI.
     *
     * Secrets are never returned in full — only a short fingerprint so the user
     * can see *that* a value is set and where it came from (db vs env).
     *
     * @return array<string, array{set: bool, source: string, masked: string}>
     */
    public function maskedAll(): array
    {
        $out = [];
        foreach (array_keys(self::KEYS) as $key) {
            $dbVal = '';
            try {
                $dbVal = $this->get($key);
            } catch (DbalException $e) {
                // DB unavailable — fall through to env-only view.
            }
            $envVal = Config::rawEnv($key);

            $value = $dbVal !== '' ? $dbVal : $envVal;
            $source = $dbVal !== '' ? 'db' : ($envVal !== '' ? 'env' : 'none');
            $isSecret = self::KEYS[$key] === 'secret';

            $out[$key] = [
                'set'    => $value !== '',
                'source' => $source,
                'masked' => $value === '' ? '' : ($isSecret ? self::mask($value) : $value),
            ];
        }
        return $out;
    }

    /**
     * Mask a secret: keep first 4 and last 4 chars for recognition.
     */
    public static function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($value, 0, 4) . str_repeat('•', min(12, $len - 8)) . substr($value, -4);
    }
}
