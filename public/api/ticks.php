<?php
// Legacy endpoint shim — delegates to the v1 API.
// New clients should call /api/v1/index.php/market/ticks/{botId}.
$botId = (int)($_GET['bot_id'] ?? $_GET['botId'] ?? 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PATH_INFO'] = '/market/ticks/' . $botId;
unset($_GET['route']);
require __DIR__ . '/v1/index.php';
