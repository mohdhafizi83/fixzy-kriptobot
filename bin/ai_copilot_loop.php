<?php

/**
 * AI Copilot L1 slow loop (Phase 2 — shadow mode).
 *
 * Runs OUTSIDE the 1-minute daemon loop: one LLM call per bot per CLOSED
 * decision-timeframe candle. Decisions are validated, cached into the bot's
 * runtime_state (ai_copilot_cache) and fully logged to audit_logs
 * (AI_L1_DECISION / AI_L1_ERROR). In shadow mode nothing is acted upon — the
 * daemon never reads this cache yet; the web report page shows "AI would do X,
 * bot did Y".
 *
 * Usage:
 *   php bin/ai_copilot_loop.php --once          (cron/timer friendly)
 *   php bin/ai_copilot_loop.php --loop=60       (run forever, 60s sleep)
 *   php bin/ai_copilot_loop.php --once --bot=3  (single bot, debugging)
 *
 * If the AI feature is not configured/enabled the script exits 0 immediately;
 * an unreachable LLM only logs an error and keeps the previous cache — the
 * trading daemon is never affected.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Fixzy\Kriptobot\Config\AiCopilotConfig;
use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Database\AuditLogger;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Intelligence\AiCopilot\DataPackBuilder;
use Fixzy\Kriptobot\Intelligence\AiCopilot\L1Analyzer;
use Fixzy\Kriptobot\Service\AgentSettingsService;
use Fixzy\Kriptobot\Service\FeatureStatusService;
use Fixzy\Kriptobot\Trading\AdvisorBacktestGate;
use Fixzy\Kriptobot\Trading\AdvisorParams;
use Fixzy\Kriptobot\Trading\ExchangeService;

Config::load();

// --- single-instance lock (same pattern as bot_daemon) -----------------------
$lockHandle = fopen(__DIR__ . '/ai_copilot_loop.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another ai_copilot_loop is already running; exiting.\n";
    exit(0);
}

$args = getopt('', ['once', 'loop::', 'bot::']);
$loopSec = isset($args['loop']) ? max(15, (int)($args['loop'] ?: 60)) : 0;
$onlyBot = isset($args['bot']) ? (int)$args['bot'] : null;

function copilotEcho(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

/** Replace invalid UTF-8 byte sequences so json_encode of audit context never fails. */
function scrubUtf8(string $s): string
{
    return mb_check_encoding($s, 'UTF-8') ? $s : (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8');
}

/** Fear & Greed (alternative.me, free) cached 1h in app_settings; null on failure. */
function fetchFearGreed(\Doctrine\DBAL\Connection $db): ?int
{
    try {
        $row = $db->fetchAssociative("SELECT value, updated_at FROM app_settings WHERE setting_key = 'ai_copilot.fng'");
        if (is_array($row) && (time() - strtotime((string)$row['updated_at'])) < 3600) {
            $v = json_decode((string)$row['value'], true);
            return is_array($v) && isset($v['v']) ? (int)$v['v'] : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = @file_get_contents('https://api.alternative.me/fng/?limit=1', false, $ctx);
        $val = null;
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['data'][0]['value'])) {
                $val = (int)$j['data'][0]['value'];
            }
        }
        $db->executeStatement(
            "INSERT INTO app_settings (setting_key, value, encrypted, updated_at) VALUES ('ai_copilot.fng', ?, 0, ?)
             ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            [json_encode(['v' => $val]), date('Y-m-d H:i:s')]
        );
        return $val;
    } catch (Throwable) {
        return null;
    }
}

$candlesPerTf = ['5m' => 400, '1h' => 100, '4h' => 60, '1d' => 60];

