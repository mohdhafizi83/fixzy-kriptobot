<?php

namespace Fixzy\Kriptobot\Backtest;

use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Trading\BaseOrderConditionEngine;
use Fixzy\Kriptobot\Trading\DcaConditionEngine;
use Fixzy\Kriptobot\Trading\OrderManager;
use Fixzy\Kriptobot\Trading\Rules\ExecutionGuardService;
use Fixzy\Kriptobot\Trading\TrailingEngine;

/**
 * BacktestEngine
 *
 * Simulates the DCA bot strategy candle-by-candle using
 * 1-minute historical data from BinanceDataIngester.
 *
 * DESIGN:
 * - Reuses the real engines (BaseOrderConditionEngine, DcaConditionEngine,
 *   ExecutionGuardService, OrderManager, AuditLogger) that are the SAME as the live bot.
 * - Simulates the full cycle: Base Order ~ DCA ~ Take Profit / Cut Loss
 * - Produces a comprehensive performance report.
 *
 * BACKTEST LIMITATIONS:
 * - "Slippage" is not taken into account (buys/sells assumed exactly at candle close price)
 * - Trading fees are not calculated by default (configurable)
 * - Does not support TradingView Webhook / Sentiment signals (no historical data)
 */
class BacktestEngine
{
    private const MIN_CANDLES_FOR_INDICATORS = 50;
    private const DEFAULT_FEE_RATE = 0.001;

    private BinanceDataIngester $ingester;
    private TrailingEngine $trailingEngine;
    private AuditLogger $auditLogger;
    private ExecutionGuardService $executionGuard;

    public function __construct(
        BinanceDataIngester $ingester,
        AuditLogger $auditLogger,
        ExecutionGuardService $executionGuard
    ) {
        $this->ingester        = $ingester;
        $this->trailingEngine  = new TrailingEngine();
        $this->auditLogger     = $auditLogger;
        $this->executionGuard  = $executionGuard;
    }

    /**
     * Main entry point for running a backtest.
     *
     * @param array $config Strategy configuration
     * @param int   $userId Current user ID
     * @param int   $botId  Bot ID
     * @return array        Full backtest report
     */
    public function run(array $config, int $userId = 0, int $botId = 0): array
    {
        $symbol    = strtoupper($config['symbol']);
        $fromDate  = $config['from_date'];
        $toDate    = $config['to_date'];
        $timeframe = $config['timeframe'] ?? '1h';
        $feeRate   = (float)($config['fee_rate'] ?? self::DEFAULT_FEE_RATE);

        $fromMs = strtotime($fromDate . ' 00:00:00') * 1000;
        $toMs   = strtotime($toDate . ' 23:59:59') * 1000;

        $allCandles = $this->ingester->fetchCandles($symbol, $fromMs, $toMs, $timeframe);

        if (count($allCandles) < self::MIN_CANDLES_FOR_INDICATORS) {
            return $this->errorResult("Insufficient data ({$symbol}, {$fromDate} ~ {$toDate}). Need at least " . self::MIN_CANDLES_FOR_INDICATORS . " candles.");
        }

        $result = $this->simulate($allCandles, $config, $feeRate, $symbol, $timeframe, $userId, $botId);

        $result['price_history'] = array_map(fn($c) => [
            't' => (int)$c[0],
            'y' => (float)$c[4]
        ], $allCandles);

        return $result;
    }

