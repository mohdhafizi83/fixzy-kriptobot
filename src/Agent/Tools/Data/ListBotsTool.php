<?php

namespace Fixzy\Kriptobot\Agent\Tools\Data;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class ListBotsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'list_bots';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'List all trading bots belonging to the user. Returns a summary of each bot including pair, status, allocated capital, and current PNL. Use to get an overview of the user bot portfolio.'
            : 'List all trading bots belonging to the user. Returns summary of each bot including pair, status, allocated capital, and current PNL. Use to get an overview of the user bot portfolio.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status_filter' => [
                    'type'    => 'string',
                    'enum'    => ['all', 'active', 'inactive', 'agent_managed'],
                    'description' => 'Filter bots by status',
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $statusFilter = $params['status_filter'] ?? 'all';

        try {
            $conn = Database::getConnection();

            $where = "WHERE user_id = ? AND parent_id IS NULL";
            $bindings = [$userId];

            if ($statusFilter === 'active') {
                $where .= " AND status = 1";
            } elseif ($statusFilter === 'inactive') {
                $where .= " AND status = 0";
            } elseif ($statusFilter === 'agent_managed') {
                $where .= " AND is_agent_managed = 1";
            }

            $bots = $conn->executeQuery(
                "SELECT id, coin_pair, allocated_capital, status, is_agent_managed, runtime_state FROM bots {$where} ORDER BY id DESC",
                $bindings
            )->fetchAllAssociative();

            $summary = [];
            foreach ($bots as $bot) {
                $runtime = json_decode($bot['runtime_state'], true) ?? [];
                $summary[] = [
                    'id'                => (int)$bot['id'],
                    'coin_pair'         => $bot['coin_pair'],
                    'allocated_capital' => (float)$bot['allocated_capital'],
                    'status'            => $bot['status'] ? 'active' : 'inactive',
                    'agent_managed'     => (bool)$bot['is_agent_managed'],
                    'runtime_status'    => $runtime['status'] ?? 'IDLE',
                    'live_pnl_pct'      => $runtime['live_pnl_percent'] ?? 0,
                    'current_holdings'  => $runtime['current_holdings'] ?? 0,
                    'dca_step'          => $runtime['dca_current_step'] ?? 0,
                ];
            }

            $counts = [
                'total'   => count($summary),
                'active'  => count(array_filter($summary, fn($b) => $b['status'] === 'active')),
                'idle'    => count(array_filter($summary, fn($b) => $b['runtime_status'] === 'IDLE')),
                'in_deal' => count(array_filter($summary, fn($b) => !in_array($b['runtime_status'], ['IDLE', ''])))
            ];

            return ToolResult::ok([
                'bots'   => $summary,
                'counts' => $counts,
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Bot list failed: ' . $e->getMessage());
        }
    }
}