$runOnce = static function () use ($onlyBot, $candlesPerTf): void {
    $analyzer = L1Analyzer::fromConfig();
    if ($analyzer === null) {
        copilotEcho('AI Copilot L1: AI_API_KEY/AI_BASE_URL/AI_MODEL not set — nothing to do.');
        return;
    }
    try {
        $conn = Database::getConnection();
        $features = new FeatureStatusService($conn);
        if (!$features->isEnabled('ai')) {
            copilotEcho('AI Copilot L1: AI feature disabled in FeatureStatus — nothing to do.');
            return;
        }

        $auditLogger = new AuditLogger($conn);
        $agentSettingsService = new AgentSettingsService($conn);
        $botSql = "SELECT id, user_id, coin_pair, runtime_state, configuration FROM bots WHERE status = 1";
        $botParams = [];
        if ($onlyBot !== null) {
            $botSql .= " AND id = ?";
            $botParams[] = $onlyBot;
        }
        $stmt = $conn->executeQuery($botSql, $botParams);
        $bots = $stmt->fetchAllAssociative();
    } catch (Throwable $e) {
        // Broken schema/DB: exit cleanly; the trading daemon is unaffected.
        copilotEcho('AI Copilot L1: prologue failed — ' . $e->getMessage());
        return;
    }

    $fng = fetchFearGreed($conn);
    $agentCache = [];
    $exchangeCache = [];
    $decided = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($bots as $botData) {
        $botId = (int)$botData['id'];
        $userId = (int)$botData['user_id'];
        $symbol = (string)$botData['coin_pair'];
        $state = json_decode((string)$botData['runtime_state'], true) ?: [];
        $ai = AiCopilotConfig::resolve($botData['configuration'] === null ? null : json_decode((string)$botData['configuration'], true));

        if (!isset($agentCache[$userId])) {
            try {
                $agentCache[$userId] = $agentSettingsService->get($userId);
            } catch (Throwable) {
                $agentCache[$userId] = ['agent_enabled' => false];
            }
        }
        $mode = AiCopilotConfig::effectiveTrustLevel($ai, !empty($agentCache[$userId]['agent_enabled']), true);
        if ($mode === 'off') {
            continue;
        }

        try {
            $isTestnet = Config::get('BINANCE_TESTNET', '1') === '1';
            if (!isset($exchangeCache[$userId])) {
                $keyData = $conn->executeQuery(
                    "SELECT exchange_name, api_key, api_secret_encrypted FROM user_api_keys WHERE user_id = ? LIMIT 1",
                    [$userId]
                )->fetchAssociative();
                $exchangeCache[$userId] = new ExchangeService(
                    $keyData ? (string)$keyData['exchange_name'] : 'binance',
                    $keyData ? (string)$keyData['api_key'] : '',
                    $keyData ? (string)base64_decode((string)$keyData['api_secret_encrypted']) : '',
                    $isTestnet
                );
            }
            $exchange = $exchangeCache[$userId];

            $tf = $ai['decision_timeframe'];
            $candles = $exchange->getHistoricalCandles($symbol, $tf, 100);
            // last CLOSED candle only (ccxt includes the forming one last)
            $tfMs = DataPackBuilder::tfMinutes($tf) * 60 * 1000;
            $closed = null;
            foreach (array_reverse($candles) as $c) {
                if (((float)$c[0] + $tfMs) <= (microtime(true) * 1000)) {
                    $closed = $c;
                    break;
                }
            }
            if ($closed === null) {
                copilotEcho("bot #$botId: no closed {$tf} candle yet — skip");
                $skipped++;
                continue;
            }
            $candleTime = (int)$closed[0];
            $cache = is_array($state['ai_copilot_cache'] ?? null) ? $state['ai_copilot_cache'] : [];
            if ((int)($cache['candle_time'] ?? -1) === $candleTime) {
                $skipped++; // already decided for this candle
                continue;
            }

            // multi-TF candles (decision TF reused from above)
            $tfCandles = [$tf => $candles];
            foreach ($candlesPerTf as $oneTf => $limit) {
                if (!isset($tfCandles[$oneTf])) {
                    $tfCandles[$oneTf] = $exchange->getHistoricalCandles($symbol, $oneTf, $limit);
                }
            }

            // position snapshot
            $position = [];
            $holdings = (float)($state['current_holdings'] ?? 0);
            if ($holdings > 0) {
                $avg = (float)($state['average_entry_price'] ?? 0);
                $last = (float)$exchange->getCurrentPrice($symbol);
                $cfg = json_decode((string)$botData['configuration'], true) ?: [];
                $position = [
                    'avg_price' => $avg,
                    'pnl_pct' => $avg > 0 ? ($last - $avg) / $avg * 100 : null,
                    'dca_step' => (int)($state['dca_current_step'] ?? 0),
                    'dist_tp_pct' => isset($cfg['take_profit']['target_percent']) ? (float)$cfg['take_profit']['target_percent'] : null,
                    'dist_sl_pct' => isset($cfg['stop_loss']['percent']) ? (float)$cfg['stop_loss']['percent'] : null,
                ];
            }

            // account snapshot (best effort)
            $account = [];
            try {
                $bal = $exchange->getExchange()->fetch_balance();
                if (isset($bal['free']['USDT'])) {
                    $account['free_usdt'] = (float)$bal['free']['USDT'];
                }
            } catch (Throwable) {
                // optional field — degrade gracefully
            }

            // last 10 trades for this bot
            $trades = [];
            foreach ($conn->executeQuery(
                "SELECT action, audit_data FROM trade_logs WHERE bot_id = ? ORDER BY id DESC LIMIT 10",
                [$botId]
            )->fetchAllAssociative() as $t) {
                $ad = json_decode((string)$t['audit_data'], true) ?: [];
                $trades[] = [
                    'a' => (string)$t['action'],
                    'pnl' => isset($ad['pnl_percent']) ? round((float)$ad['pnl_percent'], 1) : null,
                ];
            }

            $pack = DataPackBuilder::build(
                $symbol,
                $tfCandles,
                $position,
                $account,
                $trades,
                $cache['decision'] ?? null,
                $fng,
                isset($cache['created_at']) ? max(0, time() - (int)$cache['created_at']) : 0
            );

            copilotEcho("bot #$botId ($symbol, $mode): calling L1 for candle " . date('Y-m-d H:i', intdiv($candleTime, 1000)) . " …");
            $result = $analyzer->analyze($symbol, $tf, $pack);

            if ($result['ok']) {
                $decision = $result['decision'];
                $meets = $decision['confidence'] >= $ai['min_confidence'];
                // confidence gate: below threshold keeps the PREVIOUS params (brief §4)
                $params = $meets ? $decision['params'] : ($cache['params'] ?? $decision['params']);

                // Phase 3 backtest gate: evaluated for EVERY mode (cheap,
                // deterministic) so a later shadow->advisor flip can never
                // ride a cache that has no real verdict.
                $paramsApproved = false;
                $gateDetail = null;
                $staticParams = AdvisorParams::staticParams(json_decode((string)$botData['configuration'], true) ?: []);
                $paramsHash = AdvisorParams::paramsHash($params, $staticParams, $tf);
                if (!$meets) {
                    // previous params kept: their verdict stands only if it
                    // was a real one (gate detail present).
                    $paramsApproved = isset($cache['gate']) && !empty($cache['params_approved']);
                    $gateDetail = is_array($cache['gate'] ?? null) ? $cache['gate'] : null;
                } elseif (isset($cache['gate'], $cache['params_approved'])
                    && ($cache['params_hash'] ?? null) === $paramsHash) {
                    $paramsApproved = (bool)$cache['params_approved']; // same params: reuse real verdict
                    $gateDetail = is_array($cache['gate']) ? $cache['gate'] : null;
                } else {
                    $needed = max(6, (int)ceil(30 * 60 / DataPackBuilder::tfMinutes($tf)));
                    $gateDetail = AdvisorBacktestGate::evaluate(
                        array_slice($tfCandles[$tf] ?? [], -$needed),
                        $params,
                        $staticParams
                    );
                    $paramsApproved = (bool)$gateDetail['approved'];
                }
                $state['ai_copilot_cache'] = [
                    'decision' => $decision,
                    'params' => $params,
                    'candle_time' => $candleTime,
                    'tf' => $tf,
                    'created_at' => time(),
                    'trust_mode' => $mode,
                    'meets_confidence' => $meets,
                    'params_hash' => $paramsHash,
                    'params_approved' => $paramsApproved,
                    'gate' => $gateDetail,
                ];
                // Targeted atomic write: only our cache key is touched, so a
                // daemon state write during the (slow) LLM call is never
                // clobbered — json_set applies to the CURRENT row value.
                $cacheJson = (string)json_encode($state['ai_copilot_cache']);
                $writeSql = "UPDATE bots SET runtime_state = json_set(
                        CASE WHEN json_valid(runtime_state) AND json_type(runtime_state) = 'object'
                             THEN runtime_state ELSE '{}' END,
                        '$.ai_copilot_cache', json(?)) WHERE id = ?";
                $conn->executeStatement($writeSql, [$cacheJson, $botId]);
                // F8 mitigation: a daemon wholesale state write can land between
                // our read and write and wipe the cache — verify and retry once.
                $persisted = $conn->fetchOne(
                    "SELECT json_extract(runtime_state, '$.ai_copilot_cache.candle_time') FROM bots WHERE id = ?",
                    [$botId]
                );
                if ((int)$persisted !== $candleTime) {
                    $conn->executeStatement($writeSql, [$cacheJson, $botId]);
                }
                $auditLogger->log($botId, $userId, 'AI_L1_DECISION', sprintf(
                    'L1 %s: %s/%s conf=%.2f (%s)',
                    $tf,
                    $decision['regime'],
                    $decision['bias'],
                    $decision['confidence'],
                    $meets ? 'meets confidence' : 'BELOW confidence — previous params kept'
                ), [
                    'tf' => $tf,
                    'candle_time' => date('Y-m-d H:i', intdiv($candleTime, 1000)),
                    'trust_mode' => $mode,
                    'pack_bytes' => strlen((string)json_encode($pack)),
                    'decision' => $decision,
                    'params_applied' => $params,
                    'meets_confidence' => $meets,
                    // scrub invalid UTF-8 and cap size: a hostile/compromised
                    // LLM must not bloat audit_logs or break json_encode.
                    'raw' => mb_substr(scrubUtf8((string)($result['raw'] ?? '')), 0, 2000),
                ]);
                copilotEcho("bot #$botId: L1 OK — {$decision['regime']}/{$decision['bias']} conf=" . $decision['confidence']);
                $decided++;
            } else {
                // fail-safe: previous cache stays in place; daemon untouched.
                $errKey = 'ai_copilot_err_logged_' . $botId . '_' . ($result['stage'] ?? 'x');
                $throttled = false;
                if (function_exists('apcu_fetch')) {
                    $throttled = apcu_fetch($errKey) !== false;
                    if (!$throttled) {
                        apcu_store($errKey, 1, 3600);
                    }
                } else {
                    // CLI without APCu: fall back to an app_settings timestamp.
                    $tsKey = 'ai_copilot.err_ts_' . $botId . '_' . ($result['stage'] ?? 'x');
                    $last = (int)($conn->fetchOne("SELECT value FROM app_settings WHERE setting_key = ?", [$tsKey]) ?: 0);
                    $throttled = (time() - $last) < 3600;
                    if (!$throttled) {
                        $conn->executeStatement(
                            "INSERT INTO app_settings (setting_key, value, encrypted, updated_at) VALUES (?, ?, 0, ?)
                             ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
                            [$tsKey, (string)time(), date('Y-m-d H:i:s')]
                        );
                    }
                }
                if (!$throttled) {
                    $auditLogger->log($botId, $userId, 'AI_L1_ERROR', 'L1 failed (' . ($result['stage'] ?? '?') . '): ' . implode('; ', $result['errors'] ?? []), [
                        'stage' => $result['stage'] ?? 'unknown',
                        'errors' => array_map(
                            static fn(string $e): string => mb_substr(scrubUtf8($e), 0, 500),
                            $result['errors'] ?? []
                        ),
                        'raw' => mb_substr(scrubUtf8((string)($result['raw'] ?? '')), 0, 1000),
                        'cache_kept' => $cache !== [],
                    ]);
                }
                copilotEcho("bot #$botId: L1 FAILED (" . ($result['stage'] ?? 'unknown') . ") — previous cache kept");
                $failed++;
            }
        } catch (Throwable $e) {
            // one bad bot must never stop the others
            copilotEcho("bot #$botId: error — " . $e->getMessage());
            $failed++;
        }
    }

    copilotEcho("run done: decided=$decided skipped=$skipped failed=$failed bots=" . count($bots));
};

if ($loopSec > 0) {
    for (; ;) {
        try {
            $runOnce();
        } catch (Throwable $e) {
            copilotEcho('loop error: ' . $e->getMessage());
        }
        sleep($loopSec);
    }
}

$runOnce();
exit(0);