    /**
     * Main simulation: iterate candle by candle.
     */
    private function simulate(array $candles, array $config, float $feeRate, string $symbol, string $timeframe, int $userId, int $botId): array
    {
        $totalCandles = count($candles);

        // ─── Strategy Configuration ────────────────────────────────────────────
        $startConditions   = $config['start_conditions']   ?? [];
        $allocatedCapital  = (float)($config['allocated_capital'] ?? 100.0);
        $targetProfit      = (float)($config['target_profit'] ?? 2.0);
        $tpType            = $config['tp_type'] ?? 'average_price';
        $cutLoss           = (float)($config['cut_loss'] ?? 0);
        $maxDcaSteps       = (int)($config['max_dca_steps'] ?? 4);
        $priceDropTrigger  = (float)($config['price_drop_trigger'] ?? 2.0);
        $stepScale         = (float)($config['step_scale'] ?? 1.0);
        $volumeScale       = (float)($config['volume_scale'] ?? 1.5);
        $trailingBuy       = (float)($config['trailing_buy_deviation'] ?? 0);
        $trailingTp        = (float)($config['trailing_tp_deviation'] ?? 0);

        // ─── Build config for the real engines ─────────────────────────────────
        // Filter out external signals (tv_webhook, external_signal, news_sentiment)
        // since there is no historical data — only technical signals decide.
        $filteredConditions = array_values(array_filter($startConditions, fn($c) =>
            !in_array($c['type'] ?? '', ['tv_webhook', 'external_signal', 'news_sentiment'])
        ));
        $baseOrderConfig = ['conditions' => $filteredConditions];

        // Also filter DCA custom conditions if present
        $rawDcaConditions = $config['dca_conditions'] ?? [];
        $filteredDcaConditions = array_values(array_filter($rawDcaConditions, fn($c) =>
            !in_array($c['type'] ?? '', ['tv_webhook', 'external_signal', 'news_sentiment'])
        ));

        $dcaConfig = [
            'price_drop_trigger'         => $priceDropTrigger,
            'step_scale'                 => $stepScale,
            'volume_scale'               => $volumeScale,
            'max_dca_steps'              => $maxDcaSteps,
            'custom_conditions_enabled'  => !empty($config['dca_custom_conditions_enabled']),
            'conditions'                 => $filteredDcaConditions,
            'trailing_enabled'           => !empty($config['dca_trailing_enabled']),
            'trailing_deviation'         => (float)($config['dca_trailing_deviation'] ?? 0.5),
        ];

        $dcaTrailingEnabled    = !empty($dcaConfig['trailing_enabled']);
        $dcaTrailingDeviation  = $dcaTrailingEnabled ? $dcaConfig['trailing_deviation'] : 0;

        $runtimeState = [
            'latest_tv_signal'       => '',
            'latest_external_signal' => '',
            'latest_ai_sentiment'    => '',
        ];

        $baseOrderCfg    = $config['base_order'] ?? [];
        $generalCfg      = $config['general'] ?? [];
        $cooldownSeconds = (int)($baseOrderCfg['cooldown_seconds'] ?? 7200);
        $maxActiveDeals  = (int)($generalCfg['max_active_deals'] ?? 1);

        // ─── Simulation Variables ───────────────────────────────────────────
        $state = 'IDLE';

        $deal = null;
        $closedTrades = [];
        $stats = $this->initStats($allocatedCapital);

        $trailLow  = 0.0;
        $trailHigh = 0.0;

        $dcaTrailWatermark = 0.0;

        // Cooldown / active deal tracking (simulation)
        $lastTradeTimestamp = 0;
        $currentActiveDeals = 0;

        $windowStart = self::MIN_CANDLES_FOR_INDICATORS;

        // ─── MAIN LOOP ──────────────────────────────────────────────────────
        for ($i = $windowStart; $i < $totalCandles; $i++) {

            $window = array_slice($candles, max(0, $i - self::MIN_CANDLES_FOR_INDICATORS), self::MIN_CANDLES_FOR_INDICATORS + 1);

            $currentCandle = $candles[$i];
            $closePrice    = (float)$currentCandle[4];
            $highPrice     = (float)$currentCandle[2];
            $lowPrice      = (float)$currentCandle[3];
            $timestamp     = (int)$currentCandle[0];

            // Candle map for the engine (multi-timeframe format)
            $candleMap = [$timeframe => $window];

            // ── MODE: IDLE ───────────────────────────────────────────────────
            if ($state === 'IDLE') {
                $baseEngine = new BaseOrderConditionEngine($baseOrderConfig, $runtimeState, $candleMap);
                $triggered  = $baseEngine->evaluate();
                $ruleResults = $baseEngine->getLastRuleResults();

                if ($triggered) {
                    if (!empty($ruleResults)) {
                        $this->auditLogger->logRuleEval($botId, $userId, 'BACKTEST_BASE', $ruleResults);
                    }

                    $simulatedNow = (int)($timestamp / 1000);
                    if (!$this->executionGuard->canBuyForBacktest(
                        $userId, $symbol, $cooldownSeconds, $maxActiveDeals,
                        '', $botId, $simulatedNow, $lastTradeTimestamp, $currentActiveDeals
                    )) {
                        $rejectionCtx = $this->executionGuard->getLastRejectionContext();
                        $this->auditLogger->log($botId, $userId, 'REJECTION', "Buy $symbol blocked by Execution Guard", [
                            'symbol'    => $symbol,
                            'rejection' => $rejectionCtx,
                            'timestamp' => date('Y-m-d H:i:s', $simulatedNow),
                        ]);
                        continue;
                    }

                    if ($trailingBuy > 0) {
                        $state    = 'TRAILING_BUY';
                        $trailLow = $closePrice;
                    } else {
                        $deal  = $this->openDeal($closePrice, $allocatedCapital, $maxDcaSteps, $volumeScale, $timestamp, $feeRate, 'Base Order');
                        $state = 'ACTIVE';
                        $stats['total_trades']++;
                        $currentActiveDeals++;

                        $this->auditLogger->logTrade($botId, $userId, [
                            'action'        => 'BACKTEST_BUY',
                            'symbol'        => $symbol,
                            'execute_price' => $closePrice,
                            'coin_amount'   => $deal['total_coins'],
                            'usdt_amount'   => $deal['base_order_usdt'],
                        ]);
                    }
                }
                continue;
            }

            // ── MODE: TRAILING_BUY ────────────────────────────────────────────
            if ($state === 'TRAILING_BUY') {
                $trailResult = $this->trailingEngine->evaluateTrailingBuy($closePrice, $trailLow, $trailingBuy);
                $trailLow    = $trailResult['new_low'];

                if ($trailResult['action'] === 'BUY') {
                    $simulatedNow = (int)($timestamp / 1000);
                    if (!$this->executionGuard->canBuyForBacktest(
                        $userId, $symbol, $cooldownSeconds, $maxActiveDeals,
                        '', $botId, $simulatedNow, $lastTradeTimestamp, $currentActiveDeals
                    )) {
                        $rejectionCtx = $this->executionGuard->getLastRejectionContext();
                        $this->auditLogger->log($botId, $userId, 'REJECTION', "Trailing Buy $symbol blocked by Execution Guard", [
                            'symbol'    => $symbol,
                            'rejection' => $rejectionCtx,
                            'timestamp' => date('Y-m-d H:i:s', $simulatedNow),
                        ]);
                        continue;
                    }

                    $deal  = $this->openDeal($closePrice, $allocatedCapital, $maxDcaSteps, $volumeScale, $timestamp, $feeRate, 'Trailing Buy');
                    $state = 'ACTIVE';
                    $trailLow = 0.0;
                    $stats['total_trades']++;
                    $currentActiveDeals++;

                    $this->auditLogger->logTrade($botId, $userId, [
                        'action'        => 'BACKTEST_BUY',
                        'symbol'        => $symbol,
                        'execute_price' => $closePrice,
                        'coin_amount'   => $deal['total_coins'],
                        'usdt_amount'   => $deal['base_order_usdt'],
                    ]);
                }
                continue;
            }

            // ── MODE: ACTIVE / TRAILING_TP ────────────────────────────────────
            if ($state === 'ACTIVE' || $state === 'TRAILING_TP') {

                $avgPrice      = $deal['avg_price'];
                $currentPnlPct = (($closePrice - $avgPrice) / $avgPrice) * 100;

                $requiredTpPct = $targetProfit;
                if ($tpType === 'base_order' && $deal['total_spent'] > 0) {
                    $requiredTpPct = $targetProfit * ($deal['base_order_usdt'] / $deal['total_spent']);
                }

                // 1. CUT LOSS ─────────────────────────────────────────────
                if ($cutLoss > 0 && $currentPnlPct <= -abs($cutLoss)) {
                    $closedTrade = $this->closeDeal($deal, $closePrice, $timestamp, 'CUT_LOSS', $feeRate);
                    $closedTrades[] = $closedTrade;
                    $this->updateStats($stats, $closedTrade);
                    $deal  = null;
                    $state = 'IDLE';
                    $trailHigh = 0.0;
                    $dcaTrailWatermark = 0.0;
                    $lastTradeTimestamp = (int)($timestamp / 1000);
                    $currentActiveDeals = max(0, $currentActiveDeals - 1);

                    $this->auditLogger->logSell($botId, $userId, 'BACKTEST_CUT_LOSS', [
                        'symbol'      => $symbol,
                        'sell_price'  => $closePrice,
                        'coin_sold'   => $closedTrade['total_coins'] ?? 0,
                        'pnl_percent' => $closedTrade['pnl_pct'],
                        'usdt_value'  => $closedTrade['net_revenue'],
                        'reason'      => 'CUT_LOSS',
                        'status'      => 'SUCCESS',
                    ]);
                    continue;
                }

                // 2. TRAILING TAKE PROFIT ──────────────────────────────────
                if ($trailingTp > 0 && $currentPnlPct >= $requiredTpPct) {
                    if ($state === 'ACTIVE') {
                        $state     = 'TRAILING_TP';
                        $trailHigh = $closePrice;
                    } else {
                        $trailResult = $this->trailingEngine->evaluateTrailingSell($closePrice, $trailHigh, $trailingTp);
                        $trailHigh   = $trailResult['new_high'];

                        if ($trailResult['action'] === 'SELL') {
                            $closedTrade = $this->closeDeal($deal, $closePrice, $timestamp, 'TAKE_PROFIT', $feeRate);
                            $closedTrades[] = $closedTrade;
                            $this->updateStats($stats, $closedTrade);
                            $deal      = null;
                            $state     = 'IDLE';
                            $trailHigh = 0.0;
                            $dcaTrailWatermark = 0.0;
                            $lastTradeTimestamp = (int)($timestamp / 1000);
                            $currentActiveDeals = max(0, $currentActiveDeals - 1);

                            $this->auditLogger->logSell($botId, $userId, 'BACKTEST_TAKE_PROFIT', [
                                'symbol'      => $symbol,
                                'sell_price'  => $closePrice,
                                'coin_sold'   => $closedTrade['total_coins'] ?? 0,
                                'pnl_percent' => $closedTrade['pnl_pct'],
                                'usdt_value'  => $closedTrade['net_revenue'],
                                'reason'      => 'TAKE_PROFIT',
                                'status'      => 'SUCCESS',
                            ]);
                            continue;
                        }
                    }
                }

                // 3. REGULAR TAKE PROFIT (no trailing) ───────────────────
                if ($trailingTp <= 0 && $currentPnlPct >= $requiredTpPct) {
                    $closedTrade = $this->closeDeal($deal, $closePrice, $timestamp, 'TAKE_PROFIT', $feeRate);
                    $closedTrades[] = $closedTrade;
                    $this->updateStats($stats, $closedTrade);
                    $deal  = null;
                    $state = 'IDLE';
                    $trailHigh = 0.0;
                    $dcaTrailWatermark = 0.0;
                    $lastTradeTimestamp = (int)($timestamp / 1000);
                    $currentActiveDeals = max(0, $currentActiveDeals - 1);

                    $this->auditLogger->logSell($botId, $userId, 'BACKTEST_TAKE_PROFIT', [
                        'symbol'      => $symbol,
                        'sell_price'  => $closePrice,
                        'coin_sold'   => $closedTrade['total_coins'] ?? 0,
                        'pnl_percent' => $closedTrade['pnl_pct'],
                        'usdt_value'  => $closedTrade['net_revenue'],
                        'reason'      => 'TAKE_PROFIT',
                        'status'      => 'SUCCESS',
                    ]);
                    continue;
                }

                // 4. DCA (Safety Orders) ──────────────────────────────────
                if ($deal['dca_step'] < $deal['max_dca_steps']) {

                    $dcaEngine = new DcaConditionEngine(
                        $dcaConfig,
                        $deal['avg_price'],
                        $closePrice,
                        $deal['dca_step'],
                        '',
                        '',
                        $candleMap
                    );

                    if ($dcaEngine->evaluate()) {
                        $dcaEvalDetails = $dcaEngine->getLastEvaluationDetails();
                        $dcaRuleResults = $dcaEngine->getLastRuleResults();

                        $this->auditLogger->log($botId, $userId, 'DCA_EVAL',
                            'DCA Step ' . ($deal['dca_step'] + 1) . ' PASSED for ' . $symbol, [
                                'dca_step'     => $deal['dca_step'] + 1,
                                'price_drop'   => $dcaEvalDetails,
                                'rule_results' => $dcaRuleResults,
                            ]);

                        if (!empty($dcaRuleResults)) {
                            $this->auditLogger->logRuleEval($botId, $userId,
                                'BACKTEST_DCA Step ' . ($deal['dca_step'] + 1), $dcaRuleResults);
                        }

                        // Trailing DCA — wait for a bounce before executing
                        if ($dcaTrailingEnabled) {
                            $trailResult = $this->trailingEngine->evaluateTrailingBuy(
                                $closePrice, $dcaTrailWatermark, $dcaTrailingDeviation
                            );
                            $dcaTrailWatermark = $trailResult['new_low'];

                            if ($trailResult['action'] === 'WAIT') {
                                continue;
                            }
                            $dcaTrailWatermark = 0.0;
                        }

                        // Execute DCA
                        $dcaSize = $deal['base_order_usdt'] * pow($volumeScale, $deal['dca_step'] + 1);
                        $coinsBought = ($dcaSize * (1 - $feeRate)) / $closePrice;
                        $deal['total_coins']  += $coinsBought;
                        $deal['total_spent']  += $dcaSize;
                        $deal['avg_price']     = $deal['total_spent'] / $deal['total_coins'];
                        $deal['dca_step']++;

                        $dcaEvent = [
                            'type'   => 'BUY',
                            'label'  => 'DCA ' . $deal['dca_step'],
                            'price'  => $closePrice,
                            'time'   => $timestamp,
                            'usdt'   => $dcaSize,
                            'reason' => 'Price Drop ' . round($dcaEvalDetails['actual_drop_percent'], 2) . '%'
                        ];

                        $deal['dca_history'][] = $dcaEvent;
                        $deal['events'][]      = $dcaEvent;
                        $stats['total_dca_executions']++;

                        $this->auditLogger->logTrade($botId, $userId, [
                            'action'        => 'BACKTEST_DCA_BUY',
                            'symbol'        => $symbol,
                            'execute_price' => $closePrice,
                            'coin_amount'   => $coinsBought,
                            'usdt_amount'   => $dcaSize,
                            'dca_step'      => $deal['dca_step'],
                        ]);
                    }
                }

                continue;
            }
        }

        // Open deal at the end of the simulation
        if ($deal !== null) {
            $lastPrice = (float)end($candles)[4];
            $deal['unrealized_pnl_pct']  = (($lastPrice - $deal['avg_price']) / $deal['avg_price']) * 100;
            $deal['unrealized_pnl_usdt'] = ($lastPrice - $deal['avg_price']) * $deal['total_coins'];
            $stats['open_deal'] = $deal;
        }

        $this->finalizeStats($stats, $closedTrades, $allocatedCapital, $feeRate);

        return [
            'config'        => [
                'symbol'     => $symbol,
                'timeframe'  => $timeframe,
                'from_date'  => $config['from_date'],
                'to_date'    => $config['to_date'],
                'strategy'   => $this->summarizeStrategy($config),
            ],
            'stats'         => $stats,
            'trades'        => $closedTrades,
            'candles_total' => $totalCandles,
        ];
    }

