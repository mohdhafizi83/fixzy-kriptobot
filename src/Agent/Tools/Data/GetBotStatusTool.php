<?php

namespace Fixzy\Kriptobot\Agent\Tools\Data;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class GetBotStatusTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_bot_status';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Get detailed status of a specific bot including full configuration, runtime state, current PNL, and trade statistics. Use to check bot performance before proposing changes.'
            : 'Get detailed status of a specific bot including full configuration, runtime state, current PNL, and trade statistics. Use to check bot performance before proposing changes.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bot_id' => ['type' => 'integer', 'description' => 'Bot ID to inspect'],
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

            $config = json_decode($bot['configuration'], true) ?? [];
            $runtime = json_decode($bot['runtime_state'], true) ?? [];

            // Recent trades for this bot
            $recentTrades = $conn->executeQuery(
                "SELECT action, price, amount, created_at FROM trade_logs WHERE bot_id = ? ORDER BY created_at DESC LIMIT 10",
                [$botId]
            )->fetchAllAssociative();

            // Count total trades
            $tradeCount = $conn->executeQuery(
                "SELECT COUNT(*) as cnt FROM trade_logs WHERE bot_id = ?",
                [$botId]
            )->fetchOne();

            // Agent decisions for this bot
            $agentDecisions = $conn->executeQuery(
                "SELECT id, decision_type, status, created_at FROM agent_decisions WHERE bot_id = ? ORDER BY created_at DESC LIMIT 5",
                [$botId]
            )->fetchAllAssociative();

            return ToolResult::ok([
                'bot_id'          => $botId,
                'coin_pair'       => $bot['coin_pair'],
                'allocated_capital' => (float)$bot['allocated_capital'],
                'status'          => $bot['status'] ? 'active' : 'inactive',
                'agent_managed'   => (bool)$bot['is_agent_managed'],
                'configuration'   => $config,
                'runtime_state'   => $runtime,
                'runtime_status'  => $runtime['status'] ?? 'IDLE',
                'current_pnl_pct' => $runtime['live_pnl_percent'] ?? 0,
                'current_holdings' => $runtime['current_holdings'] ?? 0,
                'average_entry'   => $runtime['average_entry_price'] ?? 0,
                'total_trades'    => (int)$tradeCount,
                'recent_trades'   => $recentTrades,
                'agent_decisions' => $agentDecisions,
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Bot status fetch failed: ' . $e->getMessage());
        }
    }
}
