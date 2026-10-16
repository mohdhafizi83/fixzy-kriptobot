<?php
/**
 * Web UI page of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
// login.php — real credential login (no more auto-login).
//
// Security: CSRF-protected form, rate limited per IP (10 attempts / 15 min),
// bcrypt verification via AuthService, session ID regenerated on success.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Database\Database;
use Fixzy\Kriptobot\Security\AuthService;
use Fixzy\Kriptobot\Security\RateLimiter;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;

Config::load();
SecurityHeaders::send();
SessionGuard::start();

// Fresh install: force the first-run wizard before any login is possible.
if (SessionGuard::setupPending()) {
    header('Location: setup.php');
    exit;
}

// Already logged in? Straight to the dashboard.
if (SessionGuard::isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
$timedOut = isset($_GET['timeout']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $limiter = new RateLimiter();
    $ip = RateLimiter::clientIp();

    if ($limiter->tooMany('login:' . $ip, 10, 900)) {
        http_response_code(429);
        $error = 'Too many login attempts. Please wait 15 minutes and try again.';
    } elseif (!SessionGuard::verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        $error = 'Session expired or invalid form submission. Please try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $db = Database::getConnection();
        $auth = new AuthService($db);
        $user = $auth->authenticate($email, $password);

        if ($user !== null) {
            $limiter->clear('login:' . $ip);
            SessionGuard::login((int)$user['id'], (string)$user['email']);
            header('Location: index.php');
            exit;
        }

        $error = 'Invalid email or password.';
    }
}

$csrf = SessionGuard::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Fixzy Kriptobot — Login</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-brand/15 border border-brand/30 mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
            </div>
            <h1 class="text-xl font-bold text-white tracking-wider">FIXZY KRIPTOBOT</h1>
            <p class="text-gray-400 text-sm mt-1">Sign in to your dashboard</p>
        </div>

        <form method="POST" action="login.php" class="bg-gray-800 rounded-xl shadow-lg p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <?php if ($timedOut): ?>
                <div class="bg-yellow-500/20 border border-yellow-500 text-yellow-200 text-sm rounded px-3 py-2">
                    Your session timed out. Please sign in again.
                </div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="bg-red-500/20 border border-red-500 text-red-200 text-sm rounded px-3 py-2">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm text-gray-300 mb-1" for="email">Email</label>
                <input id="email" name="email" type="email" required autocomplete="username"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-indigo-400 focus:outline-none"
                       placeholder="admin@example.com">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-indigo-400 focus:outline-none">
            </div>
            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 rounded transition">
                Sign In
            </button>
        </form>

        <p class="text-center text-gray-500 text-xs mt-4">
            Single-user system. Forgot your password? Reset it from the server CLI:
            <code>php bin/kriptobot password:reset</code>
        </p>
    </div>
</body>
</html>
