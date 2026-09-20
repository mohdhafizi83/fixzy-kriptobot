<?php

namespace Fixzy\Kriptobot\Agent\Tools\BotManagement;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Agent\ConfigGenerator;
use Fixzy\Kriptobot\Database\Database;

class CreateBotTool implements ToolInterface
{
    public function getName(): string
    {
        return 'create_bot';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Create a new trading bot with the proposed configuration. Bot is saved in DRAFT (inactive) status until approved by user. Requires full bot configuration in JSON format. Use this ONLY after complete backtesting and analysis.'
            : 'Create a new trading bot with the proposed configuration. Bot is saved in DRAFT (inactive) status until approved by user. Requires full bot configuration in JSON format. Use this ONLY after complete backtesting and analysis.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'config' => [
                    'type'        => 'object',
                    'description' => 'Full bot configuration JSON with general, base_order, dca, and risk_management sections',
                ],
                'coin_pair'       => ['type' => 'string', 'description' => 'Trading pair e.g. BTC/USDT'],
                'allocated_capital' => ['type' => 'number', 'description' => 'Allocated capital in USDT'],
                'auto_activate'   => ['type' => 'boolean', 'description' => 'If true, auto-activate (only for full_autonomy mode)'],
            ],
            'required'   => ['config', 'coin_pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $config = $params['config'] ?? [];
        $coinPair = $params['coin_pair'] ?? '';
        $allocatedCapital = (float)($params['allocated_capital'] ?? 0);

        if (empty($config) || empty($coinPair)) {
            return ToolResult::fail('Config and coin_pair are required');
        }

        try {
            $conn = Database::getConnection();

            $generator = new ConfigGenerator();
            $fullConfig = is_array($config) ? array_replace_recursive(
                $generator->buildConfig([]),
                $config
            ) : $config;

            $runtimeState = $generator->initialRuntimeState();

            $conn->executeStatement(
                "INSERT INTO bots (user_id, coin_pair, allocated_capital, status, configuration, runtime_state, is_agent_managed)
                 VALUES (?, ?, ?, 0, ?, ?, 1)",
                [
                    $userId,
                    $coinPair,
                    $allocatedCapital > 0 ? $allocatedCapital : 0,
                    json_encode($fullConfig),
                    json_encode($runtimeState),
                ]
            );

            $botId = (int) $conn->lastInsertId();

            return ToolResult::ok([
                'bot_id'   => $botId,
                'status'   => 'draft',
                'message'  => "Bot #{$botId} created successfully. Awaiting user approval to activate.",
                'coin_pair' => $coinPair,
                'config_summary' => [
                    'target_profit' => $fullConfig['risk_management']['target_profit'] ?? 'N/A',
                    'cut_loss'      => $fullConfig['risk_management']['cut_loss_percent'] ?? 'N/A',
                    'max_dca'       => $fullConfig['dca']['max_steps'] ?? 'N/A',
                ],
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Bot creation failed: ' . $e->getMessage());
        }
    }
}
