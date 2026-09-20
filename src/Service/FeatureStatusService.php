<?php

namespace Fixzy\Kriptobot\Service;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Database\Database;

/**
 * FeatureStatusService — central registry of features that depend on external
 * APIs/tokens.
 *
 * Policy (user decision 2026-09-20):
 *   - The exchange API is COMPULSORY: without a verified exchange connection
 *     the bot cannot trade at all.
 *   - All other integrations (AI, CryptoPanic news, Telegram) are OPTIONAL:
 *     they stay DISABLED until their credentials are provided AND a live
 *     verification call confirms they work.
 *   - Every disabled feature carries a human-readable reason that is surfaced
 *     in the UI and daemon notifications.
 *
 * Statuses:
 *   enabled   — configured AND verified working (last verification passed)
 *   disabled  — not configured, or verification failed (see `reason`)
 */
class FeatureStatusService
{
    public const FEATURE_EXCHANGE     = 'exchange';
    public const FEATURE_AI           = 'ai';
    public const FEATURE_CRYPTOPANIC  = 'cryptopanic';
    public const FEATURE_TELEGRAM     = 'telegram';

    /** feature_key => [label, required, description] */
    public const REGISTRY = [
        self::FEATURE_EXCHANGE => [
            'label'      => 'Exchange API',
            'required'   => true,
            'hint'       => 'Binance (testnet or live) API credentials. Required for all trading.',
        ],
        self::FEATURE_AI => [
            'label'      => 'AI Analysis (OpenAI-compatible)',
            'required'   => false,
            'hint'       => 'AI market analysis (ai_market conditions) and news sentiment. Needs AI_API_KEY, AI_BASE_URL, AI_MODEL in .env.',
        ],
        self::FEATURE_CRYPTOPANIC => [
            'label'      => 'CryptoPanic News Feed',
            'required'   => false,
            'hint'       => 'News source used by AI sentiment analysis. Needs CRYPTOPANIC_API_KEY in .env.',
        ],
        self::FEATURE_TELEGRAM => [
            'label'      => 'Telegram Notifications',
            'required'   => false,
            'hint'       => 'Trade alerts and agent notifications. Needs TELEGRAM_BOT_TOKEN in .env and a chat id in your profile.',
        ],
    ];

    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        $this->db->executeStatement(
            "CREATE TABLE IF NOT EXISTS feature_status (
                feature_key TEXT PRIMARY KEY,
                status TEXT NOT NULL DEFAULT 'disabled',
                reason TEXT NOT NULL DEFAULT '',
                last_checked_at TEXT DEFAULT NULL,
                last_verified_at TEXT DEFAULT NULL
            )"
        );
    }

    /**
     * All feature statuses merged over the registry (unknown/missing rows = disabled).
     *
     * @return array<string, array{key:string,label:string,required:bool,hint:string,status:string,reason:string,last_checked_at:?string,last_verified_at:?string}>
     */
    public function getAll(): array
    {
        $rows = [];
        foreach ($this->db->fetchAllAssociative("SELECT * FROM feature_status") as $r) {
            $rows[$r['feature_key']] = $r;
        }

        $out = [];
        foreach (self::REGISTRY as $key => $meta) {
            $row = $rows[$key] ?? null;
            $out[$key] = [
                'key'              => $key,
                'label'            => $meta['label'],
                'required'         => $meta['required'],
                'hint'             => $meta['hint'],
                'status'           => $row['status'] ?? 'disabled',
                'reason'           => $row['reason'] ?? ($meta['required']
                    ? 'Not configured or not verified yet. This feature is compulsory.'
                    : 'Not configured or not verified yet. The feature stays disabled until its credentials are added and verified.'),
                'last_checked_at'  => $row['last_checked_at'] ?? null,
                'last_verified_at' => $row['last_verified_at'] ?? null,
            ];
        }
        return $out;
    }

    public function isEnabled(string $feature): bool
    {
        $row = $this->db->fetchAssociative(
            "SELECT status FROM feature_status WHERE feature_key = ?",
            [$feature]
        );
        return ($row['status'] ?? 'disabled') === 'enabled';
    }

    public function getStatus(string $feature): array
    {
        return $this->getAll()[$feature] ?? [
            'key' => $feature, 'label' => $feature, 'required' => false,
            'status' => 'disabled', 'reason' => 'Unknown feature.',
            'hint' => '', 'last_checked_at' => null, 'last_verified_at' => null,
        ];
    }

    private function save(string $feature, bool $ok, string $reason): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->db->fetchAssociative(
            "SELECT feature_key FROM feature_status WHERE feature_key = ?", [$feature]
        );
        if ($exists) {
            $this->db->executeStatement(
                "UPDATE feature_status SET status = ?, reason = ?, last_checked_at = ?" .
                ($ok ? ", last_verified_at = ?" : "") . " WHERE feature_key = ?",
                $ok
                    ? ['enabled', $reason, $now, $now, $feature]
                    : ['disabled', $reason, $now, $feature]
            );
        } else {
            $this->db->executeStatement(
                "INSERT INTO feature_status (feature_key, status, reason, last_checked_at, last_verified_at) VALUES (?, ?, ?, ?, ?)",
                [$feature, $ok ? 'enabled' : 'disabled', $reason, $now, $ok ? $now : null]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verification routines — each performs a REAL call to confirm the
    // integration works, then persists the result.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verify the exchange connection the daemon would actually use.
     */
    public function verifyExchange(int $userId, bool $isTestnet, string $exchangeName, string $apiKey, string $apiSecret): bool
    {
        if ($apiKey === '') {
            $this->save(
                self::FEATURE_EXCHANGE,
                false,
                $isTestnet
                    ? 'Testnet API key is empty. Add it in Settings → Environment (Demo Mode).'
                    : 'Live API key for exchange "' . strtoupper($exchangeName) . '" is missing. Add it in Settings → API Keys.'
            );
            return false;
        }

        try {
            $ex = new \Fixzy\Kriptobot\Trading\ExchangeService($exchangeName, $apiKey, $apiSecret, $isTestnet);
            $ex->getExchange()->fetch_time(); // lightweight, validates credentials + connectivity
            $mode = $isTestnet ? 'testnet' : 'live';
            $this->save(self::FEATURE_EXCHANGE, true, "Verified: {$exchangeName} ({$mode}) connection OK.");
            return true;
        } catch (\Throwable $e) {
            $this->save(self::FEATURE_EXCHANGE, false, 'Connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify the AI provider: try GET {base}/models first (cheap, no tokens);
     * fall back to a 1-token chat completion if /models is unsupported.
     */
    public function verifyAi(array $aiCfg): bool
    {
        $apiKey  = (string)($aiCfg['api_key'] ?? '');
        $baseUrl = rtrim((string)($aiCfg['base_url'] ?? ''), '/');
        $model   = (string)($aiCfg['model'] ?? '');

        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            $missing = [];
            if ($apiKey === '')  $missing[] = 'AI_API_KEY';
            if ($baseUrl === '') $missing[] = 'AI_BASE_URL';
            if ($model === '')   $missing[] = 'AI_MODEL';
            $this->save(self::FEATURE_AI, false, 'Not configured: missing ' . implode(', ', $missing) . ' in .env.');
            return false;
        }

        $client = new \GuzzleHttp\Client(['timeout' => 15]);
        try {
            $res = $client->get($baseUrl . '/models', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            ]);
            $data = json_decode((string)$res->getBody(), true);
            if (is_array($data) && (isset($data['data']) || isset($data['models']))) {
                $this->save(self::FEATURE_AI, true, "Verified: provider reachable, model '{$model}' selected.");
                return true;
            }
            throw new \Exception('Unexpected /models response shape.');
        } catch (\Throwable $modelsErr) {
            // Fallback: minimal chat completion (1 token) to prove chat endpoint + key + model.
            try {
                $res = $client->post($baseUrl . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'      => $model,
                        'messages'   => [['role' => 'user', 'content' => 'ping']],
                        'max_tokens' => 1,
                    ],
                ]);
                $data = json_decode((string)$res->getBody(), true);
                if (is_array($data) && isset($data['choices'])) {
                    $this->save(self::FEATURE_AI, true, "Verified: chat endpoint OK, model '{$model}' responding.");
                    return true;
                }
                throw new \Exception('Unexpected chat response shape.');
            } catch (\Throwable $chatErr) {
                $this->save(
                    self::FEATURE_AI,
                    false,
                    'Verification failed (' . $modelsErr->getMessage() . ' / ' . $chatErr->getMessage() . '). Check AI_API_KEY, AI_BASE_URL and AI_MODEL.'
                );
                return false;
            }
        }
    }

    public function verifyCryptopanic(string $apiKey): bool
    {
        if ($apiKey === '') {
            $this->save(self::FEATURE_CRYPTOPANIC, false, 'Not configured: CRYPTOPANIC_API_KEY is empty in .env.');
            return false;
        }
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $res = $client->get('https://cryptopanic.com/api/v1/posts/', [
                'query'   => ['auth_token' => $apiKey, 'public' => 'true'],
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($res->getStatusCode() === 200) {
                $this->save(self::FEATURE_CRYPTOPANIC, true, 'Verified: CryptoPanic API responding.');
                return true;
            }
            throw new \Exception('HTTP ' . $res->getStatusCode());
        } catch (\Throwable $e) {
            $this->save(self::FEATURE_CRYPTOPANIC, false, 'Verification failed: ' . $e->getMessage() . '. Check CRYPTOPANIC_API_KEY.');
            return false;
        }
    }

    public function verifyTelegram(string $botToken, ?string $chatId): bool
    {
        if ($botToken === '') {
            $this->save(self::FEATURE_TELEGRAM, false, 'Not configured: TELEGRAM_BOT_TOKEN is empty in .env.');
            return false;
        }
        if ($chatId === null || $chatId === '') {
            $this->save(self::FEATURE_TELEGRAM, false, 'Bot token set but no chat id linked. Send /start to your bot and set the chat id in your profile.');
            return false;
        }
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $res = $client->get("https://api.telegram.org/bot{$botToken}/getMe");
            $data = json_decode((string)$res->getBody(), true);
            if (!empty($data['ok'])) {
                $this->save(self::FEATURE_TELEGRAM, true, 'Verified: bot @' . ($data['result']['username'] ?? '?') . ' reachable.');
                return true;
            }
            throw new \Exception(($data['description'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            $this->save(self::FEATURE_TELEGRAM, false, 'Verification failed: ' . $e->getMessage() . '. Check TELEGRAM_BOT_TOKEN.');
            return false;
        }
    }

    /**
     * Run every verification and return the fresh status list.
     */
    public function verifyAll(int $userId): array
    {
        $user = $this->db->fetchAssociative(
            "SELECT telegram_chat_id FROM users WHERE id = ?",
            [$userId]
        ) ?: [];

        // Exchange: resolve credentials the same way the dashboard does
        // (demo mode -> testnet keys, live mode -> user_api_keys).
        try {
            $creds = (new UserService($this->db))->resolveExchangeCredentials($userId);
            $this->verifyExchange(
                $userId,
                (bool)$creds['is_testnet'],
                (string)$creds['exchange'],
                (string)$creds['api_key'],
                (string)$creds['api_secret']
            );
        } catch (\Throwable $e) {
            $this->save(self::FEATURE_EXCHANGE, false, $e->getMessage());
        }

        $this->verifyAi(Config::aiConfig());
        $this->verifyCryptopanic(Config::get('CRYPTOPANIC_API_KEY'));
        $this->verifyTelegram(Config::get('TELEGRAM_BOT_TOKEN'), $user['telegram_chat_id'] ?? null);

        return $this->getAll();
    }
}
