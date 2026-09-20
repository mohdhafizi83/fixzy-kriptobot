<?php
// API endpoint — approve/reject AI Agent decisions
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Agent\Approval\ApprovalManager;
use Fixzy\Kriptobot\Config\Config;
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

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];

// CSRF Protection — all actions on this endpoint are state-changing
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$clientToken = $input['csrf_token'] ?? '';
if (!SessionGuard::verifyCsrf($clientToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$decisionId = (int)($input['decision_id'] ?? 0);
$action     = $input['action'] ?? '';
$feedback   = trim($input['feedback'] ?? '');
$modifiedConfig = $input['modified_config'] ?? null;

if ($decisionId <= 0) {
    echo json_encode(['success' => false, 'error' => 'decision_id required']);
    exit;
}

$approvalManager = new ApprovalManager();

try {
    $result = match ($action) {
        'approve' => $approvalManager->approve($decisionId, $userId, false),
        'approve_apply' => $approvalManager->approve($decisionId, $userId, true),
        'reject'  => $approvalManager->reject($decisionId, $userId, $feedback),
        'modify'  => $approvalManager->modify($decisionId, $userId, $modifiedConfig ?? []),
        'get'     => ['success' => true, 'decision' => $approvalManager->getDecision($decisionId, $userId)],
        'list_pending' => [
            'success'   => true,
            'decisions' => $approvalManager->getPendingDecisions($userId),
        ],
        default   => ['success' => false, 'error' => "Unknown action: {$action}"],
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log("Agent approval error [user={$userId}]: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Approval action failed. Please try again.',
    ], JSON_UNESCAPED_UNICODE);
}