    /**
     * Open a new deal (Base Order).
     *
     * Uses OrderManager::calculateBaseOrderSize() to compute
     * the same size as the live bot.
     */
    private function openDeal(float $price, float $capital, int $maxDca, float $volScale, int $timestamp, float $feeRate, string $reason = ''): array
    {
        $baseOrderUsdt = OrderManager::calculateBaseOrderSize($capital, $maxDca, $volScale);
        $coinsBought   = ($baseOrderUsdt * (1 - $feeRate)) / $price;

        $event = [
            'type'   => 'BUY',
            'label'  => 'Base Order',
            'price'  => $price,
            'time'   => $timestamp,
            'usdt'   => $baseOrderUsdt,
            'reason' => $reason
        ];

        return [
            'entry_price'      => $price,
            'avg_price'        => $price,
            'base_order_usdt'  => $baseOrderUsdt,
            'total_coins'      => $coinsBought,
            'total_spent'      => $baseOrderUsdt,
            'dca_step'         => 0,
            'max_dca_steps'    => $maxDca,
            'entry_timestamp'  => $timestamp,
            'dca_history'      => [],
            'events'           => [$event]
        ];
    }

    /**
     * Close a deal and calculate PNL.
     */
    private function closeDeal(array $deal, float $price, int $timestamp, string $reason, float $feeRate): array
    {
        $grossRevenue = $deal['total_coins'] * $price;
        $sellFee      = $grossRevenue * $feeRate;
        $netRevenue   = $grossRevenue - $sellFee;
        $pnlUsdt      = $netRevenue - $deal['total_spent'];
        $pnlPct       = ($pnlUsdt / $deal['total_spent']) * 100;
        $durationSec  = ($timestamp - $deal['entry_timestamp']) / 1000;

        $event = [
            'type'   => 'SELL',
            'label'  => str_replace('_', ' ', $reason),
            'price'  => $price,
            'time'   => $timestamp,
            'usdt'   => $netRevenue,
            'pnl'    => $pnlUsdt,
            'reason' => $reason === 'TAKE_PROFIT' ? 'Target Profit Reached' : 'Stop Loss Triggered'
        ];

        $deal['events'][] = $event;

        return [
            'entry_price'     => $deal['entry_price'],
            'exit_price'      => $price,
            'avg_entry_price' => $deal['avg_price'],
            'total_spent'     => $deal['total_spent'],
            'total_coins'     => $deal['total_coins'],
            'net_revenue'     => $netRevenue,
            'pnl_usdt'        => round($pnlUsdt, 4),
            'pnl_pct'         => round($pnlPct, 4),
            'dca_steps_used'  => $deal['dca_step'],
            'duration_sec'    => $durationSec,
            'close_reason'    => $reason,
            'entry_time'      => date('Y-m-d H:i', $deal['entry_timestamp'] / 1000),
            'exit_time'       => date('Y-m-d H:i', $timestamp / 1000),
            'dca_history'     => $deal['dca_history'],
            'events'          => $deal['events']
        ];
    }

