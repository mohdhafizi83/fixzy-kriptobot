<?php

namespace Fixzy\Kriptobot\Agent\Tools\BotManagement;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class MonitorBotTool implements ToolInterface
{
    public function getName(): string
    {
        return 'monitor_bot';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Get a real-time monitoring snapshot for a specific bot including runtime status, current PNL, and latest signals. Use to monitor running bots.'
            : 'Get a real-time monitoring snapshot for a specific bot including runtime status, current PNL, and latest signals. Use to monitor running bots.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bot_id' => ['type' => 'integer', 'description' => 'Bot ID to monitor'],
            ],
            'required'   => ['bot_id'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $botId = (int)($params['bot_id'] ?? 0);

        if ($botId <= 0) {
            return ToolResult::fail('Invalid bot_id');
        }

        try {
            $conn = Database::getConnection();

            $bot = $conn->executeQuery(
                "SELECT * FROM bots WHERE id = ? AND user_id = ?",
                [$botId, $userId]
            )->fetchAssociative();

            if (!$bot) {
                return ToolResult::fail('Bot not found or access denied');
            }

            $runtime = json_decode($bot['runtime_state'], true) ?? [];
            $config = json_decode($bot['configuration'], true) ?? [];

            // Last 5 audit events
            $recentEvents = $conn->executeQuery(
                "SELECT event_type, event_summary, created_at FROM audit_logs WHERE bot_id = ? ORDER BY created_at DESC LIMIT 5",
                [$botId]
            )->fetchAllAssociative();

            return ToolResult::ok([
                'bot_id'            => $botId,
                'coin_pair'         => $bot['coin_pair'],
                'status'            => $runtime['status'] ?? 'IDLE',
                'active'           => (bool)$bot['status'],
                'current_pnl_pct'   => $runtime['live_pnl_percent'] ?? 0,
                'current_holdings'  => $runtime['current_holdings'] ?? 0,
                'average_entry'     => $runtime['average_entry_price'] ?? 0,
                'dca_step'          => $runtime['dca_current_step'] ?? 0,
                'latest_tv_signal'  => $runtime['latest_tv_signal'] ?? '',
                'latest_ai_sentiment' => $runtime['latest_ai_sentiment'] ?? '',
                'capital_allocated' => (float)$bot['allocated_capital'],
                'target_profit'     => $config['risk_management']['target_profit'] ?? 'N/A',
                'cut_loss'          => $config['risk_management']['cut_loss_percent'] ?? 'N/A',
                'recent_events'     => $recentEvents,
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Monitor failed: ' . $e->getMessage());
        }
    }
}
