<?php

namespace Fixzy\Kriptobot\Agent\Tools\Backtesting;

use Fixzy\Kriptobot\Agent\Tools\ToolInterface;
use Fixzy\Kriptobot\Agent\Tools\ToolResult;
use Fixzy\Kriptobot\Backtest\BinanceDataIngester;
use Fixzy\Kriptobot\Backtest\BacktestEngine;
use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Trading\Rules\ExecutionGuardService;

class OptimizeStrategyTool implements ToolInterface
{
    public function getName(): string
    {
        return 'optimize_strategy';
    }

    public function getDescription(string $lang = 'en'): string
    {
        return $lang === 'ms'
            ? 'Run strategy parameter optimization through grid search. Tests multiple parameter combinations (target profit, cut loss, DCA steps, DCA volume scale, trailing) and returns top 5 configurations ranked by metrics (win rate, Sharpe ratio, total PNL). Use after initial backtest.'
            : 'Run strategy parameter optimization through grid search. Tests multiple parameter combinations (target profit, cut loss, DCA steps, DCA volume scale, trailing) and returns top 5 configurations ranked by metrics (win rate, Sharpe ratio, total PNL). Use after initial backtest.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pair'              => ['type' => 'string', 'description' => 'Trading pair e.g. BTC/USDT'],
                'from_date'         => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                'to_date'           => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                'timeframe'         => ['type' => 'string', 'enum' => ['5m', '15m', '1h', '4h', '1d']],
                'allocated_capital' => ['type' => 'number', 'description' => 'Capital per deal in USDT'],
                'entry_conditions'  => [
                    'type'        => 'array',
                    'description' => 'Fixed entry conditions (not optimized)',
                ],
                'optimize_target_profit' => ['type' => 'boolean', 'description' => 'Optimize take profit %'],
                'optimize_cut_loss'      => ['type' => 'boolean', 'description' => 'Optimize stop loss %'],
                'optimize_dca'           => ['type' => 'boolean', 'description' => 'Optimize DCA parameters'],
                'risk_profile'           => ['type' => 'string', 'enum' => ['conservative', 'moderate', 'aggressive'], 'description' => 'Risk profile to constrain parameter ranges'],
            ],
            'required'   => ['pair'],
        ];
    }

    public function execute(array $params, int $userId): ToolResult
    {
        $pair = strtoupper($params['pair'] ?? 'BTC/USDT');
        $fromDate = $params['from_date'] ?? date('Y-m-d', strtotime('-60 days'));
        $toDate   = $params['to_date'] ?? date('Y-m-d', strtotime('-1 day'));
        $riskProfile = $params['risk_profile'] ?? 'moderate';

        try {
            $conn = Database::getConnection();
            $ingester = new BinanceDataIngester($conn);
            $auditLogger = new AuditLogger($conn);
            $executionGuard = new ExecutionGuardService($conn);
            $engine = new BacktestEngine($ingester, $auditLogger, $executionGuard);

            $entryConditions = $params['entry_conditions'] ?? [];
            $capital = (float)($params['allocated_capital'] ?? 100);
            $timeframe = $params['timeframe'] ?? '1h';

            // Generate parameter grid based on risk profile
            $paramGrid = $this->buildParameterGrid(
                $riskProfile,
                $params['optimize_target_profit'] ?? true,
                $params['optimize_cut_loss'] ?? true,
                $params['optimize_dca'] ?? true
            );

            $results = [];
            $totalCombos = count($paramGrid);
            $processed = 0;

            foreach ($paramGrid as $combo) {
                $config = [
                    'symbol'                 => $pair,
                    'from_date'              => $fromDate,
                    'to_date'                => $toDate,
                    'timeframe'              => $timeframe,
                    'start_conditions'       => $entryConditions,
                    'allocated_capital'      => $capital,
                    'target_profit'          => $combo['target_profit'],
                    'tp_type'                => 'average_price',
                    'cut_loss'               => $combo['cut_loss'],
                    'max_dca_steps'          => $combo['max_dca_steps'],
                    'price_drop_trigger'     => $combo['price_drop_trigger'],
                    'step_scale'             => $combo['step_scale'],
                    'volume_scale'           => $combo['volume_scale'],
                    'trailing_buy_deviation' => $combo['trailing_buy'],
                    'trailing_tp_deviation'  => $combo['trailing_tp'],
                ];

                try {
                    $result = $engine->run($config, $userId, 0);

                    if (!empty($result['error'])) continue;

                    $stats = $result['stats'] ?? [];
                    $trades = $result['trades'] ?? [];

                    $completedTrades = $stats['total_trades'] ?? 0;
                    if ($completedTrades < 3) continue;

                    $winRate = $stats['win_rate'] ?? 0;
                    $totalPnl = $stats['total_pnl_usdt'] ?? 0;
                    $drawdown = $stats['max_drawdown_pct'] ?? 100;
                    $sharpe = $this->estimateSharpe($trades, $capital);

                    $score = $this->calculateScore($winRate, $totalPnl, $drawdown, $sharpe, $completedTrades);

                    $results[] = [
                        'params'      => $combo,
                        'win_rate'    => $winRate,
                        'total_pnl'   => round($totalPnl, 4),
                        'max_drawdown'=> round($drawdown, 2),
                        'sharpe'      => round($sharpe, 3),
                        'trades_count'=> $completedTrades,
                        'score'       => round($score, 4),
                    ];

                } catch (\Throwable $e) {
                    continue;
                }

                $processed++;
                if ($processed >= 20) break; // Limit to 20 combos for performance
            }

            // Sort by score descending
            usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

            return ToolResult::ok([
                'pair'           => $pair,
                'period'         => "{$fromDate} to {$toDate}",
                'combos_tested'  => count($results),
                'top_results'    => array_slice($results, 0, 5),
                'recommendation' => !empty($results) ? $results[0]['params'] : null,
            ]);

        } catch (\Throwable $e) {
            return ToolResult::fail('Optimization failed: ' . $e->getMessage());
        }
    }

    private function buildParameterGrid(
        string $riskProfile,
        bool $optimizeTP,
        bool $optimizeCL,
        bool $optimizeDCA
    ): array {
        $tpRange = $optimizeTP ? match ($riskProfile) {
            'conservative' => [1.0, 1.5, 2.0],
            'aggressive'   => [3.0, 5.0, 7.0],
            default        => [2.0, 3.0, 5.0],
        } : [2.0];

        $clRange = $optimizeCL ? match ($riskProfile) {
            'conservative' => [8, 10, 12],
            'aggressive'   => [20, 25, 30],
            default        => [12, 15, 20],
        } : [20];

        $dcaStepsRange = $optimizeDCA ? match ($riskProfile) {
            'conservative' => [2, 3],
            'aggressive'   => [5, 6, 8],
            default        => [3, 4, 5],
        } : [4];

        $volumeScaleRange = $optimizeDCA ? match ($riskProfile) {
            'conservative' => [1.2, 1.5],
            'aggressive'   => [2.0, 2.5, 3.0],
            default        => [1.5, 2.0],
        } : [2.0];

        $trailTpRange = $optimizeTP ? match ($riskProfile) {
            'conservative' => [0.2, 0.3, 0.5],
            'aggressive'   => [0.3, 0.5, 0.8],
            default        => [0.3, 0.5],
        } : [0.5];

        $grid = [];
        foreach ($tpRange as $tp) {
            foreach ($clRange as $cl) {
                foreach ($dcaStepsRange as $steps) {
                    foreach ($volumeScaleRange as $vs) {
                        foreach ($trailTpRange as $ttp) {
                            $grid[] = [
                                'target_profit'      => $tp,
                                'cut_loss'           => $cl,
                                'max_dca_steps'      => $steps,
                                'price_drop_trigger' => 5,
                                'step_scale'         => 1.0,
                                'volume_scale'       => $vs,
                                'trailing_buy'       => 0,
                                'trailing_tp'        => $ttp,
                            ];
                        }
                    }
                }
            }
        }

        return $grid;
    }

    private function estimateSharpe(array $trades, float $capital): float
    {
        if (count($trades) < 3) return 0;

        $returns = [];
        foreach ($trades as $t) {
            $pnlPct = $t['pnl_pct'] ?? 0;
            $returns[] = $pnlPct / 100;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0;
        foreach ($returns as $r) {
            $variance += pow($r - $mean, 2);
        }
        $std = count($returns) > 1 ? sqrt($variance / (count($returns) - 1)) : 0.01;

        return $std > 0 ? $mean / $std : 0;
    }

    private function calculateScore(
        float $winRate,
        float $totalPnl,
        float $drawdown,
        float $sharpe,
        int   $tradesCount
    ): float {
        $pnlScore = min($totalPnl / 100, 5);
        $winRateScore = $winRate / 20;
        $drawdownPenalty = $drawdown > 50 ? -2 : ($drawdown > 30 ? -1 : 0);
        $sharpeScore = min($sharpe, 3);
        $tradesBonus = min($tradesCount / 10, 1);

        return $pnlScore + $winRateScore + $drawdownPenalty + $sharpeScore + $tradesBonus;
    }
}
