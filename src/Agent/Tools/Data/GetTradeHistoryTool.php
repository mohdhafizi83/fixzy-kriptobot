<?php

namespace Fixzy\Kriptobot\Agent\Tools\Data;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Database\Database;

class GetTradeHistoryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_trade_history';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Get trade history for a specific bot or all user bots. Contains buy/sell logs, PNL, and trade performance. Use to analyze existing bot performance.'
            : 'Get trade history for a specific bot or all user bots. Contains buy/sell logs, PNL, and trade performance. Use to analyze existing bot performance.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bot_id' => ['type' => 'integer', 'description' => 'Bot ID (omit for all user bots)'],
                'limit'  => ['type' => 'integer', 'description' => 'Max trades to return (default 50)'],
                'days'   => ['type' => 'integer', 'description' => 'Look back N days (default 30)'],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $botId = $params['bot_id'] ?? null;
        $limit = min((int)($params['limit'] ?? 50), 200);
        $days  = (int)($params['days'] ?? 30);

        try {
            $conn = Database::getConnection();

            if ($botId) {
                $trades = $conn->executeQuery(
                    "SELECT * FROM trade_logs WHERE bot_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY) ORDER BY created_at DESC LIMIT " . (int)$limit,
                    [$botId]
                )->fetchAllAssociative();
            } else {
                $trades = $conn->executeQuery(
                    "SELECT tl.*, b.coin_pair FROM trade_logs tl
                     JOIN bots b ON tl.bot_id = b.id
                     WHERE b.user_id = ? AND tl.created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY)
                     ORDER BY tl.created_at DESC LIMIT " . (int)$limit,
                    [$userId]
                )->fetchAllAssociative();
            }

            $summary = $this->summarizeTrades($trades);

            return ToolResult::ok([
                'trade_count' => count($trades),
                'summary'     => $summary,
                'recent'      => array_slice($trades, 0, 10),
                'period_days' => $days,
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Trade history fetch failed: ' . $e->getMessage());
        }
    }

    private function summarizeTrades(array $trades): array
    {
        $buys  = array_filter($trades, fn($t) => in_array($t['action'] ?? '', ['BUY', 'DCA_BUY', 'BACKTEST_BUY', 'BACKTEST_DCA_BUY']));
        $sells = array_filter($trades, fn($t) => in_array($t['action'] ?? '', ['SELL', 'BACKTEST_SELL', 'BACKTEST_TAKE_PROFIT', 'BACKTEST_CUT_LOSS']));

        $totalBuyVolume = array_sum(array_column($buys, 'amount'));
        $totalSellVolume = array_sum(array_column($sells, 'amount'));

        return [
            'total_trades' => count($trades),
            'buy_orders'   => count($buys),
            'sell_orders'  => count($sells),
            'buy_volume'   => round($totalBuyVolume, 8),
            'sell_volume'  => round($totalSellVolume, 8),
        ];
    }
}
