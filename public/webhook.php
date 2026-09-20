<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Database\AuditLogger;
// File: public/webhook.php

// ========================================================================
// SECURITY: shared secret loaded from environment (TRADINGVIEW_WEBHOOK_SECRET).
// Configure it in .env; never hard-code secrets in this file.
// ========================================================================
\Fixzy\Kriptobot\Config\Config::load();
define('WEBHOOK_SECRET', (string) \Fixzy\Kriptobot\Config\Config::get('TRADINGVIEW_WEBHOOK_SECRET', ''));
if (WEBHOOK_SECRET === '') {
    http_response_code(500);
    die(json_encode(["status" => "error", "message" => "Webhook secret not configured on server."]));
}

// 1. Only allow the POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(["status" => "error", "message" => "Method Not Allowed. Please use POST."]));
}

// 1b. Rate limit: max 60 webhook calls per minute per IP.
$__limiter = new \Fixzy\Kriptobot\Security\RateLimiter();
if ($__limiter->tooMany('tvwebhook:' . \Fixzy\Kriptobot\Security\RateLimiter::clientIp(), 60, 60)) {
    http_response_code(429);
    die(json_encode(["status" => "error", "message" => "Rate limit exceeded."]));
}

// 2. Read the incoming JSON data
$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);

// 3. Basic format validation
if (!$data || !isset($data['bot_id']) || !isset($data['signal'])) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Invalid JSON format or missing parameters."]));
}

// ========================================================================
// 🛡️ 4. SECRET CHECK (AUTHENTICATION) — constant-time comparison
// ========================================================================
if (!isset($data['secret']) || !is_string($data['secret']) || !hash_equals(WEBHOOK_SECRET, $data['secret'])) {
    // If the token is missing or wrong, return a 401 Unauthorized error
    http_response_code(401);
    die(json_encode(["status" => "error", "message" => "Access Denied. Secret Token is invalid or missing."]));
}

// 5. Extract data if the security checks pass
$botId = (int) $data['bot_id'];
$signal = trim(strtoupper($data['signal']));
$type = isset($data['type']) && $data['type'] === 'external_signal' ? 'external_signal' : 'tv_webhook';

// 6. Inject the signal via the backend service (no SQL in this file).
$service = new \Fixzy\Kriptobot\Service\WebhookService();
$auditLogger = new AuditLogger($service->getConnection());

try {
    $result = $service->injectSignal($botId, $signal, $type);

    if ($result['status'] === 'not_found') {
        http_response_code(404);
        die(json_encode(["status" => "error", "message" => $result['message']]));
    }

    if ($result['status'] === 'ignored') {
        die(json_encode(["status" => "ignored", "message" => $result['message']]));
    }

    $auditLogger->log($botId, (int)$result['user_id'], 'SIGNAL', "$type signal [$signal] received for Bot #$botId", [
        'type'   => $type,
        'signal' => $signal,
        'bot_id' => $botId,
        'source_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Success! Signal '$signal' injected into Bot #$botId memory."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('Webhook error: ' . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Server error."]);
}
