<?php

namespace Fixzy\Kriptobot\Intelligence\AiCopilot;

use Doctrine\DBAL\Connection;

/**
 * Phase 4 (L3) escalation: deterministic trigger detection + audit trail.
 *
 * Detection is CHEAP and runs inside the daemon tick (no LLM here). When a
 * trigger fires the bot is paused (new entries + DCA frozen; TP/trailing sells
 * keep running) and an agent_decisions row is opened so the whole episode —
 * snapshot, AI question, AI answer, action, human override — lives in the
 * same audit trail as the existing approval flow.
 *
 * Triggers (all require an open position unless noted):
 *  - flash_move:    |Δprice| >= flash_move_pct within flash_move_window_min
 *  - drawdown:      live pnl <= -max_drawdown_pct
 *  - dca_exhausted: DCA steps used up, still underwater, price below entry
 *  - l1_expired:    advisor/active but the L1 decision is missing/stale
 *                   (LLM down) — goes straight to a human, no AI question.
 *
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
final class Escalation
{
    public const TRIGGERS = ['flash_move', 'drawdown', 'dca_exhausted', 'l1_expired'];

    /** Do not re-trigger an escalation for this long after one was resolved. */
    public const COOLDOWN_SECONDS = 1800;

    /** Re-ask the AI this long after a "wait" verdict. */
    public const REASK_SECONDS = 1800;

    public static function isOpen(?array $esc): bool
    {
        return is_array($esc) && !empty($esc['paused']);
    }

    /**
     * Pure trigger detection — no I/O so it is fully unit-testable.
     *
     * @param array{mode:string,state:array,config:array,esc:array,now:int,
     *              price:float,pnl:float,price_window:?float,cache_age:?int,ttl:int,
     *              trade_age?:int|null} $ctx
     * @return array{trigger:string,detail:array,human_only:bool}|null
     */
    public static function detect(array $ctx): ?array
    {
        $mode = (string)($ctx['mode'] ?? 'off');
        if ($mode !== 'advisor' && $mode !== 'active') {
            return null;
        }
        $state = is_array($ctx['state'] ?? null) ? $ctx['state'] : [];
        $now = (int)($ctx['now'] ?? time());

        // Dedup: never open a second escalation while one is paused, while a
        // decision is still being answered, or during the post-resolution
        // cooldown (prevents alert spam on a sustained crash).
        $esc = $state['ai_l3'] ?? null;
        if (self::isOpen(is_array($esc) ? $esc : null)) {
            return null;
        }
        if (is_array($esc) && isset($esc['last_resolved_at'])
            && ($now - (int)$esc['last_resolved_at']) < self::COOLDOWN_SECONDS) {
            return null;
        }

        $settings = is_array($ctx['esc'] ?? null) ? $ctx['esc'] : [];
        $config = is_array($ctx['config'] ?? null) ? $ctx['config'] : [];
        $price = (float)($ctx['price'] ?? 0);
        $pnl = (float)($ctx['pnl'] ?? 0);
        $holdings = (float)($state['current_holdings'] ?? 0);
        $detailBase = ['price' => $price, 'pnl_pct' => $pnl, 'holdings' => $holdings];

        // 1) Flash move (needs price history from price_ticks).
        $priceWindow = $ctx['price_window'] ?? null;
        $flashPct = (float)($settings['flash_move_pct'] ?? 3.0);
        if ($holdings > 0 && is_numeric($priceWindow) && (float)$priceWindow > 0 && $price > 0) {
            $movePct = (($price - (float)$priceWindow) / (float)$priceWindow) * 100;
            if (abs($movePct) >= $flashPct) {
                return ['trigger' => 'flash_move', 'human_only' => false, 'detail' => $detailBase + [
                    'window_pct' => round($movePct, 4),
                    'window_min' => (int)($settings['flash_move_window_min'] ?? 10),
                    'threshold_pct' => $flashPct,
                ]];
            }
        }

        // 2) Drawdown breach.
        $maxDd = (float)($settings['max_drawdown_pct'] ?? 5.0);
        if ($holdings > 0 && $pnl <= -$maxDd) {
            return ['trigger' => 'drawdown', 'human_only' => false, 'detail' => $detailBase + [
                'limit_pct' => $maxDd,
            ]];
        }

        // 3) DCA exhausted while still underwater and price below entry.
        $step = (int)($state['dca_current_step'] ?? 0);
        $maxSteps = (int)($config['dca']['max_steps'] ?? 0);
        $avgEntry = (float)($state['average_entry_price'] ?? 0);
        if ($holdings > 0 && $maxSteps > 0 && $step >= $maxSteps && $pnl < 0 && $price > 0 && $avgEntry > 0 && $price < $avgEntry) {
            return ['trigger' => 'dca_exhausted', 'human_only' => false, 'detail' => $detailBase + [
                'dca_step' => $step, 'max_steps' => $maxSteps, 'avg_entry' => $avgEntry,
            ]];
        }

        // 4) L1 expired: the strategic brain is gone; freeze and ask a human.
        // Grace: a missing cache on a freshly started position is NOT "LLM
        // down" — the loop simply has not produced a decision yet.
        $age = $ctx['cache_age'] ?? null;
        $ttl = (int)($ctx['ttl'] ?? 3600);
        $tradeAgeRaw = $ctx['trade_age'] ?? null;
        $inGrace = is_numeric($tradeAgeRaw) && (float)$tradeAgeRaw <= $ttl;
        if ($holdings > 0 && ($age === null ? !$inGrace : (int)$age > $ttl)) {
            return ['trigger' => 'l1_expired', 'human_only' => true, 'detail' => $detailBase + [
                'cache_age' => $age === null ? null : (int)$age, 'ttl' => $ttl,
            ]];
        }

        return null;
    }

    /** Oldest price inside the flash window (from price_ticks), or null. */
    public static function windowPrice(Connection $conn, int $botId, int $windowMin): ?float
    {
        $price = $conn->fetchOne(
            "SELECT price FROM price_ticks
             WHERE bot_id = ? AND created_at <= datetime('now', ?)
             ORDER BY id DESC LIMIT 1",
            [$botId, "-{$windowMin} minutes"]
        );
        return is_numeric($price) ? (float)$price : null;
    }

    /**
     * Open the audit-trail row. l1_expired skips the AI question (the LLM is
     * the thing that is down) and goes straight to a human.
     */
    public static function openDecision(
        Connection $conn,
        int $botId,
        int $userId,
        array $trigger,
        string $symbol
    ): int {
        $humanOnly = !empty($trigger['human_only']);
        $conn->executeStatement(
            "INSERT INTO agent_decisions
             (user_id, decision_type, bot_id, justification, market_snapshot, requiring_approval, status)
             VALUES (?, 'ai_escalation', ?, ?, ?, 1, ?)",
            [
                $userId,
                $botId,
                sprintf('[L3 %s] Bot #%d (%s) paused: %s', $trigger['trigger'], $botId, $symbol, (string)json_encode($trigger['detail'])),
                (string)json_encode(['trigger' => $trigger['trigger'], 'detail' => $trigger['detail'], 'opened_at' => date('Y-m-d H:i:s')]),
                $humanOnly ? 'pending_approval' : 'pending_ai',
            ]
        );
        return (int)$conn->lastInsertId();
    }

    /** Age in seconds of the L1 cache, or null when absent/corrupt. */
    public static function cacheAge(?array $cache, int $now): ?int
    {
        if (!is_array($cache)) {
            return null;
        }
        $created = (int)($cache['created_at'] ?? 0);
        return $created > 0 ? max(0, $now - $created) : null;
    }
}
