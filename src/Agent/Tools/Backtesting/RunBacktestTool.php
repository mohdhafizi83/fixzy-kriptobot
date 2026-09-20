<?php

namespace Fixzy\Kriptobot\Agent\Tools\Backtesting;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Backtest\BacktestEngine;
use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Trading\Rules\ExecutionGuardService;

class RunBacktestTool implements ToolInterface
{
    public function getName(): string
    {
        return 'run_backtest';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Run backtesting simulation on a trading bot configuration using historical data. Analyzes performance including total trades, win rate, average PNL, max drawdown, and overall returns. Use to validate strategies before live deployment.'
            : 'Run backtesting simulation on a trading bot configuration using historical data. Analyzes performance including total trades, win rate, average PNL, max drawdown, and overall returns. Use to validate strategies before live deployment.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair'              => ['type' => 'string', 'description' => 'Trading pair e.g. BTC/USDT'],
                'from_date'         => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default: 30 days ago)'],
                'to_date'           => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (default: yesterday)'],
                'timeframe'         => ['type' => 'string', 'enum' => ['5m', '15m', '1h', '4h', '1d'], 'description' => 'Candle timeframe (default 1h)'],
                'allocated_capital' => ['type' => 'number', 'description' => 'Total capital per deal in USDT (default 100)'],
                'target_profit'     => ['type' => 'number', 'description' => 'Take profit percentage (default 2.0)'],
                'cut_loss'          => ['type' => 'number', 'description' => 'Stop loss percentage (default 20)'],
                'max_dca_steps'     => ['type' => 'integer', 'description' => 'Max DCA safety orders (default 4)'],
                'price_drop_trigger'=> ['type' => 'number', 'description' => 'Price drop % to trigger DCA (default 2.0)'],
                'volume_scale'      => ['type' => 'number', 'description' => 'DCA volume multiplier (default 1.5)'],
                'step_scale'        => ['type' => 'number', 'description' => 'DCA step scale (default 1.0)'],
                'trailing_buy'      => ['type' => 'number', 'description' => 'Trailing buy deviation % (0 = disabled)'],
                'trailing_tp'       => ['type' => 'number', 'description' => 'Trailing TP deviation % (0 = disabled)'],
                'tp_type'           => ['type' => 'string', 'enum' => ['average_price', 'base_order'], 'description' => 'TP calculation type: average_price or base_order (default average_price)'],
                'entry_conditions'  => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type'      => ['type' => 'string'],
                            'value'     => ['type' => 'string'],
                            'timeframe' => ['type' => 'string'],
                            'period'    => ['type' => 'integer'],
                            'stddev'    => ['type' => 'number'],
                        ],
                    ],
                    'description' => 'Entry conditions array — each has: type, value, timeframe, period, stddev',
                ],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = strtoupper($params['pair'] ?? 'BTC/USDT');
        $fromDate = $params['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $toDate   = $params['to_date'] ?? date('Y-m-d', strtotime('-1 day'));

        try {
            $conn = Database::getConnection();
            $ingester = new BinanceDataIngester($conn);
            $auditLogger = new AuditLogger($conn);
            $executionGuard = new ExecutionGuardService($conn);
            $engine = new BacktestEngine($ingester, $auditLogger, $executionGuard);

            $entryConditions = $params['entry_conditions'] ?? $params['start_conditions'] ?? [];

            $config = [
                'symbol'                 => $pair,
                'from_date'              => $fromDate,
                'to_date'                => $toDate,
                'timeframe'              => $params['timeframe'] ?? '1h',
                'start_conditions'       => $entryConditions,
                'allocated_capital'      => (float)($params['allocated_capital'] ?? 100),
                'target_profit'          => (float)($params['target_profit'] ?? 2.0),
                'tp_type'                => $params['tp_type'] ?? 'average_price',
                'cut_loss'               => (float)($params['cut_loss'] ?? 20),
                'max_dca_steps'          => (int)($params['max_dca_steps'] ?? 4),
                'price_drop_trigger'     => (float)($params['price_drop_trigger'] ?? 2.0),
                'step_scale'             => (float)($params['step_scale'] ?? 1.0),
                'volume_scale'           => (float)($params['volume_scale'] ?? 1.5),
                'trailing_buy_deviation' => (float)($params['trailing_buy'] ?? 0),
                'trailing_tp_deviation'  => (float)($params['trailing_tp'] ?? 0),
            ];

            $result = $engine->run($config, $userId, 0);

            if (!empty($result['error'])) {
                return ToolResult::fail($result['message'] ?? $result['error']);
            }

            $stats = $result['stats'] ?? [];

            $summary = [
                'pair'             => $pair,
                'period'           => "{$fromDate} to {$toDate}",
                'timeframe'        => $params['timeframe'] ?? '1h',
                'total_trades'     => $stats['total_trades'] ?? 0,
                'winning_trades'   => $stats['winning_trades'] ?? 0,
                'losing_trades'    => $stats['losing_trades'] ?? 0,
                'win_rate'         => $stats['win_rate'] ?? 0,
                'total_pnl_usdt'   => round($stats['total_pnl_usdt'] ?? 0, 4),
                'total_pnl_pct'    => round($stats['total_pnl_pct'] ?? 0, 2),
                'avg_trade_pnl'    => round($stats['avg_trade_pnl'] ?? 0, 4),
                'max_drawdown_pct' => round($stats['max_drawdown_pct'] ?? 0, 2),
                'best_trade'       => $stats['best_trade'] ? [
                    'pnl_pct'     => $stats['best_trade']['pnl_pct'] ?? 0,
                    'entry_time'  => $stats['best_trade']['entry_time'] ?? '',
                    'exit_time'   => $stats['best_trade']['exit_time'] ?? '',
                ] : null,
                'worst_trade'      => $stats['worst_trade'] ? [
                    'pnl_pct'     => $stats['worst_trade']['pnl_pct'] ?? 0,
                    'entry_time'  => $stats['worst_trade']['entry_time'] ?? '',
                    'exit_time'   => $stats['worst_trade']['exit_time'] ?? '',
                ] : null,
                'total_dca_executions' => $stats['total_dca_executions'] ?? 0,
                'open_deal'        => !empty($stats['open_deal']) ? [
                    'unrealized_pnl' => round($stats['open_deal']['unrealized_pnl_pct'] ?? 0, 2),
                ] : null,
                'initial_capital'  => $stats['initial_capital'] ?? 0,
            ];

            return ToolResult::ok($summary);

        } catch (\Throwable $e) {
            return ToolResult::fail('Backtest failed: ' . $e->getMessage());
        }
    }
}
