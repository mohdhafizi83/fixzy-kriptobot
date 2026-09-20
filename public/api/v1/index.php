<?php

/**
 * Fixzy Kriptobot REST API v1 — front controller.
 *
 * All frontend-facing reads/writes go through here. Routes are resolved from
 * PATH_INFO (e.g. /api/v1/index.php/bots/2/activate) or ?route=/bots/2/activate.
 *
 * Auth:
 *   - Browser (Web UI): PHP session + CSRF token (X-CSRF-Token header or body field).
 *   - Mobile/scripts:   Authorization: Bearer kb_<id>_<secret> (no CSRF needed).
 *
 * Every response uses the envelope {ok, data, error}.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Http\ApiException;
use Fixzy\Kriptobot\Http\ApiResponse;
use Fixzy\Kriptobot\Http\HttpRequest;
use Fixzy\Kriptobot\Http\Router;
use Fixzy\Kriptobot\Security\RateLimiter;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;
use Fixzy\Kriptobot\Service\AgentSettingsService;
use Fixzy\Kriptobot\Service\ApiTokenService;
use Fixzy\Kriptobot\Service\BotService;
use Fixzy\Kriptobot\Service\MarketService;
use Fixzy\Kriptobot\Service\UserService;

SecurityHeaders::send();

// ─── Rate limiting (per IP) — protects against brute force & flooding ────────
$rateLimiter = new RateLimiter();
$clientIp = RateLimiter::clientIp();
if ($rateLimiter->tooMany('api:' . $clientIp, 300, 60)) { // 300 req/min
    ApiResponse::error('Rate limit exceeded. Slow down.', 429);
}

// ─── CORS (locked down by default; configure API_CORS_ORIGINS in .env) ───────
$corsOrigins = array_filter(array_map('trim', explode(',', Config::get('API_CORS_ORIGINS', ''))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array('*', $corsOrigins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $corsOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if ($corsOrigins !== []) {
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
if (HttpRequest::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Authentication ──────────────────────────────────────────────────────────
$tokenService = new ApiTokenService();
$userId = null;
$authMode = null; // 'token' | 'session'

$bearer = HttpRequest::bearerToken();
if ($bearer !== null) {
    $userId = $tokenService->verify($bearer);
    $authMode = 'token';
    if ($userId === null) {
        ApiResponse::error('Invalid or revoked API token.', 401);
    }
} else {
    // Browser session path — requires a real, logged-in session.
    SessionGuard::start();
    if (!SessionGuard::isAuthenticated()) {
        ApiResponse::error('Authentication required. Log in first.', 401);
    }
    $userId = (int)$_SESSION['user_id'];
    $authMode = 'session';
}

$body = HttpRequest::body();

// CSRF only applies to session-authenticated state-changing requests.
if ($authMode === 'session' && in_array(HttpRequest::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $clientToken = HttpRequest::csrfToken($body);
    if (!SessionGuard::verifyCsrf($clientToken)) {
        ApiResponse::error('Invalid CSRF token.', 403);
    }
}

// ─── Services ────────────────────────────────────────────────────────────────
$botService = new BotService();
$userService = new UserService();
$marketService = new MarketService();
$agentSettingsService = new AgentSettingsService();
$featureService = new \Fixzy\Kriptobot\Service\FeatureStatusService();

$router = new Router();

// ─── Meta ────────────────────────────────────────────────────────────────────
$router->add('GET', '/', function (): array {
    return ['name' => 'Fixzy Kriptobot API', 'version' => 'v1', 'time' => date('c')];
});

// ─── Bots ────────────────────────────────────────────────────────────────────
$router->add('GET', '/bots', function (array $p, array $b) use ($botService, $userId): array {
    return $botService->listAll($userId);
});

$router->add('GET', '/bots/grouped', function (array $p, array $b) use ($botService, $userId): array {
    return $botService->listGrouped($userId);
});

$router->add('GET', '/bots/{id}', function (array $p, array $b) use ($botService, $userId): array {
    $bot = $botService->get($userId, (int)$p['id']);
    if ($bot === null) {
        throw new ApiException('Bot not found.', 404);
    }
    return $bot;
});

$router->add('POST', '/bots', function (array $p, array $b) use ($botService, $userId): array {
    if (empty($b['general'])) {
        throw new ApiException('Missing bot configuration payload.', 422);
    }
    return $botService->save($userId, null, $b);
});

$router->add('PUT', '/bots/{id}', function (array $p, array $b) use ($botService, $userId): array {
    if (empty($b['general'])) {
        throw new ApiException('Missing bot configuration payload.', 422);
    }
    return $botService->save($userId, (int)$p['id'], $b);
});

$router->add('POST', '/bots/{id}/activate', function (array $p, array $b) use ($botService, $userId): array {
    $on = !array_key_exists('enabled', $b) || !empty($b['enabled']);
    $botService->setActive($userId, (int)$p['id'], $on);
    return ['bot_id' => (int)$p['id'], 'active' => $on];
});

$router->add('DELETE', '/bots/{id}', function (array $p, array $b) use ($botService, $userId): array {
    return $botService->delete($userId, (int)$p['id']);
});

// ─── Portfolio / P&L summary ─────────────────────────────────────────────────
$router->add('GET', '/portfolio', function (array $p, array $b) use ($userService, $botService): array {
    $global = ['total_value' => 0.0, 'connected' => false, 'assets' => [], 'error' => null];
    try {
        $creds = $userService->resolveExchangeCredentials(1);
        $ex = new \Fixzy\Kriptobot\Trading\ExchangeService(
            $creds['exchange'],
            $creds['api_key'],
            $creds['api_secret'],
            $creds['is_testnet']
        );
        $balances = $ex->getExchange()->fetch_balance();
        $tickers = $ex->getExchange()->fetch_tickers();
        $assets = [];
        foreach ($balances['total'] as $asset => $amount) {
            if ($amount > 0) {
                if ($asset === 'USDT' || $asset === 'USDC') {
                    $value = $amount;
                } else {
                    $pair = $asset . '/USDT';
                    $value = isset($tickers[$pair]) ? ($amount * $tickers[$pair]['last']) : 0;
                }
                $assets[$asset] = ['qty' => $amount, 'value' => $value];
                $global['total_value'] += $value;
            }
        }
        uasort($assets, fn($a, $b) => $b['value'] <=> $a['value']);
        $global['assets'] = array_slice($assets, 0, 10, true);
        $global['connected'] = true;
    } catch (\Throwable $e) {
        $global['error'] = $e->getMessage();
    }

    // Per-bot live P&L from runtime state.
    $bots = $botService->listAll(1);
    $botPnl = [];
    foreach ($bots as $bot) {
        if (!empty($bot['parent_id'])) {
            continue;
        }
        $state = $bot['runtime_state'] ?? [];
        $botPnl[] = [
            'bot_id'    => (int)$bot['id'],
            'name'      => $bot['configuration']['general']['name'] ?? ('Bot #' . $bot['id']),
            'pair'      => $bot['coin_pair'],
            'active'    => (bool)$bot['status'],
            'holdings'  => (float)($state['current_holdings'] ?? 0),
            'pnl_pct'   => (float)($state['live_pnl_percent'] ?? 0),
            'entry'     => (float)($state['average_entry_price'] ?? 0),
        ];
    }

    return ['portfolio' => $global, 'bots' => $botPnl];
});

// ─── Settings: profile / environment / filters / recovery ───────────────────
$router->add('GET', '/settings/profile', fn() => $userService->getProfile($userId));

$router->add('PUT', '/settings/profile', function (array $p, array $b) use ($userService, $userId): array {
    return $userService->updateProfile(
        $userId,
        (string)($b['email'] ?? ''),
        (string)($b['password'] ?? ''),
        (string)($b['telegram_chat_id'] ?? '')
    );
});

$router->add('GET', '/settings/environment', fn() => $userService->getEnvironment($userId));

$router->add('PUT', '/settings/environment', function (array $p, array $b) use ($userService, $userId): array {
    return $userService->updateEnvironment(
        $userId,
        !empty($b['is_demo_mode']),
        (string)($b['testnet_api_key'] ?? ''),
        (string)($b['testnet_api_secret'] ?? '')
    );
});

$router->add('GET', '/settings/global-filters', function () use ($userService, $userId): array {
    $raw = $userService->getGlobalFilters($userId);
    return ['global_filters' => $raw !== null ? json_decode($raw, true) : null];
});

$router->add('PUT', '/settings/global-filters', function (array $p, array $b) use ($userService, $userId): array {
    $payload = $b['global_filters'] ?? $b;
    $userService->updateGlobalFilters($userId, json_encode($payload));
    return ['global_filters' => $payload];
});

$router->add('GET', '/settings/recovery-mode', fn() => ['smart_recovery_mode' => $userService->getSmartRecoveryMode($userId)]);

// Feature gating: which integrations are enabled/verified and why not.
$router->add('GET', '/settings/features', function () use ($featureService): array {
    return ['features' => array_values($featureService->getAll())];
});

$router->add('POST', '/settings/features/verify', function () use ($featureService, $userId): array {
    return ['features' => array_values($featureService->verifyAll($userId))];
});

$router->add('PUT', '/settings/recovery-mode', function (array $p, array $b) use ($userService, $userId): array {
    $userService->updateSmartRecoveryMode($userId, !empty($b['smart_recovery_mode']));
    return ['smart_recovery_mode' => !empty($b['smart_recovery_mode'])];
});

$router->add('GET', '/settings/agent', function () use ($userId): array {
    $manager = new \Fixzy\Kriptobot\Agent\Approval\ApprovalManager();
    return $manager->getAgentSettings($userId);
});

$router->add('PUT', '/settings/agent', function (array $p, array $b) use ($agentSettingsService, $userId): array {
    $agentSettingsService->update($userId, $b);
    $manager = new \Fixzy\Kriptobot\Agent\Approval\ApprovalManager();
    return $manager->getAgentSettings($userId);
});

// ─── Exchange API keys ───────────────────────────────────────────────────────
$router->add('GET', '/api-keys', fn() => $userService->listApiKeys($userId));

$router->add('POST', '/api-keys', function (array $p, array $b) use ($userService, $userId): array {
    $exchange = trim((string)($b['exchange_name'] ?? ''));
    $key = trim((string)($b['api_key'] ?? ''));
    $secret = trim((string)($b['api_secret'] ?? ''));
    if ($exchange === '' || $key === '' || $secret === '') {
        throw new ApiException('exchange_name, api_key and api_secret are required.', 422);
    }
    $id = $userService->addApiKey($userId, $exchange, $key, $secret);
    return ['id' => $id];
});

$router->add('POST', '/api-keys/{id}/test', function (array $p) use ($userService, $userId): array {
    return $userService->testApiKeyConnection($userId, (int)$p['id']);
});

// ─── Market data ─────────────────────────────────────────────────────────────
$router->add('GET', '/market/pairs', fn() => $marketService->availablePairs());

$router->add('GET', '/market/ticks/{botId}', function (array $p) use ($marketService): array {
    $limit = (int)($_GET['limit'] ?? 250);
    return $marketService->recentTicks((int)$p['botId'], $limit);
});

// ─── Agent sessions & decisions (delegates to existing services) ────────────
$router->add('GET', '/agent/sessions', function () use ($userId) {
    $session = new \Fixzy\Kriptobot\Agent\AgentSession();
    return $session->getSessionsForUser($userId);
});

$router->add('GET', '/agent/sessions/{id}/messages', function (array $p) use ($userId) {
    $session = new \Fixzy\Kriptobot\Agent\AgentSession();
    $data = $session->get((int)$p['id']);
    if (!$data || (int)$data['user_id'] !== $userId) {
        throw new ApiException('Access denied.', 403);
    }
    return $session->getMessages((int)$p['id'], 100);
});

$router->add('GET', '/agent/decisions/pending', function () use ($userId) {
    $manager = new \Fixzy\Kriptobot\Agent\Approval\ApprovalManager();
    return $manager->getPendingDecisions($userId);
});

$router->add('POST', '/agent/decisions/{id}/approve', function (array $p, array $b) use ($userId) {
    $manager = new \Fixzy\Kriptobot\Agent\Approval\ApprovalManager();
    $autoApply = !empty($b['auto_apply']);
    return $manager->approve((int)$p['id'], $userId, $autoApply);
});

$router->add('POST', '/agent/decisions/{id}/reject', function (array $p, array $b) use ($userId) {
    $manager = new \Fixzy\Kriptobot\Agent\Approval\ApprovalManager();
    return $manager->reject((int)$p['id'], $userId, (string)($b['feedback'] ?? ''));
});

// ─── Backtesting ─────────────────────────────────────────────────────────────
$router->add('GET', '/backtest/status/{symbol}', function (array $p) {
    $svc = new \Fixzy\Kriptobot\Service\BacktestService();
    return $svc->status($p['symbol']);
});

$router->add('POST', '/backtest/ingest', function (array $p, array $b) {
    $svc = new \Fixzy\Kriptobot\Service\BacktestService();
    return $svc->ingest(
        (string)($b['symbol'] ?? ''),
        (string)($b['from_date'] ?? date('Y-m-d', strtotime('-30 days'))),
        (string)($b['to_date'] ?? date('Y-m-d', strtotime('-1 day')))
    );
});

$router->add('POST', '/backtest/run', function (array $p, array $b) use ($userId) {
    $svc = new \Fixzy\Kriptobot\Service\BacktestService();
    $result = $svc->run($b, $userId, (int)($b['bot_id'] ?? 0));
    if (isset($result['error'])) {
        throw new ApiException((string)$result['error'], 422);
    }
    return $result;
});

// ─── API tokens (session-auth only; token cannot mint tokens) ────────────────
$router->add('GET', '/tokens', function () use ($tokenService, $authMode, $userId): array {
    if ($authMode !== 'session') {
        throw new ApiException('Token management requires a browser session.', 403);
    }
    return $tokenService->listForUser($userId);
});

$router->add('POST', '/tokens', function (array $p, array $b) use ($tokenService, $authMode, $userId): array {
    if ($authMode !== 'session') {
        throw new ApiException('Token management requires a browser session.', 403);
    }
    return $tokenService->create($userId, (string)($b['name'] ?? 'device-token'));
});

$router->add('DELETE', '/tokens/{id}', function (array $p) use ($tokenService, $authMode, $userId): array {
    if ($authMode !== 'session') {
        throw new ApiException('Token management requires a browser session.', 403);
    }
    $ok = $tokenService->revoke($userId, (int)$p['id']);
    if (!$ok) {
        throw new ApiException('Token not found or already revoked.', 404);
    }
    return ['revoked' => true];
});

// ─── Dispatch ────────────────────────────────────────────────────────────────
try {
    $result = $router->dispatch(HttpRequest::method(), HttpRequest::path(), $body);
    ApiResponse::ok($result);
} catch (ApiException $e) {
    ApiResponse::error($e->getMessage(), $e->getStatus());
} catch (\RuntimeException $e) {
    error_log('API v1 validation error: ' . $e->getMessage());
    ApiResponse::error('Request could not be processed.', 422);
} catch (\Throwable $e) {
    error_log('API v1 error: ' . $e->getMessage());
    ApiResponse::error('Internal server error.', 500);
}
