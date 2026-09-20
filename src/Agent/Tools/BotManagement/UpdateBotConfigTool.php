<?php

namespace Fixzy\Kriptobot\Agent\Tools\BotManagement;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class UpdateBotConfigTool implements ToolInterface
{
    public function getName(): string
    {
        return 'update_bot_config';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Update configuration of an existing trading bot. Can modify parameters like target profit, cut loss, DCA, and entry conditions. Changes are saved but may require user approval before activation. Use after performance analysis.'
            : 'Update configuration of an existing trading bot. Can modify parameters like target profit, cut loss, DCA, and entry conditions. Changes are saved but may require user approval before activation. Use after performance analysis.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bot_id'      => ['type' => 'integer', 'description' => 'Bot ID to update'],
                'config_patch' => [
                    'type' => 'object',
                    'description' => 'Partial config to merge (only fields to change)',
                ],
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

            $existingConfig = json_decode($bot['configuration'], true) ?? [];
            $patch = $params['config_patch'] ?? [];

            if (empty($patch)) {
                return ToolResult::fail('config_patch is required');
            }

            $updatedConfig = array_replace_recursive($existingConfig, $patch);

            $conn->update('bots', [
                'configuration' => json_encode($updatedConfig),
            ], ['id' => $botId]);

            return ToolResult::ok([
                'bot_id'         => $botId,
                'updated'        => true,
                'changed_fields' => array_keys($patch),
                'message'        => "Bot #{$botId} configuration updated.",
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Bot update failed: ' . $e->getMessage());
        }
    }
}
