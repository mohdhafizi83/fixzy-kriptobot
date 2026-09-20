<?php

namespace Fixzy\Kriptobot\Agent;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;

class AgentToolRegistry
{
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function getAllTools(): array
    {
        return $this->tools;
    }

    /**
     * Returns tool definitions for LLM function calling format (OpenAI-compatible).
     */
    public function getToolDefinitions(string $lang = 'en'): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->getName(),
                    'description' => $tool->getDescription($lang),
                    'parameters'  => $tool->getParameters(),
                ],
            ];
        }
        return $definitions;
    }

    /**
     * Returns tool definitions for ReAct prompt format (fallback).
     */
    public function getToolDescriptionsForReact(string $lang = 'en'): string
    {
        $lines = [];
        foreach ($this->tools as $tool) {
            $params = $tool->getParameters();
            $paramDesc = '';
            if (!empty($params['properties'])) {
                $props = [];
                foreach ($params['properties'] as $name => $prop) {
                    $required = in_array($name, $params['required'] ?? []) ? ' (required)' : '';
                    $props[] = "  - {$name}: {$prop['description']}{$required}";
                }
                $paramDesc = "\n" . implode("\n", $props);
            }
            $lines[] = "**{$tool->getName()}**: {$tool->getDescription($lang)}{$paramDesc}";
        }
        return implode("\n\n", $lines);
    }

    /**
     * Filter tools based on allowed actions for a user.
     */
    public function getFilteredToolDefinitions(array $allowedActions, string $lang = 'en'): array
    {
        $toolActionMap = [
            'create_bot'     => ['create_bot'],
            'update_config'  => ['update_bot_config'],
            'delete_bot'     => ['delete_bot'],
            'activate_bot'   => ['activate_bot'],
            'deactivate_bot' => ['deactivate_bot'],
            'analyze_market' => [
                'analyze_market', 'analyze_technical', 'analyze_sentiment',
                'analyze_volatility', 'analyze_trend',
            ],
            'run_backtest'   => ['run_backtest', 'optimize_strategy', 'compare_strategies'],
            'view_data'      => [
                'list_bots', 'get_bot_status', 'get_historical_data',
                'get_trade_history', 'monitor_bot',
            ],
        ];

        $allowedToolNames = [];
        foreach ($allowedActions as $action) {
            if (isset($toolActionMap[$action])) {
                $allowedToolNames = array_merge($allowedToolNames, $toolActionMap[$action]);
            }
        }
        $allowedToolNames = array_unique($allowedToolNames);

        if (empty($allowedToolNames)) {
            return [];
        }

        $definitions = [];
        foreach ($this->tools as $tool) {
            if (in_array($tool->getName(), $allowedToolNames, true)) {
                $definitions[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $tool->getName(),
                        'description' => $tool->getDescription($lang),
                        'parameters'  => $tool->getParameters(),
                    ],
                ];
            }
        }
        return $definitions;
    }

    public function execute(string $name, array $params, int $userId): ToolResult
    {
        $tool = $this->getTool($name);
        if ($tool === null) {
            return ToolResult::fail("Tool '{$name}' not found.");
        }
        try {
            return $tool->execute($params, $userId);
        } catch (\Throwable $e) {
            error_log("Agent tool '{$name}' error: " . $e->getMessage());
            return ToolResult::fail("Tool error: " . $e->getMessage());
        }
    }
}
