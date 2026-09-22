<?php
namespace Fixzy\Kriptobot\Config;

/**
 * Application configuration — single source of truth.
 * Loads the .env file once. All access goes through Config::get().
 */
class Config
{
    private static bool $loaded = false;
    private static array $env = [];

    private const DEFAULTS = [
        'APP_ENV'                  => 'development',
        'AES_MASTER_KEY'           => '',
        'TELEGRAM_BOT_TOKEN'       => '',
        'TELEGRAM_CHAT_ID'         => '',
        'AI_API_KEY'               => '',
        'AI_BASE_URL'              => '',
        'AI_MODEL'                 => '',
        'CRYPTOPANIC_API_KEY'      => '',
    ];

    public static function load(): void
    {
        if (self::$loaded) return;
        self::doLoad();
        self::$loaded = true;
    }

    /**
     * Force re-reading the .env file (after it was modified at runtime).
     */
    public static function reload(): void
    {
        self::$env = [];
        self::doLoad();
        self::$loaded = true;
    }

    private static function doLoad(): void
    {
        // KRIPTOBOT_ENV_FILE overrides the .env location (used by tests).
        $envFile = getenv('KRIPTOBOT_ENV_FILE') ?: dirname(__DIR__, 2) . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $eq = strpos($line, '=');
                if ($eq === false) continue;
                $key = trim(substr($line, 0, $eq));
                $val = trim(substr($line, $eq + 1));
                // Strip surrounding quotes
                if (strlen($val) >= 2 && (
                    ($val[0] === '"' && $val[-1] === '"') ||
                    ($val[0] === "'" && $val[-1] === "'")
                )) {
                    $val = substr($val, 1, -1);
                }
                self::$env[$key] = $val;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        self::load();

        // DB overlay: user-managed settings (set via Web UI / CLI setup) win
        // over .env. Guarded so a missing/broken database never breaks config.
        if (\Fixzy\Kriptobot\Service\SettingService::isManagedKey($key)) {
            $dbVal = self::dbOverlay($key);
            if ($dbVal !== null && $dbVal !== '') {
                return $dbVal;
            }
        }

        return self::$env[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    /**
     * Read the raw .env value only (no DB overlay). Used by the settings UI to
     * show where a value comes from.
     */
    public static function rawEnv(string $key): string
    {
        self::load();
        return self::$env[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    private static ?bool $dbOverlayAvailable = null;
    private static ?array $dbOverlayCache = null;

    /**
     * Load all managed settings from the database once per process.
     * Returns null when the database is unavailable.
     */
    private static function dbOverlayMap(): ?array
    {
        if (self::$dbOverlayCache !== null) {
            return self::$dbOverlayCache;
        }
        if (self::$dbOverlayAvailable === false) {
            return null;
        }
        try {
            $svc = new \Fixzy\Kriptobot\Service\SettingService();
            $map = [];
            foreach (array_keys(\Fixzy\Kriptobot\Service\SettingService::KEYS) as $k) {
                $map[$k] = $svc->get($k);
            }
            self::$dbOverlayAvailable = true;
            self::$dbOverlayCache = $map;
            return $map;
        } catch (\Throwable $e) {
            self::$dbOverlayAvailable = false;
            return null;
        }
    }

    /**
     * Invalidate the DB settings overlay cache (call after writes).
     */
    public static function invalidateDbOverlay(): void
    {
        self::$dbOverlayCache = null;
    }

    /**
     * Fetch a managed setting from the database, or null when unavailable.
     */
    private static function dbOverlay(string $key): ?string
    {
        $map = self::dbOverlayMap();
        return $map[$key] ?? null;
    }

    /**
     * Resolve the OpenAI-compatible LLM settings.
     *
     * Any provider that speaks the OpenAI chat-completions format works
     * (DeepSeek, OpenRouter, OpenAI, Groq, Ollama, vLLM, LM Studio, ...).
     * AI is considered configured only when both AI_API_KEY and AI_BASE_URL
     * are set; otherwise AI features stay disabled.
     *
     * @return array{api_key: string, base_url: string, model: string}
     */
    public static function aiConfig(): array
    {
        $apiKey  = self::get('AI_API_KEY');
        $baseUrl = self::get('AI_BASE_URL');
        $model   = self::get('AI_MODEL');

        // Normalize: ensure trailing slash so callers can append 'chat/completions'.
        if ($baseUrl !== '' && !str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }

        return ['api_key' => $apiKey, 'base_url' => $baseUrl, 'model' => $model];
    }

    public static function isDebug(): bool
    {
        return self::get('APP_ENV') === 'development';
    }
}
