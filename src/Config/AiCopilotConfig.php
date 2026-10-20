<?php

namespace Fixzy\Kriptobot\Config;

/**
 * AiCopilotConfig — schema, validator and normalizer for the optional
 * "ai_copilot" section inside bots.configuration (JSON).
 *
 * Hard rules (see PROJECT_BRIEF.md):
 *  - The section is OPTIONAL. A config without it behaves exactly like today:
 *    trust level "off", zero LLM calls, no behaviour change for old bots.
 *  - Everything lives in the existing JSON column — no DB migration.
 *  - normalize() is defensive (clamps to safe values); validate() is strict
 *    and used by the save path so users get a clear error instead of a
 *    silently rewritten value.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class AiCopilotConfig
{
    /** Trust ladder, lowest to highest autonomy. */
    public const TRUST_LEVELS = ['off', 'shadow', 'advisor', 'active'];

    /** Candle timeframes the slow AI loop (L1) may run on. */
    public const DECISION_TIMEFRAMES = ['15m', '1h', '4h', '1d'];

    /** Numeric bounds for every tunable parameter (clamped, never rejected at read time). */
    public const RANGES = [
        'min_confidence'            => [0.0, 1.0],
        'cache_ttl_seconds'         => [300, 86400],
        'flash_move_pct'            => [0.5, 20.0],
        'flash_move_window_min'     => [1, 120],
        'max_drawdown_pct'          => [0.5, 50.0],
    ];

    /**
     * Safe defaults. enabled=false + trust_level='off' means: identical to
     * pre-AI behaviour, no LLM call anywhere.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled'             => false,
            'trust_level'         => 'off',
            'decision_timeframe'  => '1h',
            'min_confidence'      => 0.6,
            'cache_ttl_seconds'   => 3600,
            'escalation'          => [
                'flash_move_pct'        => 3.0,
                'flash_move_window_min' => 10,
                'max_drawdown_pct'      => 5.0,
            ],
        ];
    }

    /**
     * Read the ai_copilot section out of a full bot configuration array.
     * Missing / malformed section always resolves to safe defaults (OFF).
     *
     * @param array<string, mixed> $botConfig
     * @return array<string, mixed>
     */
    public static function resolve(array $botConfig): array
    {
        return self::normalize($botConfig['ai_copilot'] ?? null);
    }

    /**
     * Defensive normalization: unknown keys are dropped, bad enums fall back
     * to 'off', numbers are clamped into RANGES. Never throws.
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::defaults();
        }

        $d = self::defaults();

        $enabled = !empty($raw['enabled']);
        $trust = in_array((string)($raw['trust_level'] ?? 'off'), self::TRUST_LEVELS, true)
            ? (string)$raw['trust_level']
            : 'off';
        // The switch is the master: disabled always means "off".
        if (!$enabled) {
            $trust = 'off';
        }

        $tf = in_array((string)($raw['decision_timeframe'] ?? ''), self::DECISION_TIMEFRAMES, true)
            ? (string)$raw['decision_timeframe']
            : $d['decision_timeframe'];

        $esc = is_array($raw['escalation'] ?? null) ? $raw['escalation'] : [];

        return [
            'enabled'            => $enabled,
            'trust_level'        => $trust,
            'decision_timeframe' => $tf,
            'min_confidence'     => self::clamp($raw['min_confidence'] ?? null, 'min_confidence', $d['min_confidence']),
            'cache_ttl_seconds'  => (int)self::clamp($raw['cache_ttl_seconds'] ?? null, 'cache_ttl_seconds', $d['cache_ttl_seconds']),
            'escalation'         => [
                'flash_move_pct'        => self::clamp($esc['flash_move_pct'] ?? null, 'flash_move_pct', $d['escalation']['flash_move_pct']),
                'flash_move_window_min' => (int)self::clamp($esc['flash_move_window_min'] ?? null, 'flash_move_window_min', $d['escalation']['flash_move_window_min']),
                'max_drawdown_pct'      => self::clamp($esc['max_drawdown_pct'] ?? null, 'max_drawdown_pct', $d['escalation']['max_drawdown_pct']),
            ],
        ];
    }

    /**
     * Strict validation for the save path (Web UI / REST API).
     * Returns a list of human-readable errors; empty array = valid.
     *
     * @return array<int, string>
     */
    public static function validate(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            return ['ai_copilot must be a JSON object.'];
        }

        $errors = [];
        $d = self::defaults();

        if (isset($raw['enabled']) && !is_bool($raw['enabled']) && !is_numeric($raw['enabled'])) {
            $errors[] = 'ai_copilot.enabled must be a boolean.';
        }

        $trust = $raw['trust_level'] ?? 'off';
        if (!in_array($trust, self::TRUST_LEVELS, true)) {
            $errors[] = 'ai_copilot.trust_level must be one of: ' . implode(', ', self::TRUST_LEVELS) . '.';
        }

        if (!empty($raw['enabled']) && $trust === 'off') {
            $errors[] = 'ai_copilot is enabled but trust_level is "off" — pick a trust level (shadow, advisor, active) or disable it.';
        }

        $tf = $raw['decision_timeframe'] ?? $d['decision_timeframe'];
        if (!in_array($tf, self::DECISION_TIMEFRAMES, true)) {
            $errors[] = 'ai_copilot.decision_timeframe must be one of: ' . implode(', ', self::DECISION_TIMEFRAMES) . '.';
        }

        foreach (['min_confidence', 'cache_ttl_seconds'] as $key) {
            if (array_key_exists($key, $raw)) {
                if (!is_numeric($raw[$key])) {
                    $errors[] = "ai_copilot.{$key} must be a number.";
                } else {
                    [$lo, $hi] = self::RANGES[$key];
                    if ((float)$raw[$key] < $lo || (float)$raw[$key] > $hi) {
                        $errors[] = "ai_copilot.{$key} must be between {$lo} and {$hi}.";
                    }
                }
            }
        }

        if (array_key_exists('escalation', $raw)) {
            if (!is_array($raw['escalation'])) {
                $errors[] = 'ai_copilot.escalation must be a JSON object.';
            } else {
                foreach (['flash_move_pct', 'flash_move_window_min', 'max_drawdown_pct'] as $key) {
                    if (array_key_exists($key, $raw['escalation'])) {
                        if (!is_numeric($raw['escalation'][$key])) {
                            $errors[] = "ai_copilot.escalation.{$key} must be a number.";
                        } else {
                            [$lo, $hi] = self::RANGES[$key];
                            if ((float)$raw['escalation'][$key] < $lo || (float)$raw['escalation'][$key] > $hi) {
                                $errors[] = "ai_copilot.escalation.{$key} must be between {$lo} and {$hi}.";
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Resolve the trust level the daemon should actually honour, given the
     * global agent switch (agent_settings.agent_enabled) and whether the AI
     * feature is configured + verified. Any unmet prerequisite degrades to
     * 'off' — fail-safe by design.
     */
    public static function effectiveTrustLevel(array $aiCopilot, bool $agentEnabled, bool $aiFeatureEnabled): string
    {
        $trust = (string)($aiCopilot['trust_level'] ?? 'off');
        if ($trust === 'off' || empty($aiCopilot['enabled'])) {
            return 'off';
        }
        if (!$agentEnabled || !$aiFeatureEnabled) {
            return 'off';
        }
        return $trust;
    }

    /**
     * Clamp a numeric value into its declared range; non-numeric uses $fallback.
     */
    private static function clamp(mixed $value, string $key, float|int $fallback): float
    {
        [$lo, $hi] = self::RANGES[$key];
        if (!is_numeric($value)) {
            $value = $fallback;
        }
        return round(min($hi, max($lo, (float)$value)), 4);
    }
}
