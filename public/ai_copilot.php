<?php
/**
 * Web UI page of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
// File: public/ai_copilot.php — AI Copilot shadow report: "AI would do X, bot did Y".
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Config\AiCopilotConfig;
use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;

Config::load();
if (!Config::isDebug()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

SecurityHeaders::send();
SessionGuard::requireWeb();
$userId = (int)$_SESSION['user_id'];

$db = Database::getConnection();
$esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$bots = $db->fetchAllAssociative(
    'SELECT id, coin_pair, runtime_state, configuration FROM bots WHERE user_id = ? ORDER BY id',
    [$userId]
);

$botRows = [];
foreach ($bots as $b) {
    $cfg = json_decode((string)$b['configuration'], true);
    $ai = AiCopilotConfig::resolve($cfg);
    $state = json_decode((string)$b['runtime_state'], true) ?: [];
    $cache = is_array($state['ai_copilot_cache'] ?? null) ? $state['ai_copilot_cache'] : null;
    $botRows[] = [
        'id' => (int)$b['id'],
        'pair' => (string)$b['coin_pair'],
        'mode' => $ai['trust_level'],
        'enabled' => $ai['enabled'],
        'cache' => $cache,
    ];
}

$selectedId = isset($_GET['bot']) ? (int)$_GET['bot'] : ($botRows[0]['id'] ?? 0);
$selected = null;
foreach ($botRows as $r) {
    if ($r['id'] === $selectedId) {
        $selected = $r;
    }
}

$decisions = [];
$actions = [];
$outcomes = [];
if ($selected !== null) {
    $decisions = $db->fetchAllAssociative(
        "SELECT id, created_at, context_data FROM audit_logs
         WHERE bot_id = ? AND user_id = ? AND event_type = 'AI_L1_DECISION'
         ORDER BY id DESC LIMIT 50",
        [$selectedId, $userId]
    );
    // Bot's real actions since the oldest listed decision (or last 7 days).
    $since = $decisions !== [] ? (string)end($decisions)['created_at'] : date('Y-m-d H:i:s', time() - 7 * 86400);
    $actions = $db->fetchAllAssociative(
        "SELECT event_type, event_summary, created_at FROM audit_logs
         WHERE bot_id = ? AND created_at >= ?
           AND event_type IN ('BUY','SELL','STATE_CHANGE','RISK_GUARD_BLOCK')
         ORDER BY id ASC",
        [$selectedId, $since]
    );
    $outcomes = $db->fetchAllAssociative(
        "SELECT action, audit_data, created_at FROM trade_logs
         WHERE bot_id = ? AND created_at >= ? ORDER BY id ASC",
        [$selectedId, $since]
    );
}

// Bucket actions/outcomes into decision windows (decision.created_at -> next decision).
$buckets = [];
foreach ($decisions as $i => $d) {
    $start = strtotime((string)$d['created_at']);
    $end = $i + 1 < count($decisions) ? strtotime((string)$decisions[$i + 1]['created_at']) : time();
    $buckets[(int)$d['id']] = ['start' => $start, 'end' => $end, 'acts' => [], 'pnl' => 0.0];
}
foreach ($actions as $a) {
    $t = strtotime((string)$a['created_at']);
    foreach ($buckets as $id => &$bkt) {
        if ($t >= $bkt['start'] && $t < $bkt['end']) {
            $bkt['acts'][] = $a;
            break;
        }
    }
    unset($bkt);
}
foreach ($outcomes as $o) {
    $t = strtotime((string)$o['created_at']);
    foreach ($buckets as $id => &$bkt) {
        if ($t >= $bkt['start'] && $t < $bkt['end']) {
            $ad = json_decode((string)$o['audit_data'], true) ?: [];
            if (isset($ad['pnl_percent'])) {
                $bkt['pnl'] += (float)$ad['pnl_percent'];
            }
            break;
        }
    }
    unset($bkt);
}

$aiCfg = Config::aiConfig();
$aiConfigured = $aiCfg['api_key'] !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Fixzy Kriptobot - AI Copilot Report</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .ai-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ai-table th, .ai-table td { border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; text-align: left; }
        .ai-table th { background: #f3f4f6; }
        .badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; margin: 1px; background: #e5e7eb; }
        .badge.buy { background: #d1fae5; } .badge.sell { background: #fee2e2; }
        .badge.block { background: #fef3c7; } .badge.state { background: #e0e7ff; }
        .conf-low { color: #b45309; } .conf-ok { color: #047857; font-weight: 600; }
        .muted { color: #6b7280; font-size: 12px; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; background: #fff; }
    </style>
</head>
<body class="bg-gray-50">
<div style="max-width: 1100px; margin: 0 auto; padding: 16px;">
    <h2 style="margin: 8px 0;">🤖 AI Copilot — Shadow Report</h2>
    <p class="muted">
        Shadow mode is dictation only: the L1 decision is logged and cached, the trading bot keeps doing its own thing.
        This page compares <b>AI would do X</b> vs <b>bot did Y</b> vs the realized result.
        <?php if (!$aiConfigured): ?><b style="color:#b91c1c;">AI is not configured (AI_API_KEY missing) — no L1 decisions will be produced.</b><?php endif; ?>
    </p>

    <?php if ($botRows === []): ?>
        <div class="card">No bots yet.</div>
    <?php else: ?>
        <div class="card">
            <label>Bot:
                <select onchange="location.href='?bot='+this.value">
                    <?php foreach ($botRows as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $r['id'] === $selectedId ? 'selected' : '' ?>>
                            #<?= $r['id'] ?> <?= $esc($r['pair']) ?> — <?= $r['enabled'] ? $esc($r['mode']) : 'off' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($selected !== null): ?>
                <span class="muted" style="margin-left:16px;">
                    <?php if ($selected['cache'] !== null && is_array($selected['cache']['decision'] ?? null)):
                        $c = $selected['cache']; ?>
                        Last L1: <b><?= $esc($c['decision']['regime']) ?>/<?= $esc($c['decision']['bias']) ?></b>
                        conf=<?= $esc($c['decision']['confidence']) ?>
                        (<?= $esc($c['tf']) ?> candle <?= date('Y-m-d H:i', intdiv((int)$c['candle_time'], 1000)) ?>,
                        age <?= max(0, time() - (int)$c['created_at']) ?>s,
                        <?= !empty($c['meets_confidence']) ? 'confidence OK' : 'BELOW confidence' ?>)
                    <?php else: ?>
                        No cached L1 decision yet for this bot.
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($selected !== null && $decisions === []): ?>
            <div class="card">No L1 decisions recorded yet for this bot.
                Run <code>php bin/ai_copilot_loop.php --once</code> (or the systemd timer) after setting the bot's trust level to <b>shadow</b>.</div>
        <?php elseif ($selected !== null): ?>
            <table class="ai-table">
                <tr>
                    <th>Candle (<?= $esc($selected['cache']['tf'] ?? 'tf') ?>)</th>
                    <th>AI would do (L1)</th>
                    <th>Params</th>
                    <th>Bot actually did</th>
                    <th>Result</th>
                </tr>
                <?php foreach ($decisions as $d):
                    $ctx = json_decode((string)$d['context_data'], true) ?: [];
                    $dec = $ctx['decision'] ?? [];
                    $bkt = $buckets[(int)$d['id']] ?? ['acts' => [], 'pnl' => 0.0];
                    $params = $ctx['params_applied'] ?? ($dec['params'] ?? []);
                ?>
                <tr>
                    <td><?= $esc($ctx['candle_time'] ?? $d['created_at']) ?>
                        <?php if (!empty($ctx['meets_confidence']) === false): ?><br><span class="badge block">low conf</span><?php endif; ?></td>
                    <td>
                        <b><?= $esc($dec['regime'] ?? '?') ?>/<?= $esc($dec['bias'] ?? '?') ?></b>
                        <span class="<?= (float)($dec['confidence'] ?? 0) >= 0.6 ? 'conf-ok' : 'conf-low' ?>">conf=<?= $esc($dec['confidence'] ?? '?') ?></span>
                        <div class="muted"><?= $esc($dec['reason'] ?? '') ?></div>
                    </td>
                    <td class="muted">
                        dca <?= $esc($params['dca_drop_pct'] ?? '-') ?>%/<?= (int)($params['max_dca_steps'] ?? 0) ?>st
                        · tp <?= $esc($params['tp_pct'] ?? '-') ?>%
                        · trail <?= $esc($params['trail_pct'] ?? '-') ?>%
                        · <?= $esc($params['entry_aggressiveness'] ?? '-') ?>
                    </td>
                    <td>
                        <?php if ($bkt['acts'] === []): ?><span class="muted">nothing</span><?php endif; ?>
                        <?php foreach (array_slice($bkt['acts'], 0, 8) as $a):
                            $cls = match ((string)$a['event_type']) {
                                'BUY' => 'buy', 'SELL' => 'sell', 'RISK_GUARD_BLOCK' => 'block', default => 'state',
                            }; ?>
                            <span class="badge <?= $cls ?>" title="<?= $esc($a['created_at']) ?>"><?= $esc($a['event_type']) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($bkt['acts']) > 8): ?><span class="muted">+<?= count($bkt['acts']) - 8 ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if (abs($bkt['pnl']) < 0.0001): ?><span class="muted">—</span>
                        <?php else: ?><b style="color: <?= $bkt['pnl'] >= 0 ? '#047857' : '#b91c1c' ?>"><?= $bkt['pnl'] >= 0 ? '+' : '' ?><?= number_format($bkt['pnl'], 2) ?>%</b><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <p class="muted" style="margin-top:24px;">
        ← <a href="settings.php">Bot settings</a> · <a href="index.php">Dashboard</a>
    </p>
</div>
</body>
</html>
