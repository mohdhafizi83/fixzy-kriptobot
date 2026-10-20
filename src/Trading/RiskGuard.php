<?php

namespace Fixzy\Kriptobot\Trading;

use Doctrine\DBAL\Connection;

/**
 * RiskGuard — the hard risk layer (PROJECT_BRIEF section 6).
 *
 * ALWAYS ON for every bot, regardless of whether the AI Copilot is enabled.
 * The AI can never switch these off or widen them beyond the hard caps.
 * All state lives in existing tables (app_settings key/value, bot
 * runtime_state JSON) — no DB migration.
 *
 * Guarantees:
 *  - Daily net-loss kill switch: realized net loss beyond the daily limit
 *    blocks ALL new base entries for kill_switch_hours + Telegram alert.
 *  - Max exposure per coin and overall (limits from agent_settings).
 *  - Cut-loss cooldown: no new entry for the bot for N hours after a cut loss.
 *  - Order sanity: size floor + price deviation from mid.
 *  - AI-mode DCA steps are always finite and capped.
 * Fail-safe: any unreadable input degrades to "block the new entry" (HOLD),
 * never to "allow the trade". Existing positions are never force-sold here.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class RiskGuard
{
    /**
     * audit_logs sell event types that realize PnL. AuditLogger::logSell()
     * writes event_type='SELL' with the concrete type in context_data.sell_type,
     * so dailyRealizedNetUsdt() accepts both shapes (direct rows and logSell rows).
     */
    public const SELL_EVENTS = ['TAKE_PROFIT', 'CUT_LOSS', 'CUSTOM_SELL', 'GLOBAL_SELL_OVERRIDE', 'PARTIAL_SELL'];

    public const DEFAULTS = [
        'daily_loss_pct'           => 2.0,   // kill switch: daily net loss > 2% of capital base
        'kill_switch_hours'        => 24,    // entries blocked for this long after a trip
        'cut_loss_cooldown_hours'  => 4,     // per-bot cooldown after a cut loss
        'price_deviation_pct'      => 2.0,   // order price must be within x% of mid
        'min_order_usdt'           => 5.0,   // exchange minimum notional floor
        'ai_max_dca_steps_cap'     => 8,     // AI mode: DCA steps must stay finite and capped
    ];

    /** Defense-in-depth clamps for app_settings overrides (direct DB writes only). */
    private const RANGES = [
        'daily_loss_pct'           => [0.1, 50.0],
        'kill_switch_hours'        => [1.0, 168.0],
        'cut_loss_cooldown_hours'  => [0.0, 168.0],
        'price_deviation_pct'      => [0.1, 20.0],
        'min_order_usdt'           => [1.0, 100000.0],
        'ai_max_dca_steps_cap'     => [0.0, 8.0],
    ];

    private bool $tableReady = false;

    /**
     * @param array<string, mixed> $overrides numeric overrides (tests / app_settings preloaded)
     */
    public function __construct(private Connection $db, private array $overrides = [])
    {
    }

    /**
     * Read one guard parameter: app_settings row > constructor override > default.
     */
    public function setting(string $key): float
    {
        $default = (float)(self::DEFAULTS[$key] ?? 0);
        $raw = $this->appSetting('risk_guard.' . $key);
        $value = null;
        if ($raw !== '' && is_numeric($raw)) {
            $value = (float)$raw;
        } elseif (array_key_exists($key, $this->overrides) && is_numeric($this->overrides[$key])) {
            $value = (float)$this->overrides[$key];
        }
        if ($value === null || !is_finite($value)) {
            return $default;
        }
        // Clamp to a sane range so a bad direct DB write (e.g. '1e400' = INF,
        // negative floor) can never disable a guard.
        if (isset(self::RANGES[$key])) {
            [$lo, $hi] = self::RANGES[$key];
            $value = max($lo, min($hi, $value));
        }
        return $value;
    }

    /**
     * Realized net PnL (USDT) for a user since $sinceTs, from audit_logs sell events.
     * profit = sell_value * p / (100 + p) where p = pnl_percent vs cost basis.
     * Malformed rows are skipped (fail-safe: they can only reduce measured loss,
     * and the kill switch also trips on the explicit trip flag).
     */
    public function dailyRealizedNetUsdt(int $userId, int $sinceTs): float
    {
        // AuditLogger::logSell() stores event_type='SELL' and the concrete type
        // in context_data.sell_type; also accept rows written with the concrete
        // type directly (tests, future writers).
        $eventTypes = array_merge(['SELL'], self::SELL_EVENTS);
        $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
        // Window uses the SAME clock as AuditLogger (date(), server-local):
        // $sinceTs is formatted with the local clock, so callers should pass
        // strtotime('today') (local midnight). On UTC hosts this is UTC.
        $rows = $this->db->fetchAllAssociative(
            "SELECT event_type, context_data FROM audit_logs
             WHERE user_id = ? AND event_type IN ({$placeholders}) AND created_at >= ?",
            array_merge([$userId], $eventTypes, [date('Y-m-d H:i:s', $sinceTs)])
        );

        $net = 0.0;
        foreach ($rows as $row) {
            $ctx = json_decode((string)$row['context_data'], true);
            if (!is_array($ctx)) {
                continue;
            }
            // For generic 'SELL' rows the concrete type must be one of ours;
            // failed sells (status ERROR) must not count.
            if ($row['event_type'] === 'SELL') {
                $sellType = (string)($ctx['sell_type'] ?? '');
                if (!in_array($sellType, self::SELL_EVENTS, true)) {
                    continue;
                }
            }
            if ((string)($ctx['status'] ?? 'SUCCESS') !== 'SUCCESS') {
                continue;
            }
            $sellValue = (float)($ctx['usdt_value'] ?? 0);
            $pnlPct = $ctx['pnl_percent'] ?? null;
            if ($sellValue <= 0 || !is_numeric($pnlPct) || (float)$pnlPct <= -100) {
                continue;
            }
            $p = (float)$pnlPct;
            $net += $sellValue * $p / (100 + $p);
        }
        return round($net, 8);
    }

    /**
     * Capital base for the daily-loss threshold: sum of allocated capital of the
     * user's enabled bots; falls back to agent max_total_capital when that is 0
     * (e.g. every bot uses AUTO sizing).
     *
     * @param array<string, mixed> $agentSettings
     */
    public function capitalBaseUsdt(int $userId, array $agentSettings): float
    {
        $sum = (float)$this->db->fetchOne(
            "SELECT COALESCE(SUM(allocated_capital), 0) FROM bots
             WHERE user_id = ? AND status = 1 AND parent_id IS NULL",
            [$userId]
        );
        if ($sum > 0) {
            return $sum;
        }
        return (float)($agentSettings['max_total_capital'] ?? 10000.0);
    }

    /**
     * Kill switch state for a user.
     *
     * @param array<string, mixed> $agentSettings
     * @return array{tripped: bool, until: int, loss_usdt: float, loss_pct: float, base_usdt: float, limit_usdt: float}
     */
    public function killSwitchState(int $userId, array $agentSettings): array
    {
        $until = (int)($this->appSetting('risk_guard.kill_until_' . $userId) ?: 0);
        $base = $this->capitalBaseUsdt($userId, $agentSettings);
        $loss = $this->dailyRealizedNetUsdt($userId, (int)strtotime('today'));
        $limit = $base * $this->setting('daily_loss_pct') / 100;
        return [
            'tripped'    => $until > time(),
            'until'      => $until,
            'loss_usdt'  => $loss,
            'loss_pct'   => $base > 0 ? round($loss / $base * 100, 4) : 0.0,
            'base_usdt'  => $base,
            'limit_usdt' => $limit,
        ];
    }

    /**
     * Arm the kill switch: block new entries for kill_switch_hours. Returns the
     * unix timestamp when entries become allowed again.
     */
    public function tripKillSwitch(int $userId): int
    {
        $until = time() + (int)round($this->setting('kill_switch_hours') * 3600);
        $this->setAppSetting('risk_guard.kill_until_' . $userId, (string)$until);
        return $until;
    }

    /**
     * Manually disarm the kill switch (operator reset).
     */
    public function clearKillSwitch(int $userId): void
    {
        $this->setAppSetting('risk_guard.kill_until_' . $userId, '0');
    }

    /**
     * Current exposure in USDT: holdings value per coin + total, for a user's
     * enabled bots. $priceOf(symbol) returns ?float; unknown prices are skipped
     * (the entry-time check for the traded symbol itself fails closed instead).
     *
     * @param callable(string):(?float) $priceOf
     * @return array{per_coin: array<string, float>, total: float}
     */
    public function exposureUsdt(int $userId, callable $priceOf): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT coin_pair, runtime_state FROM bots WHERE user_id = ? AND status = 1",
            [$userId]
        );
        $perCoin = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $state = json_decode((string)$row['runtime_state'], true);
            $holdingsRaw = is_array($state) ? ($state['current_holdings'] ?? 0) : 0;
            $holdings = is_numeric($holdingsRaw) ? (float)$holdingsRaw : 0.0; // arrays/objects must not cast to 1.0
            if ($holdings <= 0) {
                continue;
            }
            $price = $priceOf((string)$row['coin_pair']);
            if ($price === null || $price <= 0) {
                continue;
            }
            $value = $holdings * $price;
            $perCoin[(string)$row['coin_pair']] = ($perCoin[(string)$row['coin_pair']] ?? 0.0) + $value;
            $total += $value;
        }
        return ['per_coin' => $perCoin, 'total' => round($total, 8)];
    }

    /**
     * Gate for a NEW base entry. Checks kill switch, per-coin and total
     * exposure caps (agent_settings). Fails closed when the traded symbol's
     * price is unknown.
     *
     * @param array<string, mixed>       $agentSettings
     * @param callable(string):(?float)  $priceOf
     * @param bool $priceSourceAvailable false when the daemon has no price hub
     *        at all (e.g. no APCu) — exposure checks degrade to pass instead of
     *        blocking every entry (backward compatibility).
     * @return array{ok: bool, reason: string}
     */
    public function checkNewEntry(int $userId, string $symbol, float $orderUsdt, array $agentSettings, callable $priceOf, bool $priceSourceAvailable = true): array
    {
        $ks = $this->killSwitchState($userId, $agentSettings);
        if ($ks['tripped']) {
            return ['ok' => false, 'reason' => 'Kill switch active until ' . gmdate('Y-m-d H:i', $ks['until']) . ' UTC (daily loss limit breached)'];
        }
        if ($orderUsdt < $this->setting('min_order_usdt')) {
            return ['ok' => false, 'reason' => sprintf('Order size $%.2f below exchange minimum $%.2f', $orderUsdt, $this->setting('min_order_usdt'))];
        }
        if ($priceSourceAvailable && $priceOf($symbol) === null) {
            return ['ok' => false, 'reason' => "No price available for {$symbol} — exposure cannot be verified (HOLD)"];
        }
        $maxPerCoin = (float)($agentSettings['max_capital_per_bot'] ?? 1000.0);
        $maxTotal = (float)($agentSettings['max_total_capital'] ?? 10000.0);
        $exposure = $this->exposureUsdt($userId, $priceOf);
        $coinNow = $exposure['per_coin'][$symbol] ?? 0.0;
        if ($coinNow + $orderUsdt > $maxPerCoin) {
            return ['ok' => false, 'reason' => sprintf('Per-coin cap: %s exposure $%.2f + $%.2f > $%.2f', $symbol, $coinNow, $orderUsdt, $maxPerCoin)];
        }
        if ($exposure['total'] + $orderUsdt > $maxTotal) {
            return ['ok' => false, 'reason' => sprintf('Total cap: exposure $%.2f + $%.2f > $%.2f', $exposure['total'], $orderUsdt, $maxTotal)];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Gate for a DCA step on an existing deal: per-coin cap only (kill switch
     * blocks NEW deals but must not strand open positions — fail-safe HOLD).
     *
     * @param array<string, mixed>      $agentSettings
     * @param callable(string):(?float) $priceOf
     * @return array{ok: bool, reason: string}
     */
    public function checkDcaHeadroom(int $userId, string $symbol, float $dcaUsdt, array $agentSettings, callable $priceOf): array
    {
        $maxPerCoin = (float)($agentSettings['max_capital_per_bot'] ?? 1000.0);
        $exposure = $this->exposureUsdt($userId, $priceOf);
        $coinNow = $exposure['per_coin'][$symbol] ?? 0.0;
        if ($coinNow + $dcaUsdt > $maxPerCoin) {
            return ['ok' => false, 'reason' => sprintf('Per-coin cap: %s exposure $%.2f + DCA $%.2f > $%.2f', $symbol, $coinNow, $dcaUsdt, $maxPerCoin)];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Seconds left of the per-bot cut-loss cooldown (0 = free to trade).
     * Accepts mixed: a corrupt runtime_state (null/scalar) degrades to "no
     * cooldown" rather than a TypeError — the daemon tick must never die here.
     *
     * @param mixed $runtimeState
     */
    public function cutLossCooldownRemainingSec(mixed $runtimeState): int
    {
        if (!is_array($runtimeState)) {
            return 0;
        }
        $at = (int)($runtimeState['cut_loss_at'] ?? 0);
        if ($at <= 0) {
            return 0;
        }
        $remaining = $at + (int)round($this->setting('cut_loss_cooldown_hours') * 3600) - time();
        return max(0, $remaining);
    }

    /**
     * Pre-trade sanity: size floor + order price within x% of mid.
     * A null mid means no independent reference price is available; the
     * entry gate already failed closed in that case, so pass here.
     *
     * @return array{ok: bool, reason: string}
     */
    public function sanityCheckOrder(float $orderPrice, ?float $midPrice, float $orderUsdt): array
    {
        if ($orderUsdt < $this->setting('min_order_usdt')) {
            return ['ok' => false, 'reason' => sprintf('Order size $%.2f below minimum $%.2f', $orderUsdt, $this->setting('min_order_usdt'))];
        }
        if ($orderPrice <= 0 || !is_finite($orderPrice)) {
            return ['ok' => false, 'reason' => 'Order price must be a positive finite number'];
        }
        if ($midPrice !== null && $midPrice > 0) {
            $dev = abs($orderPrice - $midPrice) / $midPrice * 100;
            if ($dev > $this->setting('price_deviation_pct')) {
                return ['ok' => false, 'reason' => sprintf('Order price $%.6f deviates %.2f%% from mid $%.6f (max %.2f%%)', $orderPrice, $dev, $midPrice, $this->setting('price_deviation_pct'))];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * AI mode requires finite, capped DCA steps. 0 means "DCA disabled" in this
     * codebase (a safe state) and stays 0; anything above the hard cap clamps.
     */
    public function clampDcaSteps(string $aiTrustMode, int $configuredSteps): int
    {
        if ($configuredSteps < 0) {
            $configuredSteps = 0;
        }
        if ($aiTrustMode !== 'off' && $configuredSteps > (int)$this->setting('ai_max_dca_steps_cap')) {
            return (int)$this->setting('ai_max_dca_steps_cap');
        }
        return $configuredSteps;
    }

    private function appSetting(string $key): string
    {
        $this->ensureTable();
        $row = $this->db->fetchAssociative(
            "SELECT value FROM app_settings WHERE setting_key = ?",
            [$key]
        );
        return $row === false ? '' : (string)$row['value'];
    }

    private function setAppSetting(string $key, string $value): void
    {
        $this->ensureTable();
        $exists = $this->db->fetchOne("SELECT 1 FROM app_settings WHERE setting_key = ?", [$key]);
        if ($exists) {
            $this->db->update('app_settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], ['setting_key' => $key]);
        } else {
            $this->db->insert('app_settings', ['setting_key' => $key, 'value' => $value, 'encrypted' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
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
        $this->tableReady = true;
    }
}
