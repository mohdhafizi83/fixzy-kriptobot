<?php
/**
 * CronAgentOptimizer.php
 *
 * Cron job script that periodically monitors bot performance and suggests
 * improvements through the AI Agent.
 *
 * RECOMMENDED SCHEDULE: Every 6 hours
 * Cron: 0 *\/4 * * * php /path/to/kriptobot/bin/cron_agent_optimizer.php
 *
 * ACTIONS:
 * 1. Check all active agent_managed bots
 * 2. Analyze recent trading performance (7-30 days)
 * 3. If performance degrades (win rate < 50% or drawdown > limit), generate a proposal
 * 4. Save as agent_decisions (pending_approval)
 * 5. Send a Telegram notification
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
\Fixzy\Kriptobot\Config\Config::load();

use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Agent\AgentOrchestrator;
use Fixzy\Kriptobot\Agent\Approval\ApprovalManager;
use Fixzy\Kriptobot\Notifications\NotificationService;

// Lock file to prevent overlapping runs
$lockFile = __DIR__ . '/cron_agent_optimizer.lock';
$lockHandle = @fopen($lockFile, 'w+');
if (!$lockHandle) {
    echo date('Y-m-d H:i:s') . " [ERROR] Cannot create lock file {$lockFile}\n";
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [SKIP] CronAgentOptimizer already running.\n";
    fclose($lockHandle);
    exit;
}

echo "=========================================================\n";
echo " 🤖 FIXZY KRIPTOBOT AI AGENT — AUTO OPTIMIZER\n";
echo "=========================================================\n";
echo date('Y-m-d H:i:s') . " Starting optimization cycle...\n\n";

$conn = Database::getConnection();
$approvalManager = new ApprovalManager();

// Fetch all users with agent settings enabled
$agentUsers = $conn->executeQuery(
    "SELECT u.id, u.telegram_chat_id, u.email,
            s.agent_enabled, s.autonomy_mode, s.max_capital_per_bot, s.max_total_capital,
            s.allowed_actions, s.telegram_notifications
     FROM users u
     JOIN agent_settings s ON u.id = s.user_id
     WHERE s.agent_enabled = 1"
)->fetchAllAssociative();

echo "Found " . count($agentUsers) . " users with AI Agent enabled.\n\n";

$optimizationPromptTemplate = <<<'PROMPT'
You are the Fixzy Kriptobot Auto-Optimizer. Review the following bot's recent performance data and market conditions.

## Bot Current Status
- Bot ID: {bot_id}
- Pair: {coin_pair}
- Config: {config_summary}
- Runtime: {runtime_summary}

## Recent Trade Performance
- Total trades: {total_trades}
- Win rate: {win_rate}%
- Total PNL: {total_pnl} USDT
- Max drawdown: {max_drawdown}%

## Current Market Conditions
{market_data}

## Task
Based on the performance data and market conditions:
1. Identify if the bot needs optimization (worsening win rate, high drawdown, etc.)
2. If improvement is needed, propose SPECIFIC configuration changes
3. If the bot is performing well, confirm and suggest minor tweaks if any

Respond with:
- A brief analysis of current performance
- If optimization needed: a modified full bot configuration in JSON format
- If no changes needed: explain why the current config is still optimal
- Justification for your recommendation

Do NOT use tool calls — use the data provided directly.
PROMPT;

$totalOptimized = 0;
$totalProposals = 0;

foreach ($agentUsers as $user) {
    $userId = (int)$user['id'];
    $allowedActions = json_decode($user['allowed_actions'] ?? '[]', true) ?: [];
    $isFullAutonomy = $user['autonomy_mode'] === 'full_autonomy';
    $canUpdateConfig = in_array('update_config', $allowedActions);

    if (!$canUpdateConfig) {
        echo "  User #{$userId}: update_config not allowed — skipping\n";
        continue;
    }

    // Fetch agent-managed bots for this user
    $bots = $conn->executeQuery(
        "SELECT * FROM bots WHERE user_id = ? AND is_agent_managed = 1 AND status = 1",
        [$userId]
    )->fetchAllAssociative();

    echo "  User #{$userId}: " . count($bots) . " agent-managed bots found.\n";

    foreach ($bots as $bot) {
        $botId = (int)$bot['id'];
        $config = json_decode($bot['configuration'], true) ?? [];
        $runtime = json_decode($bot['runtime_state'], true) ?? [];

        // Fetch recent trades
        $trades = $conn->executeQuery(
            "SELECT * FROM trade_logs WHERE bot_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC",
            [$botId]
        )->fetchAllAssociative();

        // Fetch recent audit events
        $cuts = $conn->executeQuery(
            "SELECT COUNT(*) as cnt FROM audit_logs WHERE bot_id = ? AND event_type LIKE '%CUT_LOSS%' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$botId]
        )->fetchOne();

        $recentCutLosses = (int)$cuts;

        // Skip bots with no trade history
        if (count($trades) < 3) {
            echo "    Bot #{$botId}: Insufficient trade history (< 3 trades) — skipping\n";
            continue;
        }

        // Analyze performance
        $sells = array_filter($trades, fn($t) => strpos($t['action'] ?? '', 'SELL') !== false
            || strpos($t['action'] ?? '', 'TAKE_PROFIT') !== false
            || strpos($t['action'] ?? '', 'CUT_LOSS') !== false
        );

        $profitTrades = array_filter($sells, function($t) {
            $data = json_decode($t['audit_data'] ?? '{}', true);
            return ($data['pnl_percent'] ?? 0) > 0;
        });

        $totalPnl = 0;
        foreach ($sells as $sell) {
            $data = json_decode($sell['audit_data'] ?? '{}', true);
            $totalPnl += ($data['pnl_usdt_value'] ?? $data['usdt_value'] ?? 0);
        }

        $winRate = count($sells) > 0
            ? round((count($profitTrades) / count($sells)) * 100, 1)
            : 0;

        // Determine if optimization is needed
        $needsOptimization = false;
        $reasons = [];

        if ($winRate < 50) {
            $needsOptimization = true;
            $reasons[] = "Win rate below 50% ({$winRate}%)";
        }

        if ($totalPnl < 0) {
            $needsOptimization = true;
            $reasons[] = "Negative total PNL ({$totalPnl} USDT)";
        }

        if ($recentCutLosses >= 2) {
            $needsOptimization = true;
            $reasons[] = "{$recentCutLosses} cut losses in 7 days";
        }

        $currentDrawdown = $runtime['live_pnl_percent'] ?? 0;
        if ($currentDrawdown < -15) {
            $needsOptimization = true;
            $reasons[] = "Current drawdown > 15% ({$currentDrawdown}%)";
        }

        if (!$needsOptimization) {
            echo "    Bot #{$botId}: Performance OK (WR: {$winRate}%, PNL: {$totalPnl}) — no changes needed\n";
            continue;
        }

        echo "    Bot #{$botId}: Optimization needed — " . implode(', ', $reasons) . "\n";

        // Build optimization prompt
        $configSummary = [
            'tp'  => $config['risk_management']['target_profit'] ?? 'N/A',
            'cl'  => $config['risk_management']['cut_loss_percent'] ?? 'N/A',
            'dca' => $config['dca']['max_steps'] ?? 'N/A',
        ];

        $runtimeSummary = [
            'status'   => $runtime['status'] ?? 'IDLE',
            'pnl_pct'  => $runtime['live_pnl_percent'] ?? 0,
            'holdings' => $runtime['current_holdings'] ?? 0,
        ];

        $marketDataSummary = "Market data not fetched in cron (use AnalyzeMarket tool)";

        $prompt = str_replace(
            ['{bot_id}', '{coin_pair}', '{config_summary}', '{runtime_summary}',
             '{total_trades}', '{win_rate}', '{total_pnl}', '{max_drawdown}', '{market_data}'],
            [
                (string) $botId,
                (string) $bot['coin_pair'],
                json_encode($configSummary),
                json_encode($runtimeSummary),
                (string) count($trades),
                (string) $winRate,
                (string) $totalPnl,
                (string) $currentDrawdown,
                (string) $marketDataSummary,
            ],
            $optimizationPromptTemplate
        );

        try {
            $aiCfg = \Fixzy\Kriptobot\Config\Config::aiConfig();
            $aiKey = $aiCfg['api_key'];
            if (empty($aiKey)) {
                echo "AI_API_KEY/AI_BASE_URL/AI_MODEL not configured. Skipping AI optimization.\n";
                exit(0);
            }

            $orchestrator = new AgentOrchestrator($aiKey, $aiCfg['model'], $aiCfg['base_url']);
            $result = $orchestrator->processMessage($userId, $prompt);

            if (!empty($result['proposal'])) {
                $proposal = $result['proposal'];

                if (empty($proposal['decision_id'])) {
                    echo "      ⚠️ Proposal without decision_id — skip\n";
                    continue;
                }

                if ($isFullAutonomy) {
                    $approval = $approvalManager->approve(
                        $proposal['decision_id'], $userId, true
                    );
                    echo "      ✅ Auto-applied optimization (full autonomy)\n";
                } else {
                    echo "      📋 Optimization proposal created — awaiting approval\n";
                }

                $totalProposals++;

                // Send Telegram notification if configured
                if ($user['telegram_notifications'] && !empty($user['telegram_chat_id'])) {
                    $telegramToken = \Fixzy\Kriptobot\Config\Config::get('TELEGRAM_BOT_TOKEN');
                    $notifier = new NotificationService($telegramToken, $user['telegram_chat_id']);
                    $notifier->sendTelegramAlert(
                        "🔄 <b>Auto-Optimizer: Bot #{$botId}</b>\n\n"
                        . "Bot <b>{$bot['coin_pair']}</b> performance detected as declining:\n"
                        . "• Win Rate: {$winRate}%\n"
                        . "• PNL: {$totalPnl} USDT\n"
                        . "• Reasons: " . implode(', ', $reasons) . "\n\n"
                        . ($isFullAutonomy
                            ? "✅ <b>Optimization auto-applied</b>\n"
                            : "📋 <b>Optimization proposal ready for review</b>\n\n"
                            . "Reply with /approve {$proposal['decision_id']} to approve.")
                    );
                }
            }

            $totalOptimized++;

        } catch (\Throwable $e) {
            echo "      ❌ Optimization failed — see error log\n";
            error_log("CronAgentOptimizer bot #{$botId} error: " . $e->getMessage());
        }

        // Rate limit: small delay between bots
        usleep(200000);
    }

    echo "\n";
}

echo "=========================================================\n";
echo " Summary: {$totalOptimized} bots analyzed, {$totalProposals} optimization proposals generated.\n";
echo " Completed at: " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================\n";

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
