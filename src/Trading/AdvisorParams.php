<?php

namespace Fixzy\Kriptobot\Trading;

/**
 * Advisor-mode parameter injection (Phase 3).
 *
 * The rule engine is NOT changed — only the SOURCE of a few parameters. When
 * the bot's effective trust level is 'advisor' (or 'active') and the cached
 * L1 decision passes every gate, the L1 params replace the matching static
 * config values for this tick; otherwise the static config stands untouched.
 *
 * Gates (all must pass, fail-safe = static params):
 *  1. mode is advisor/active;
 *  2. cache holds a validated decision with params;
 *  3. confidence gate: meets_confidence was true when cached;
 *  4. freshness: cache age <= cache_ttl_seconds;
 *  5. backtest gate: params_approved true (30h backtest not worse than static).
 *
 * RiskGuard still clamps the final values downstream (DCA cap etc.), so L1
 * can never widen hard risk limits.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class AdvisorParams
{
    /** L1 param => bot config path it tunes. entry_aggressiveness has no v1 mapping. */
    private const MAPPING = [
        'dca_drop_pct'  => ['dca', 'price_drop_trigger'],
        'max_dca_steps' => ['dca', 'max_steps'],
        'tp_pct'        => ['risk_management', 'target_profit'],
        'trail_pct'     => ['risk_management', 'trailing_tp_deviation'],
    ];

    /**
     * @param array<string, mixed>       $config bot configuration (static)
     * @param mixed                      $cache  runtime_state.ai_copilot_cache
     * @param string                     $mode   effective trust level
     * @param int                        $ttlSec ai_copilot.cache_ttl_seconds
     * @return array{config: array<string, mixed>, applied: bool, reason: string, changed: list<string>}
     */
    public static function apply(array $config, mixed $cache, string $mode, int $ttlSec, int $now): array
    {
        $reject = static function (string $reason) use ($config): array {
            return ['config' => $config, 'applied' => false, 'reason' => $reason, 'changed' => []];
        };

        if ($mode !== 'advisor' && $mode !== 'active') {
            return $reject('mode is ' . $mode);
        }
        if (!is_array($cache)) {
            return $reject('no L1 cache');
        }
        $params = $cache['params'] ?? null;
        if (!is_array($params) || $params === []) {
            return $reject('cache has no params');
        }
        if (empty($cache['meets_confidence'])) {
            return $reject('confidence below threshold when cached');
        }
        $age = $now - (int)($cache['created_at'] ?? 0);
        if ($age < 0 || $age > $ttlSec) {
            return $reject("cache stale (age {$age}s > ttl {$ttlSec}s)");
        }
        if (empty($cache['params_approved'])) {
            return $reject('backtest gate not passed');
        }

        $changed = [];
        foreach (self::MAPPING as $l1Key => $path) {
            if (!array_key_exists($l1Key, $params)) {
                continue;
            }
            $value = $l1Key === 'max_dca_steps' ? (int)$params[$l1Key] : (float)$params[$l1Key];
            if (!is_finite($value)) {
                return $reject("param {$l1Key} not finite");
            }
            $current = is_array($config[$path[0]] ?? null) ? ($config[$path[0]][$path[1]] ?? null) : null;
            if ($current !== $value) {
                if (!is_array($config[$path[0]] ?? null)) {
                    $config[$path[0]] = [];
                }
                $config[$path[0]][$path[1]] = $value;
                $changed[] = $l1Key;
            }
        }
        if ($changed === []) {
            return ['config' => $config, 'applied' => true, 'reason' => 'params already current', 'changed' => []];
        }
        return ['config' => $config, 'applied' => true, 'reason' => 'L1 params applied', 'changed' => $changed];
    }

    /**
     * The static values the gate compares against (current bot config, with the
     * same defaults the daemon uses when a key is absent).
     *
     * @param array<string, mixed> $config
     * @return array<string, float> L1-shaped param set
     */
    public static function staticParams(array $config): array
    {
        return [
            'dca_drop_pct'  => (float)($config['dca']['price_drop_trigger'] ?? 2.0),
            'max_dca_steps' => (int)($config['dca']['max_steps'] ?? 4),
            'tp_pct'        => (float)($config['risk_management']['target_profit'] ?? 2.0),
            'trail_pct'     => (float)($config['risk_management']['trailing_tp_deviation'] ?? 0.2),
        ];
    }

    /** Stable hash of the four tuned params (for verdict reuse). */
    public static function paramsHash(array $params): string
    {
        $keys = ['dca_drop_pct', 'max_dca_steps', 'tp_pct', 'trail_pct'];
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = $k . ':' . (string)($params[$k] ?? '-');
        }
        return substr(sha1(implode('|', $parts)), 0, 12);
    }
}
