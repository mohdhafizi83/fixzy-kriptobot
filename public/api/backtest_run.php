<?php
/**
 * Legacy endpoint shim — kept for backward compatibility.
 *
 * Delegates to the v1 API front controller so all logic lives in one place.
 * New clients should call /api/v1/index.php/backtest/{status|ingest|run}.
 */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $body['action'] ?? $_GET['action'] ?? '';

$symbol = strtoupper(trim((string)($body['symbol'] ?? $_GET['symbol'] ?? '')));

// Map legacy action to a v1 route + method.
$route = match ($action) {
    'status' => '/backtest/status/' . rawurlencode($symbol),
    'ingest' => '/backtest/ingest',
    'run'    => '/backtest/run',
    default  => null,
};

if ($route === null) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// The status route is a GET in v1; legacy callers POST it.
$_SERVER['REQUEST_METHOD'] = $action === 'status' ? 'GET' : 'POST';
$_SERVER['PATH_INFO'] = $route;
unset($_GET['action'], $_GET['route']);

require __DIR__ . '/v1/index.php';
