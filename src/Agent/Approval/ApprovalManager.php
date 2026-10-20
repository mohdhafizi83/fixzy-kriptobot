<?php

namespace Fixzy\Kriptobot\Agent\Approval;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Notifications\NotificationService;

/**
 * Class ApprovalManager - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ApprovalManager
{
    private Connection $db;

    /**
     * __construct.
     */
    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Create a pending approval record.
     */
    public function createDecision(
        int $userId,
        int $sessionId,
        string $decisionType,
        array $proposedConfig,
        ?int $botId,
        string $justification,
        ?array $marketSnapshot = null,
        ?array $riskProfile = null,
        ?array $backtestResults = null,
        ?array $originalConfig = null
    ): int {
        $this->db->executeStatement(
            "INSERT INTO agent_decisions
             (session_id, user_id, decision_type, bot_id, proposed_config, original_config,
              justification, market_snapshot, risk_profile, backtest_results, requiring_approval, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending_approval')",
            [
                $sessionId,
                $userId,
                $decisionType,
                $botId,
                json_encode($proposedConfig),
                $originalConfig ? json_encode($originalConfig) : null,
                $justification,
                $marketSnapshot ? json_encode($marketSnapshot) : null,
                $riskProfile ? json_encode($riskProfile) : null,
                $backtestResults ? json_encode($backtestResults) : null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get pending decisions for a user.
     */
    public function getPendingDecisions(int $userId): array
    {
        $rows = $this->db->executeQuery(
            "SELECT * FROM agent_decisions WHERE user_id = ? AND status = 'pending_approval' ORDER BY created_at DESC",
            [$userId]
        )->fetchAllAssociative();

        return array_map([$this, 'decodeDecision'], $rows);
    }

    /**
     * Get a specific decision.
     */
    public function getDecision(int $decisionId, int $userId): ?array
    {
        $row = $this->db->executeQuery(
            "SELECT * FROM agent_decisions WHERE id = ? AND user_id = ?",
            [$decisionId, $userId]
        )->fetchAssociative();

        return $row ? $this->decodeDecision($row) : null;
    }

    /**
     * Approve a decision. If autonomy mode allows, also apply it.
     */
    public function approve(int $decisionId, int $userId, bool $autoApply = false): array
    {
        $decision = $this->getDecision($decisionId, $userId);
        if (!$decision) {
            return ['success' => false, 'error' => 'Decision not found'];
        }

        if ($decision['status'] !== 'pending_approval') {
            return ['success' => false, 'error' => 'Decision is not pending approval'];
        }

        $this->db->update('agent_decisions', [
            'status'      => $autoApply ? 'applied' : 'approved',
            'approved_at' => date('Y-m-d H:i:s'),
            'applied_at'  => $autoApply ? date('Y-m-d H:i:s') : null,
        ], ['id' => $decisionId]);

        if ($autoApply) {
            $this->applyDecision($decision);
        }

        return [
            'success'     => true,
            'decision_id' => $decisionId,
            'status'      => $autoApply ? 'applied' : 'approved',
            'message'     => $autoApply
                ? 'Decision approved and applied.'
                : 'Decision approved. Apply manually or enable auto-apply.',
        ];
    }

    /**
     * Reject a decision with feedback.
     */
    public function reject(int $decisionId, int $userId, string $feedback = ''): array
    {
        $decision = $this->getDecision($decisionId, $userId);
        if (!$decision) {
            return ['success' => false, 'error' => 'Decision not found'];
        }
        if (!in_array($decision['status'] ?? '', ['pending_approval', 'pending_ai'], true)) {
            return ['success' => false, 'error' => 'Decision is not pending'];
        }

        $this->db->update('agent_decisions', [
            'status'        => 'rejected',
            'user_feedback' => $feedback,
        ], ['id' => $decisionId]);

        return [
            'success'     => true,
            'decision_id' => $decisionId,
            'status'      => 'rejected',
            'message'     => 'Decision rejected.',
        ];
    }

    /**
     * Modify a config (user edits proposed config).
     */
    public function modify(int $decisionId, int $userId, array $modifiedConfig): array
    {
        $decision = $this->getDecision($decisionId, $userId);
        if (!$decision) {
            return ['success' => false, 'error' => 'Decision not found'];
        }
        if (($decision['decision_type'] ?? '') === 'ai_escalation') {
            return ['success' => false, 'error' => 'Escalation decisions cannot be modified; approve or reject the AI verdict.'];
        }

        $this->db->update('agent_decisions', [
            'final_config' => json_encode($modifiedConfig),
            'status'       => 'modified',
        ], ['id' => $decisionId]);

        return [
            'success'     => true,
            'decision_id' => $decisionId,
            'status'      => 'modified',
            'message'     => 'Configuration modified. You can now approve the updated version.',
        ];
    }

    /**
     * Apply an approved decision (update bot config in DB).
     */
    public function applyDecision(array $decision): bool
    {
        // L3 escalations are NOT config changes: the daemon (which owns the
        // exchange session) executes the hold/wait/cut verdict. Normalise the
        // status to 'approved' so the daemon picks it up on its next tick —
        // applying one as a bot-config update would corrupt the configuration.
        if (($decision['decision_type'] ?? '') === 'ai_escalation') {
            $this->db->update('agent_decisions', [
                'status'      => 'approved',
                'approved_at' => date('Y-m-d H:i:s'),
            ], ['id' => $decision['id']]);
            return true;
        }

        $config = $decision['final_config'] ?? $decision['proposed_config'] ?? [];

        if (!$config) return false;

        $botId = $decision['bot_id'];

        if ($decision['decision_type'] === 'create_bot') {
            $botId = $this->createBotFromConfig($decision['user_id'], $config, $decision);
        } elseif ($botId) {
            $this->updateBotConfig($botId, $config, $decision);
        }

        // Update bot's agent_managed and decision_id
        if ($botId) {
            $this->db->update('bots', [
                'is_agent_managed'  => 1,
                'agent_decision_id' => $decision['id'],
            ], ['id' => $botId]);
        }

        $this->db->update('agent_decisions', [
            'status'     => 'applied',
            'applied_at' => date('Y-m-d H:i:s'),
        ], ['id' => $decision['id']]);

        return true;
    }

    /**
     * Create Bot From Config.
     * @param int $userId
     * @param array $config
     * @param array $decision
     * @return int
     */
    private function createBotFromConfig(int $userId, array $config, array $decision): int
    {
        $pair = $config['general']['custom_pairs'] ?? ($config['coin_pair'] ?? 'BTC/USDT');
        $capital = (float)($config['general']['capital'] ?? $config['allocated_capital'] ?? 0);

        $runtimeState = [
            'status'                 => 'IDLE',
            'current_holdings'       => 0,
            'average_entry_price'    => 0,
            'base_order_price'       => 0,
            'base_order_volume_usdt' => 0,
            'base_order_amount'      => 0,
            'dca_current_step'       => 0,
            'live_pnl_percent'       => 0,
            'total_deal_budget'      => 0,
            'trade_start_timestamp'  => null,
            'latest_tv_signal'       => '',
            'latest_external_signal' => '',
            'latest_ai_sentiment'    => '',
            'trailing_symbol'        => '',
            'trailing_low_watermark' => 0,
            'trailing_tp_watermark'  => 0,
            'trailing_sl_watermark'  => 0,
            'partial_sell_executed'  => [],
            'is_recovery_bot'        => false,
            'deficit_to_recover'     => 0,
            'recovered_so_far'       => 0,
        ];

        $this->db->executeStatement(
            "INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state, is_agent_managed, agent_decision_id)
             VALUES (?, ?, ?, 0, ?, ?, 1, ?)",
            [
                $userId,
                $pair,
                $capital,
                json_encode($config),
                json_encode($runtimeState),
                $decision['id'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update Bot Config.
     * @param int $botId
     * @param array $config
     * @param array $decision
     * @return void
     */
    private function updateBotConfig(int $botId, array $config, array $decision): void
    {
        $this->db->update('bots', [
            'configuration'     => json_encode($config),
            'agent_decision_id' => $decision['id'],
        ], ['id' => $botId]);
    }

    /**
     * Check if user has full autonomy (skip approval).
     */
    public function isFullAutonomy(int $userId): bool
    {
        $settings = $this->getAgentSettings($userId);
        return $settings['autonomy_mode'] === 'full_autonomy';
    }

    /**
     * Get agent settings for a user.
     *
     * Delegates to AgentSettingsService::get() so the daemon and the approval
     * flow always read the same values with the same defaults.
     */
    public function getAgentSettings(int $userId): array
    {
        return (new \Fixzy\Kriptobot\Service\AgentSettingsService($this->db))->get($userId);
    }

    /**
     * Send Telegram notification for pending approval.
     */
    public function sendTelegramApproval(int $decisionId, int $userId, string $telegramToken, string $telegramChatId): bool
    {
        $decision = $this->getDecision($decisionId, $userId);
        if (!$decision) return false;

        $decodedConfig = $decision['proposed_config'];
        $pair = $decodedConfig['general']['custom_pairs'] ?? ($decodedConfig['coin_pair'] ?? 'N/A');
        $tp = $decodedConfig['risk_management']['target_profit'] ?? 'N/A';
        $cl = $decodedConfig['risk_management']['cut_loss_percent'] ?? 'N/A';

        $message = "🤖 <b>Fixzy Kriptobot AI Agent — New Proposal</b>\n\n"
                 . "<b>Pair:</b> {$pair}\n"
                 . "<b>Type:</b> " . ucfirst(str_replace('_', ' ', $decision['decision_type'])) . "\n"
                 . "<b>Target Profit:</b> {$tp}%\n"
                 . "<b>Cut Loss:</b> {$cl}%\n\n"
                 . "<b>Justification:</b>\n" . mb_substr($decision['justification'], 0, 300) . "\n\n"
                 . "<i>Reply to this message with:</i>\n"
                 . "✅ <code>/approve {$decisionId}</code> — Approve\n"
                 . "❌ <code>/reject {$decisionId}</code> — Reject\n"
                 . "✏️ <code>/review {$decisionId}</code> — Review on the dashboard";

        $notifier = new NotificationService($telegramToken, $telegramChatId);
        $sent = $notifier->sendTelegramAlert($message);

        if ($sent) {
            $this->db->update('agent_decisions', [
                'telegram_notification_sent' => 1,
            ], ['id' => $decisionId]);
        }

        return $sent;
    }

    /**
     * Decode Decision.
     * @param array $row
     * @return array
     */
    private function decodeDecision(array $row): array
    {
        $row['proposed_config']  = json_decode($row['proposed_config'], true) ?? [];
        $row['original_config']  = $row['original_config'] ? json_decode($row['original_config'], true) : null;
        $row['final_config']     = $row['final_config'] ? json_decode($row['final_config'], true) : null;
        $row['market_snapshot']  = $row['market_snapshot'] ? json_decode($row['market_snapshot'], true) : null;
        $row['risk_profile']     = $row['risk_profile'] ? json_decode($row['risk_profile'], true) : null;
        $row['backtest_results'] = $row['backtest_results'] ? json_decode($row['backtest_results'], true) : null;
        return $row;
    }
}
