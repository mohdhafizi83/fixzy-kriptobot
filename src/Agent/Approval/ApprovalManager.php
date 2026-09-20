<?php

namespace Fixzy\Kriptobot\Agent\Approval;

use Doctrine\DBAL\Connection;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Notifications\NotificationService;

class ApprovalManager
{
    private Connection $db;

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
     */
    public function getAgentSettings(int $userId): array
    {
        $row = $this->db->executeQuery(
            "SELECT * FROM agent_settings WHERE user_id = ?",
            [$userId]
        )->fetchAssociative();

        if (!$row) {
            return [
                'agent_enabled'         => false,
                'autonomy_mode'         => 'approval_required',
                'max_capital_per_bot'   => 1000.0,
                'max_total_capital'     => 10000.0,
                'allowed_actions'       => ['analyze_market', 'view_data', 'run_backtest'],
                'telegram_notifications' => true,
                'language_preference'   => 'auto',
            ];
        }

        $row['allowed_actions'] = json_decode($row['allowed_actions'], true)
            ?? ['analyze_market', 'view_data', 'run_backtest'];

        return $row;
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
