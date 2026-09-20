<?php

namespace Fixzy\Kriptobot\Agent\Tools\BotManagement;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class DeleteBotTool implements ToolInterface
{
    public function getName(): string
    {
        return 'delete_bot';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Deactivate or delete a trading bot. Use with caution — this will stop active trading. Bot is marked as inactive.'
            : 'Deactivate or delete a trading bot. Use with caution — this will stop active trading. Bot is marked as inactive.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bot_id' => ['type' => 'integer', 'description' => 'Bot ID to deactivate/delete'],
                'hard_delete' => ['type' => 'boolean', 'description' => 'If true, delete permanently (default: soft deactivate)'],
            ],
            'required'   => ['bot_id'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $botId = (int)($params['bot_id'] ?? 0);
        $hardDelete = $params['hard_delete'] ?? false;

        if ($botId <= 0) {
            return ToolResult::fail('Invalid bot_id');
        }

        try {
            $conn = Database::getConnection();

            $bot = $conn->executeQuery(
                "SELECT id FROM bots WHERE id = ? AND user_id = ?",
                [$botId, $userId]
            )->fetchAssociative();

            if (!$bot) {
                return ToolResult::fail('Bot not found or access denied');
            }

            if ($hardDelete) {
                $conn->delete('bots', ['id' => $botId]);
                return ToolResult::ok([
                    'bot_id'  => $botId,
                    'status'  => 'deleted',
                    'message' => "Bot #{$botId} permanently deleted.",
                ]);
            }

            $conn->update('bots', ['status' => 0], ['id' => $botId]);

            return ToolResult::ok([
                'bot_id'  => $botId,
                'status'  => 'deactivated',
                'message' => "Bot #{$botId} deactivated. It will stop trading on the next daemon cycle.",
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Bot deletion failed: ' . $e->getMessage());
        }
    }
}