    /**
     * Initialize the statistics structure.
     */
    private function initStats(float $capital): array
    {
        return [
            'initial_capital'      => $capital,
            'total_trades'         => 0,
            'winning_trades'       => 0,
            'losing_trades'        => 0,
            'total_dca_executions' => 0,
            'total_pnl_usdt'       => 0.0,
            'total_pnl_pct'        => 0.0,
            'win_rate'             => 0.0,
            'avg_trade_pnl'        => 0.0,
            'avg_trade_duration'   => '—',
            'best_trade'           => null,
            'worst_trade'          => null,
            'max_drawdown_pct'     => 0.0,
            'total_fees'           => 0.0,
            'open_deal'            => null,
        ];
    }

    /**
     * Update statistics after each trade is closed.
     */
    private function updateStats(array &$stats, array $trade): void
    {
        $stats['total_pnl_usdt'] += $trade['pnl_usdt'];

        if ($trade['pnl_usdt'] >= 0) {
            $stats['winning_trades']++;
        } else {
            $stats['losing_trades']++;
        }

        if ($stats['best_trade'] === null || $trade['pnl_pct'] > $stats['best_trade']['pnl_pct']) {
            $stats['best_trade'] = $trade;
        }
        if ($stats['worst_trade'] === null || $trade['pnl_pct'] < $stats['worst_trade']['pnl_pct']) {
            $stats['worst_trade'] = $trade;
        }
    }

