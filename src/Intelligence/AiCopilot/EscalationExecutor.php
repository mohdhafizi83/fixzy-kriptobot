<?php

namespace Fixzy\Kriptobot\Intelligence\AiCopilot;

use Doctrine\DBAL\Connection;

/**
 * Executes a resolved L3 escalation verdict. Runs ONLY in the daemon (it owns
 * the exchange session); the AI loop only writes verdicts.
 *
 * Verdict sources:
 *  - status 'auto_approved'  → AI answer, full-autonomy users (proposed_config.action)
 *  - status 'approved'       → human approved the AI recommendation via the UI
 *  - status 'rejected'       → human said no → resume normal trading
 *
 * Actions:
 *  - hold  → clear the pause, resume normal trading
 *  - wait  → stay paused; re-ask after Escalation::REASK_SECONDS
 *  - cut   → market-sell all holdings (via injected $sellFn), then resume
 *
 * State transitions are written with targeted json_set so a concurrent AI-loop
 * cache write is never clobbered.
 *
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
final class EscalationExecutor
{
    public const ACTIONS = ['hold', 'wait', 'cut'];

    /**
     * Strict verdict parse for the L3 answer. Anything malformed → null
     * (fail-safe: bot stays paused, nothing is sold).
     *
     * @return array{action:string,confidence:float,reason:string}|null
     */
    public static function parseVerdict(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }
        $action = $decoded['action'] ?? null;
        $confidence = $decoded['confidence'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            return null;
        }
        if (!is_numeric($confidence)) {
            return null;
        }
        $conf = (float)$confidence;
        if (!is_finite($conf) || $conf < 0.0 || $conf > 1.0) {
            return null;
        }
        $reason = is_string($decoded['reason'] ?? null)
            ? mb_substr(trim($decoded['reason']), 0, 500)
            : '';
        return ['action' => $action, 'confidence' => $conf, 'reason' => $reason];
    }

    /**
     * @param array $decision agent_decisions row (decoded)
     * @param callable(string $action):array{ok:bool,message:string} $sellFn
     *        executes the full market sell; only called for 'cut'.
     * @return array{executed:bool,action:string,resolved:bool,message:string}
     */
    public static function execute(
        Connection $conn,
        array $decision,
        int $botId,
        callable $sellFn,
        int $now,
        array $currentEsc = []
    ): array {
        $status = (string)($decision['status'] ?? '');
        $action = '';
        $note = '';
        if ($status === 'rejected') {
            $action = 'hold'; // human refused the action → resume, do nothing
        } elseif ($status === 'auto_approved' || $status === 'approved') {
            $proposed = is_array($decision['proposed_config'] ?? null) ? $decision['proposed_config'] : [];
            $verdict = self::parseVerdict($proposed);
            if ($verdict === null) {
                if ($status === 'approved') {
                    // Human approved without an AI verdict (e.g. l1_expired):
                    // approval means "acknowledged, resume normal trading".
                    $action = 'hold';
                    $note = ' (no AI verdict; human approval resumes trading)';
                } else {
                    return ['executed' => false, 'action' => '', 'resolved' => false, 'message' => 'verdict missing or invalid'];
                }
            } else {
                $action = $verdict['action'];
            }
        } else {
            return ['executed' => false, 'action' => '', 'resolved' => false, 'message' => 'not resolved'];
        }

        // A previous "wait" that has reached its re-ask time expires: resume
        // and let trigger detection re-open a FRESH escalation (with a fresh
        // AI question) if the bad conditions still hold. Endless silent
        // re-waits would hide a stuck position.
        if ($action === 'wait'
            && ($currentEsc['action'] ?? '') === 'wait'
            && (int)($currentEsc['reask_at'] ?? 0) > 0
            && $now >= (int)$currentEsc['reask_at']) {
            $action = 'hold';
            $note = ' (wait window expired)';
        }

        if ($action === 'cut') {
            $result = $sellFn('cut');
            if (!is_array($result) || empty($result['ok'])) {
                // Keep the pause; the daemon retries next tick. Never un-pause
                // after a failed sell — that would silently resume DCA into a
                // position the AI/human just decided to exit.
                return [
                    'executed' => false,
                    'action'   => 'cut',
                    'resolved' => false,
                    'message'  => 'sell failed: ' . (is_array($result) ? (string)($result['message'] ?? '?') : '?'),
                ];
            }
        }

        $resolved = $action !== 'wait';
        $escState = [
            'paused'            => $action === 'wait' ? 1 : 0,
            'trigger'           => (string)($decision['market_snapshot']['trigger'] ?? ''),
            'decision_id'       => (int)$decision['id'],
            'action'            => $action,
            'resolved_at'       => $resolved ? $now : 0,
            'last_resolved_at'  => $resolved ? $now : 0,
            'reask_at'          => $action === 'wait' ? $now + Escalation::REASK_SECONDS : 0,
        ];

        // Targeted write: never clobber a fresh AI-loop cache.
        $conn->executeStatement(
            "UPDATE bots SET runtime_state = json_set(
                 CASE WHEN json_valid(runtime_state) AND json_type(runtime_state) = 'object'
                      THEN runtime_state ELSE '{}' END,
                 '$.ai_l3', json(?)) WHERE id = ?",
            [(string)json_encode($escState), $botId]
        );

        if ($resolved && in_array($status, ['auto_approved', 'approved'], true)) {
            $conn->executeStatement(
                "UPDATE agent_decisions SET status = 'applied', applied_at = ? WHERE id = ? AND status = ?",
                [date('Y-m-d H:i:s'), (int)$decision['id'], $status]
            );
        }

        return [
            'executed' => true,
            'action'   => $action,
            'resolved' => $resolved,
            'message'  => ($action === 'wait' ? 'holding; will re-ask later' : 'escalation ' . $action . ' applied') . $note,
        ];
    }
}
