<?php
// setup.php — First-run setup wizard (self-locking).
//
// Shown when the admin account has no password yet (fresh install).
// Collects compulsory credentials + strongly-recommended Binance Testnet keys,
// auto-generates AES_MASTER_KEY into .env when missing, then locks itself:
// once a password exists this page redirects to login and cannot be re-run.
//
// Security: CSRF-protected, rate limited per IP (5 attempts / 15 min),
// bcrypt password storage, secrets encrypted at rest with AES-256.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Security\RateLimiter;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;
use Fixzy\Kriptobot\Service\SetupService;

SecurityHeaders::send();
SessionGuard::start();

$setup = new SetupService();

// Self-lock: setup only runs while no admin password is set.
if (!$setup->setupRequired()) {
    header('Location: login.php');
    exit;
}

$error = '';
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $limiter = new RateLimiter();
    $ip = RateLimiter::clientIp();

    if ($limiter->tooMany('setup:' . $ip, 5, 900)) {
        http_response_code(429);
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
    } elseif (!SessionGuard::verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        $error = 'Session expired or invalid form submission. Please try again.';
    } else {
        try {
            $setup->completeSetup(
                (string)($_POST['email'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['confirm_password'] ?? ''),
                trim((string)($_POST['testnet_api_key'] ?? '')),
                trim((string)($_POST['testnet_api_secret'] ?? ''))
            );
            $limiter->clear('setup:' . $ip);
            $done = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
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
    <title>Fixzy Kriptobot — Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">
        <div class="text-center mb-6">
            <div class="text-3xl mb-2">🤖</div>
            <h1 class="text-xl font-bold text-white tracking-wider">FIXZY KRIPTOBOT</h1>
            <p class="text-gray-400 text-sm mt-1">Welcome! Let's set up your trading bot — takes about 2 minutes.</p>
        </div>

        <?php if ($done): ?>
            <div class="bg-gray-800 rounded-xl shadow-lg p-6 space-y-4">
                <div class="bg-green-500/20 border border-green-500 text-green-200 text-sm rounded px-3 py-2">
                    ✅ Setup complete! Your account and encryption keys are ready.
                </div>
                <p class="text-gray-300 text-sm">
                    You can now sign in to your dashboard. Optional integrations
                    (Telegram notifications, AI analysis, webhook secrets) can be
                    added anytime under <strong>Settings → Integrations</strong>.
                </p>
                <a href="login.php"
                   class="block w-full text-center bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 rounded transition">
                    Go to Sign In →
                </a>
            </div>
        <?php else: ?>
        <form method="POST" action="setup.php" class="bg-gray-800 rounded-xl shadow-lg p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <?php if ($error !== ''): ?>
                <div class="bg-red-500/20 border border-red-500 text-red-200 text-sm rounded px-3 py-2">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="border-b border-gray-700 pb-3">
                <h2 class="text-sm font-bold text-indigo-300 uppercase tracking-wide">1 · Admin Account (required)</h2>
            </div>

            <div>
                <label class="block text-sm text-gray-300 mb-1" for="email">Admin Email</label>
                <input id="email" name="email" type="email" required autocomplete="username"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-indigo-400 focus:outline-none"
                       placeholder="you@example.com">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="password">Password <span class="text-gray-500">(min 8 characters)</span></label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-indigo-400 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="confirm_password">Confirm Password</label>
                <input id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-indigo-400 focus:outline-none">
            </div>

            <div class="border-b border-gray-700 pb-3 pt-2">
                <h2 class="text-sm font-bold text-amber-300 uppercase tracking-wide">2 · Binance Testnet Keys <span class="text-gray-500 normal-case">(strongly recommended — the bot can't trade without them)</span></h2>
                <p class="text-xs text-gray-400 mt-1">
                    Free testnet keys from
                    <a href="https://testnet.binance.vision/" target="_blank" rel="noopener" class="text-indigo-400 underline">testnet.binance.vision</a>.
                    Stored encrypted on your server. You can add them later from Settings if you skip this step.
                </p>
            </div>

            <div>
                <label class="block text-sm text-gray-300 mb-1" for="testnet_api_key">Testnet API Key</label>
                <input id="testnet_api_key" name="testnet_api_key" type="text" autocomplete="off"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-amber-400 focus:outline-none"
                       placeholder="e.g. h9Q7...">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="testnet_api_secret">Testnet API Secret</label>
                <input id="testnet_api_secret" name="testnet_api_secret" type="password" autocomplete="new-password"
                       class="w-full rounded bg-gray-700 text-white px-3 py-2 border border-gray-600 focus:border-amber-400 focus:outline-none"
                       placeholder="Paste secret here">
            </div>

            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2 rounded transition">
                Complete Setup
            </button>

            <p class="text-center text-gray-500 text-xs">
                Your encryption key (AES-256) is generated automatically — no config files needed.
            </p>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