    /**
     * Calculate final statistics after the simulation completes.
     */
    private function finalizeStats(array &$stats, array $trades, float $capital, float $feeRate): void
    {
        $completed = $stats['total_trades'] - ($stats['open_deal'] ? 1 : 0);

        if ($completed > 0) {
            $stats['win_rate']      = round(($stats['winning_trades'] / $completed) * 100, 1);
            $stats['avg_trade_pnl'] = round($stats['total_pnl_usdt'] / $completed, 4);
            $stats['total_pnl_pct'] = round(($stats['total_pnl_usdt'] / $capital) * 100, 2);

            $totalDuration = array_sum(array_column($trades, 'duration_sec'));
            $avgSec = $totalDuration / count($trades);
            $stats['avg_trade_duration'] = $this->formatDuration($avgSec);
        }

        $runningPnl  = 0;
        $peak        = 0;
        $maxDrawdown = 0;
        foreach ($trades as $t) {
            $runningPnl += $t['pnl_usdt'];
            if ($runningPnl > $peak) {
                $peak = $runningPnl;
            }
            $drawdown = $peak - $runningPnl;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }
        $stats['max_drawdown_pct'] = $capital > 0 ? round(($maxDrawdown / $capital) * 100, 2) : 0;
    }

    /**
     * Format a duration in seconds into a human-readable form.
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 3600) return round($seconds / 60, 1) . ' min';
        if ($seconds < 86400) return round($seconds / 3600, 1) . ' hours';
        return round($seconds / 86400, 1) . ' days';
    }

    /**
     * Summarize the strategy configuration for the report.
     */
    private function summarizeStrategy(array $config): array
    {
        return [
            'conditions'       => $config['start_conditions'] ?? [],
            'target_profit'    => ($config['target_profit'] ?? 2.0) . '%',
            'tp_type'          => $config['tp_type'] ?? 'average_price',
            'cut_loss'         => ($config['cut_loss'] ?? 0) > 0 ? $config['cut_loss'] . '%' : 'None',
            'max_dca'          => $config['max_dca_steps'] ?? 4,
            'dca_drop_trigger' => ($config['price_drop_trigger'] ?? 2.0) . '%',
            'volume_scale'     => $config['volume_scale'] ?? 1.5,
            'step_scale'       => $config['step_scale'] ?? 1.0,
            'trailing_buy'     => ($config['trailing_buy_deviation'] ?? 0) > 0 ? $config['trailing_buy_deviation'] . '%' : 'None',
            'trailing_tp'      => ($config['trailing_tp_deviation'] ?? 0) > 0 ? $config['trailing_tp_deviation'] . '%' : 'None',
        ];
    }

    /**
     * Return a uniform error result.
     */
    private function errorResult(string $message): array
    {
        return [
            'error'   => true,
            'message' => $message,
            'stats'   => null,
            'trades'  => [],
        ];
    }
}
