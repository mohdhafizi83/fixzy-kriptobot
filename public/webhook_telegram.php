<?php
// Telegram Bot webhook
require_once dirname(__DIR__) . '/vendor/autoload.php';
\Fixzy\Kriptobot\Config\Config::load();

use Fixzy\Kriptobot\Agent\Approval\ApprovalManager;
use Fixzy\Kriptobot\Notifications\NotificationService;

$telegramToken = \Fixzy\Kriptobot\Config\Config::get('TELEGRAM_BOT_TOKEN');
$webhookSecret = \Fixzy\Kriptobot\Config\Config::get('TELEGRAM_WEBHOOK_SECRET');

if (empty($telegramToken)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

// --- WEBHOOK AUTHENTICATION (secret is mandatory) ---
if (empty($webhookSecret)) {
    http_response_code(500);
    echo json_encode(['error' => 'TELEGRAM_WEBHOOK_SECRET is not configured. Refusing to accept unsigned webhooks.']);
    exit;
}
$providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($webhookSecret, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// --- RATE LIMIT: max 60 updates/min per IP ---
$__limiter = new \Fixzy\Kriptobot\Security\RateLimiter();
if ($__limiter->tooMany('tgwebhook:' . \Fixzy\Kriptobot\Security\RateLimiter::clientIp(), 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded.']);
    exit;
}

// --- PARSE INPUT ---
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message']) || !isset($input['update_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
    exit;
}

// --- REPLAY PROTECTION (check update_id) ---
$updateId = (int)$input['update_id'];
if ($updateId > 0) {
    $cacheKey = 'tg_update_' . $updateId;
    if (function_exists('apcu_exists') && apcu_exists($cacheKey)) {
        // Duplicate webhook call — acknowledge silently
        http_response_code(200);
        echo json_encode(['status' => 'duplicate']);
        exit;
    }
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, true, 86400);
    }
}

$message = $input['message'];
$chatId = $message['chat']['id'] ?? '';
$text = trim($message['text'] ?? '');
$messageId = (int)($message['message_id'] ?? 0);

// --- VALIDATE chat_id ---
if (empty($chatId) || strlen((string)$chatId) > 64 || !preg_match('/^-?\d+$/', (string)$chatId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
    exit;
}

// --- PROCESS COMMANDS ---
if (!preg_match('#^/(approve|reject|review)\s+(\d+)(?:\s+(.*))?#i', $text, $matches)) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

$command = strtolower($matches[1]);
$decisionId = (int)$matches[2];
$extraArgs = trim($matches[3] ?? '');

// Find user by telegram_chat_id via the backend service (no SQL in this file).
$webhookService = new \Fixzy\Kriptobot\Service\WebhookService();
$userId = $webhookService->findUserIdByTelegramChatId($chatId);

if ($userId === null) {
    $notifier = new NotificationService($telegramToken, $chatId);
    $notifier->sendTelegramAlert("❌ Your Telegram Chat ID is not linked to any Fixzy Kriptobot account. Please update it in the Dashboard.");
    http_response_code(200);
    echo json_encode(['status' => 'user_not_found']);
    exit;
}

$approvalManager = new ApprovalManager();
$notifier = new NotificationService($telegramToken, $chatId);

try {
    if ($command === 'approve') {
        $result = $approvalManager->approve($decisionId, $userId, true);

        if ($result['success']) {
            $notifier->sendTelegramAlert(
                "✅ <b>Approved!</b>\n\nProposal #{$decisionId} has been approved and applied.\n\n🤖 Fixzy Kriptobot AI Agent"
            );
        } else {
            $notifier->sendTelegramAlert(
                "⚠️ Unable to approve proposal #{$decisionId}.\n\n" . htmlspecialchars($result['error'] ?? 'Unknown error.')
            );
        }
    } elseif ($command === 'reject') {
        $feedback = !empty($extraArgs) ? $extraArgs : 'Rejected via Telegram';
        $result = $approvalManager->reject($decisionId, $userId, $feedback);
        $notifier->sendTelegramAlert(
            "❌ <b>Rejected.</b>\n\nProposal #{$decisionId} has been rejected."
            . (!empty($extraArgs) ? "\n\nNote: " . htmlspecialchars($extraArgs) : '')
        );
    } elseif ($command === 'review') {
        $decision = $approvalManager->getDecision($decisionId, $userId);
        if ($decision) {
            $config = $decision['proposed_config'];
            $pair = $config['general']['custom_pairs'] ?? 'N/A';
            $tp = $config['risk_management']['target_profit'] ?? 'N/A';
            $cl = $config['risk_management']['cut_loss_percent'] ?? 'N/A';

            $notifier->sendTelegramAlert(
                "🔍 <b>Proposal Review #{$decisionId}</b>\n\n"
                . "<b>Pair:</b> {$pair}\n"
                . "<b>TP:</b> {$tp}% | <b>CL:</b> {$cl}%\n"
                . "<b>Status:</b> " . ucfirst(htmlspecialchars($decision['status'] ?? 'unknown')) . "\n\n"
                . "Please check the dashboard for more information."
            );
        } else {
            $notifier->sendTelegramAlert("⚠️ Proposal #{$decisionId} not found or you do not have access.");
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'action' => $command]);

} catch (\Throwable $e) {
    error_log("Telegram webhook error [decision={$decisionId}]: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
