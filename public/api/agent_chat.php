<?php
// API endpoint — AI Agent chat
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Agent\AgentOrchestrator;
use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Security\RateLimiter;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;

Config::load();
if (!Config::isDebug()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

SecurityHeaders::send();
SessionGuard::start();
if (!SessionGuard::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required. Log in first.']);
    exit;
}

// Rate limit: AI calls are expensive — 20/min per IP.
$limiter = new RateLimiter();
if ($limiter->tooMany('agentchat:' . RateLimiter::clientIp(), 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Try again later.']);
    exit;
}

header('Content-Type: application/json');

$aiCfg = Config::aiConfig();
if (empty($aiCfg['api_key'])) {
    http_response_code(500);
    echo json_encode(['error' => 'AI API key not configured. Set AI_API_KEY, AI_BASE_URL and AI_MODEL in .env']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// CSRF Protection for state-changing actions
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? 'chat';

if (in_array($action, ['chat'])) {
    $clientToken = $input['csrf_token'] ?? '';
    if (!SessionGuard::verifyCsrf($clientToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

$message   = trim($input['message'] ?? '');
$sessionId = !empty($input['session_id']) ? (int)$input['session_id'] : null;

if ($action === 'chat') {
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        exit;
    }

    // Verify session ownership if continuing existing session
    if ($sessionId) {
        $session = new \Fixzy\Kriptobot\Agent\AgentSession();
        $sessionData = $session->get($sessionId);
        if (!$sessionData || (int)$sessionData['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
    }

    set_time_limit(300); // 5 minutes for complex agent loops

    try {
        $aiCfg = \Fixzy\Kriptobot\Config\Config::aiConfig();
        $orchestrator = new AgentOrchestrator(
            $aiCfg['api_key'],
            $aiCfg['model'],
            $aiCfg['base_url']
        );

        $result = $orchestrator->processMessage($userId, $message, $sessionId);

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } catch (\Throwable $e) {
        error_log("Agent chat error [user={$userId}]: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Agent processing failed. Please try again.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'list_sessions') {
    $session = new \Fixzy\Kriptobot\Agent\AgentSession();
    $sessions = $session->getSessionsForUser($userId);
    echo json_encode(['success' => true, 'sessions' => $sessions]);
    exit;
}

if ($action === 'get_messages') {
    if (!$sessionId) {
        echo json_encode(['success' => false, 'error' => 'session_id required']);
        exit;
    }
    $session = new \Fixzy\Kriptobot\Agent\AgentSession();
    // Verify session ownership
    $sessionData = $session->get($sessionId);
    if (!$sessionData || (int)$sessionData['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    $messages = $session->getMessages($sessionId, 100);
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'delete_session') {
    if (!$sessionId) {
        echo json_encode(['success' => false, 'error' => 'session_id required']);
        exit;
    }
    $session = new \Fixzy\Kriptobot\Agent\AgentSession();
    $sessionData = $session->get($sessionId);
    if (!$sessionData || (int)$sessionData['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    $session->archive($sessionId);
    echo json_encode(['success' => true, 'message' => 'Session deleted']);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
