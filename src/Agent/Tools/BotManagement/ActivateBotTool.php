<?php

namespace Fixzy\Kriptobot\Agent\Tools\BotManagement;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

/**
 * Class ActivateBotTool - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ActivateBotTool implements ToolInterface
{
    /**
     * Get Name.
     * @return string
     */
    public function getName(): string
    {
        return 'activate_bot';
    }

    /**
     * Get Description.
     * @param string $lang (default: 'en')
     * @return string
     */
    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Activate a trading bot to start live trading. Only use after user has approved the configuration. Bot will be processed by the daemon on the next cycle.'
            : 'Activate a trading bot to start live trading. Only use after user has approved the configuration. Bot will be processed by the daemon on the next cycle.';
    }

    /**
     * Get Parameters.
     * @return array
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bot_id' => ['type' => 'integer', 'description' => 'Bot ID to activate'],
            ],
            'required'   => ['bot_id'],
        ];
    }

    /**
     * Execute.
     * @param array $params
     * @param int $userId
     * @return ToolResult
     */
    public function execute(array $params, int $userId): ToolResult
    {
        $botId = (int)($params['bot_id'] ?? 0);

        if ($botId <= 0) {
            return ToolResult::fail('Invalid bot_id');
        }

        try {
            $conn = Database::getConnection();

            $bot = $conn->executeQuery(
                "SELECT id, status FROM bots WHERE id = ? AND user_id = ?",
                [$botId, $userId]
            )->fetchAssociative();

            if (!$bot) {
                return ToolResult::fail('Bot not found or access denied');
            }

            if ($bot['status'] == 1) {
                return ToolResult::ok([
                    'bot_id'  => $botId,
                    'status'  => 'already_active',
                    'message' => 'Bot is already active.',
                ]);
            }

            $conn->update('bots', ['status' => 1], ['id' => $botId]);

            return ToolResult::ok([
                'bot_id'  => $botId,
                'status'  => 'activated',
                'message' => "Bot #{$botId} is now active and will start trading on the next daemon cycle.",
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Bot activation failed: ' . $e->getMessage());
        }
    }
}
