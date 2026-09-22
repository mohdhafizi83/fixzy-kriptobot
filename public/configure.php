<?php
// File: public/configure.php — Global settings shell (Phase 1: API-first, no direct DB access).
// All data is loaded and saved through the v1 REST API by the JavaScript below.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;

Config::load();
if (!Config::isDebug()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

SecurityHeaders::send();
SessionGuard::requireWeb();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Fixzy Kriptobot - Configuration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans pb-20">
    <script>
        const API = 'api/v1/index.php';
        const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
        async function apiCall(path, method, body) {
            const opts = { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF } };
            if (body !== undefined) opts.body = JSON.stringify(body);
            const resp = await fetch(API + path, opts);
            const json = await resp.json().catch(() => ({ ok: false, error: 'Invalid JSON' }));
            if (!json.ok) throw new Error(json.error || ('HTTP ' + resp.status));
            return json.data;
        }
        function flash(msg, isError) {
            const el = document.getElementById('flash');
            el.textContent = (isError ? '⚠️ ' : '✅ ') + msg;
            el.className = (isError
                ? 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 shadow-sm'
                : 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 shadow-sm');
            el.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>


    <nav class="bg-indigo-900 text-white shadow-md shrink-0" x-data="{ navOpen: false }">
        <div class="flex justify-between items-center px-3 py-2 md:px-4 md:py-3">
            <div class="flex items-center space-x-2">
                <button @click="navOpen = !navOpen" class="md:hidden p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="text-lg md:text-xl font-bold tracking-wider">🤖 KRIPTOBOT</div>
            </div>
            <div class="hidden md:flex items-center space-x-4 text-sm font-semibold">
                <a href="index.php" class="hover:text-indigo-300">Dashboard</a>
                <a href="agent.php" class="hover:text-indigo-300">AI Agent</a>
                <a href="configure.php" class="text-indigo-300 border-b-2 border-indigo-300 pb-1">Global Settings</a>
                <a href="faq.php" class="hover:text-indigo-300">FAQ's</a>
                <span class="text-indigo-400">|</span>
                <span class="text-gray-300 font-normal">User: <?= htmlspecialchars($_SESSION['user_email']) ?></span>
                <a href="logout.php" class="text-red-400 hover:text-red-300 ml-2">Logout</a>
            </div>
        </div>
        <div x-show="navOpen" @click.away="navOpen = false" class="md:hidden bg-indigo-950 px-4 py-2 space-y-1 pb-4" x-transition x-cloak>
            <a href="index.php" class="block py-2 hover:text-indigo-300">📊 Dashboard</a>
            <a href="agent.php" class="block py-2 hover:text-indigo-300">🤖 AI Agent</a>
            <a href="settings.php" class="block py-2 hover:text-indigo-300">🆕 New Bot</a>
            <a href="configure.php" class="block py-2 text-indigo-300 font-semibold">⚙️ Settings</a>
            <a href="faq.php" class="block py-2 hover:text-indigo-300">📚 FAQ</a>
            <hr class="border-indigo-800 my-1">
            <span class="block py-1 text-gray-400 text-xs"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
            <a href="logout.php" class="block py-2 text-red-400 font-semibold">🚪 Logout</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto p-6 mt-8">
        
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800">System Configuration</h1>
            <p class="text-gray-500 mt-1">Manage your profile, environments, and global bot guards.</p>
        </div>

        <div id="flash" style="display:none" class="px-4 py-3 rounded mb-6 shadow-sm"></div>

        <!-- FEATURE STATUS: integrations stay disabled until configured AND verified -->
        <div id="featureStatus" style="display:none" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-8">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                <h3 class="font-bold text-gray-700 text-sm">🔌 Integration & Feature Status</h3>
                <button type="button" onclick="verifyAllFeatures()" id="verifyFeaturesBtn"
                        class="text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded px-3 py-1.5 transition-colors">Re-verify All</button>
            </div>
            <div id="featureStatusBody" class="px-5 py-3 space-y-2"></div>
        </div>
        <script>
        async function loadFeatureStatus() {
            try {
                const data = await apiCall('/settings/features', 'GET');
                renderFeatureStatus(data.features || []);
            } catch (e) { console.error('feature status load failed', e); }
        }
        async function verifyAllFeatures() {
            const btn = document.getElementById('verifyFeaturesBtn');
            btn.disabled = true; btn.textContent = 'Verifying…';
            try {
                const data = await apiCall('/settings/features/verify', 'POST');
                renderFeatureStatus(data.features || []);
            } catch (e) {
                flash('Verification failed: ' + e.message, true);
            } finally {
                btn.disabled = false; btn.textContent = 'Re-verify All';
            }
        }
        function renderFeatureStatus(features) {
            const box = document.getElementById('featureStatus');
            const body = document.getElementById('featureStatusBody');
            if (!features.length) return;
            box.style.display = 'block';
            body.innerHTML = features.map(f => {
                if (f.status === 'enabled') {
                    return '<div class="flex items-start gap-2 text-xs text-green-700"><span>✅</span><div><strong>' + f.label + '</strong> enabled &amp; verified' +
                        (f.last_verified_at ? ' <span class="text-gray-400">(' + f.last_verified_at + ')</span>' : '') + '</div></div>';
                }
                const cls = f.required ? 'text-red-700' : 'text-amber-700';
                const icon = f.required ? '❌' : '⚠️';
                const tag = f.required ? ' (COMPULSORY) — ' : ' (optional) — ';
                return '<div class="flex items-start gap-2 text-xs ' + cls + '"><span>' + icon + '</span><div><strong>' + f.label + '</strong>' + tag + f.reason +
                    '<div class="text-gray-500 mt-0.5">How to enable: ' + f.hint + '</div></div></div>';
            }).join('');
        }
        document.addEventListener('DOMContentLoaded', loadFeatureStatus);
        </script>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <div class="lg:col-span-1 space-y-8">
                <div class="bg-white rounded-xl shadow-md p-6 border-t-4 border-indigo-500" x-data="profileCard()">
                    <h2 class="text-xl font-bold mb-4 text-gray-800 flex items-center">
                        <span class="text-2xl mr-2">👤</span> Profile Settings
                    </h2>
                    <form @submit.prevent="saveProfile($el)" class="space-y-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">Email Address</label>
                            <input type="email" name="email" x-model="email" required class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">New Password</label>
                            <input type="password" name="password" placeholder="Leave blank to keep current password" class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">Telegram Chat ID</label>
                            <input type="text" name="telegram_chat_id" x-model="telegramChatId" placeholder="e.g. 123456789" class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 outline-none">
                            <p class="text-xs text-gray-500 mt-1">Send /start to the bot and enter your Chat ID here.</p>
                        </div>
                        <div class="pt-4">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg transition shadow-md">Update Profile</button>
                        </div>
                    </form>
                </div>

                <!-- AI AGENT SETTINGS CARD -->
                <div class="bg-white rounded-xl shadow-md p-6 border-t-4 border-violet-500 mt-8" x-data="agentSettingsData()">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <span class="text-2xl mr-2">🧠</span> AI Agentic System
                            <span class="text-xs text-white bg-violet-500 px-2 py-0.5 rounded ml-3 uppercase">BETA</span>
                        </h2>
                        <button type="button" @click="agentEnabled = !agentEnabled" class="flex items-center cursor-pointer">
                            <div class="relative w-10 h-6">
                                <div class="absolute inset-0 rounded-full transition-colors" :class="agentEnabled ? 'bg-violet-500' : 'bg-gray-300'"></div>
                                <div class="absolute top-1 w-4 h-4 bg-white rounded-full transition-transform" :class="agentEnabled ? 'left-5' : 'left-1'"></div>
                            </div>
                            <span class="ml-2 font-bold text-sm" :class="agentEnabled ? 'text-violet-700' : 'text-gray-500'" x-text="agentEnabled ? 'ENABLED' : 'DISABLED'"></span>
                        </button>
                    </div>

                    <p class="text-sm text-gray-600 mb-6 border-b pb-4">
                        AI Agent acts as your <strong>Strategy Engineer</strong> and <strong>Bot Architect</strong>. Describe your trading objectives in natural language and the AI will analyze markets, run backtests, and propose optimal bot configurations.
                    </p>

                    <form @submit.prevent="saveAgentSettings()" class="space-y-6" :class="{'opacity-50 pointer-events-none': !agentEnabled}">

                        <!-- Autonomy Mode -->
                        <div class="border rounded-lg p-4 bg-violet-50 border-violet-200">
                            <h3 class="font-bold text-sm text-violet-800 mb-3 flex items-center">🔐 Autonomy Mode</h3>
                            <div class="flex space-x-4">
                                <label class="flex items-center cursor-pointer">
                                    <input type="radio" name="autonomy_mode" value="approval_required" x-model="autonomyMode" class="text-violet-600 focus:ring-violet-500">
                                    <span class="ml-2 text-sm">
                                        <span class="font-bold">Approval Required</span><br>
                                        <span class="text-xs text-gray-500">AI proposes, you approve before applying</span>
                                    </span>
                                </label>
                                <label class="flex items-center cursor-pointer">
                                    <input type="radio" name="autonomy_mode" value="full_autonomy" x-model="autonomyMode" class="text-violet-600 focus:ring-violet-500">
                                    <span class="ml-2 text-sm">
                                        <span class="font-bold">Full Autonomy</span><br>
                                        <span class="text-xs text-gray-500">AI auto-applies decisions (within capital limits)</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Capital Limits -->
                        <div class="border rounded-lg p-4 bg-gray-50">
                            <h3 class="font-bold text-sm text-gray-800 mb-3 flex items-center">💰 Capital Limits</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Max Per Bot (USDT)</label>
                                    <input type="number" name="max_capital_per_bot" x-model="maxCapPerBot" step="100" min="50" class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-violet-500 outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Max Total (USDT)</label>
                                    <input type="number" name="max_total_capital" x-model="maxTotalCap" step="100" min="100" class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-violet-500 outline-none">
                                </div>
                            </div>
                        </div>

                        <!-- Allowed Actions -->
                        <div class="border rounded-lg p-4 bg-gray-50">
                            <h3 class="font-bold text-sm text-gray-800 mb-3 flex items-center">✅ Allowed Agent Actions</h3>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_analyze_market" :checked="allowedActions.includes('analyze_market')" class="text-violet-600 rounded">
                                    <span>Analyze Markets</span>
                                </label>
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_view_data" :checked="allowedActions.includes('view_data')" class="text-violet-600 rounded">
                                    <span>View Data / Bots</span>
                                </label>
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_run_backtest" :checked="allowedActions.includes('run_backtest')" class="text-violet-600 rounded">
                                    <span>Run Backtests</span>
                                </label>
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_create_bot" :checked="allowedActions.includes('create_bot')" class="text-violet-600 rounded">
                                    <span>Create Bots</span>
                                </label>
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_update_config" :checked="allowedActions.includes('update_config')" class="text-violet-600 rounded">
                                    <span>Update Bot Config</span>
                                </label>
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_activate_bot" :checked="allowedActions.includes('activate_bot')" class="text-violet-600 rounded">
                                    <span>Activate Bots</span>
                                </label>
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="allow_deactivate_bot" :checked="allowedActions.includes('deactivate_bot')" class="text-violet-600 rounded">
                                    <span>Deactivate Bots</span>
                                </label>
                            </div>
                        </div>

                        <!-- Notifications & Language -->
                        <div class="border rounded-lg p-4 bg-gray-50">
                            <h3 class="font-bold text-sm text-gray-800 mb-3 flex items-center">📬 Notifications & Language</h3>
                            <div class="space-y-3">
                                <label class="flex items-center space-x-2 text-sm">
                                    <input type="checkbox" name="telegram_notifications" x-model="telegramNotif" value="1" class="text-violet-600 rounded">
                                    <span>Send Telegram notifications for approvals</span>
                                </label>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Agent Language</label>
                                    <select name="language_preference" x-model="langPref" class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-violet-500 outline-none bg-white">
                                        <option value="auto">Auto-Detect</option>
                                        <option value="ms">Bahasa Melayu</option>
                                        <option value="en">English</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 flex justify-between items-center">
                            <a href="agent.php" class="text-violet-600 hover:text-violet-800 text-sm font-bold underline" x-show="agentEnabled">
                                🤖 Open AI Agent Chat →
                            </a>
                            <button type="submit" class="bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 px-8 rounded-lg transition shadow-md">
                                💾 Save AI Agent Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Environment Card -->
                <div class="bg-white rounded-xl shadow-md p-6 border-t-4 border-amber-500" x-data="envCard()">
                    <h2 class="text-xl font-bold mb-4 text-gray-800 flex items-center">
                        <span class="text-2xl mr-2">🌍</span> Environment
                    </h2>
                    <form @submit.prevent="saveEnv($el)" class="space-y-4">
                        <div class="bg-amber-50 border border-amber-200 p-4 rounded-lg">
                            <label class="flex items-start cursor-pointer mb-2">
                                <input type="checkbox" name="is_demo_mode" value="1" x-model="isDemo" class="mt-1 w-4 h-4 text-amber-600 border-gray-300 rounded focus:ring-amber-500">
                                <span class="ml-2 font-bold text-amber-900 text-sm">Enable Demo Account (Testnet)</span>
                            </label>
                            <div x-show="isDemo" class="mt-3 pt-3 border-t border-amber-200 space-y-3" x-transition>
                                <div>
                                    <label class="block text-xs font-bold text-amber-900 mb-1">Testnet API Key</label>
                                    <input type="text" name="testnet_api_key" value="<?= htmlspecialchars($user['testnet_api_key'] ?? '') ?>" class="w-full border border-amber-300 rounded p-2 focus:ring-2 focus:ring-amber-500 outline-none text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-amber-900 mb-1">Testnet Secret</label>
                                    <input type="password" name="testnet_api_secret" placeholder="Paste secret here" class="w-full border border-amber-300 rounded p-2 focus:ring-2 focus:ring-amber-500 outline-none text-xs">
                                </div>
                            </div>
                        </div>
                        <div class="pt-2">
                            <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-2 px-6 rounded-lg transition shadow-md">Save Environment</button>
                        </div>
                    </form>
                </div>

                <!-- INTEGRATIONS CARD (DB-backed; overrides .env) -->
                <div class="bg-white rounded-xl shadow-md p-6 border-t-4 border-sky-500 mt-6" x-data="integrationsCard()">
                    <h2 class="text-xl font-bold mb-1 text-gray-800 flex items-center">
                        <span class="text-2xl mr-2">🔌</span> Integrations
                    </h2>
                    <p class="text-sm text-gray-500 mb-4">
                        Optional services. Values saved here are stored encrypted and take priority over <code>.env</code>.
                        Leave a secret blank to keep the current value.
                    </p>
                    <form @submit.prevent="saveIntegrations()" class="space-y-5">
                        <div class="border rounded-lg p-4 bg-gray-50 space-y-3">
                            <h3 class="font-bold text-sm text-gray-800">📬 Telegram Notifications</h3>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Bot Token
                                    <span class="font-normal text-gray-500" x-text="badge('TELEGRAM_BOT_TOKEN')"></span>
                                </label>
                                <input type="password" x-model="fields.TELEGRAM_BOT_TOKEN" autocomplete="off"
                                       placeholder="from @BotFather (leave blank to keep current)"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Webhook Secret
                                    <span class="font-normal text-gray-500" x-text="badge('TELEGRAM_WEBHOOK_SECRET')"></span>
                                </label>
                                <input type="password" x-model="fields.TELEGRAM_WEBHOOK_SECRET" autocomplete="off"
                                       placeholder="leave blank to keep current"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                        </div>

                        <div class="border rounded-lg p-4 bg-gray-50 space-y-3">
                            <h3 class="font-bold text-sm text-gray-800">🧠 AI Analysis (OpenAI-compatible provider)</h3>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">AI API Key
                                    <span class="font-normal text-gray-500" x-text="badge('AI_API_KEY')"></span>
                                </label>
                                <input type="password" x-model="fields.AI_API_KEY" autocomplete="off"
                                       placeholder="leave blank to keep current"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Base URL
                                    <span class="font-normal text-gray-500" x-text="badge('AI_BASE_URL')"></span>
                                </label>
                                <input type="text" x-model="fields.AI_BASE_URL"
                                       placeholder="e.g. https://api.deepseek.com/v1"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Model
                                    <span class="font-normal text-gray-500" x-text="badge('AI_MODEL')"></span>
                                </label>
                                <input type="text" x-model="fields.AI_MODEL"
                                       placeholder="e.g. deepseek-chat"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                        </div>

                        <div class="border rounded-lg p-4 bg-gray-50 space-y-3">
                            <h3 class="font-bold text-sm text-gray-800">📰 News & Webhooks</h3>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">CryptoPanic API Key
                                    <span class="font-normal text-gray-500" x-text="badge('CRYPTOPANIC_API_KEY')"></span>
                                </label>
                                <input type="password" x-model="fields.CRYPTOPANIC_API_KEY" autocomplete="off"
                                       placeholder="leave blank to keep current"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">TradingView Webhook Secret
                                    <span class="font-normal text-gray-500" x-text="badge('TRADINGVIEW_WEBHOOK_SECRET')"></span>
                                </label>
                                <input type="password" x-model="fields.TRADINGVIEW_WEBHOOK_SECRET" autocomplete="off"
                                       placeholder="leave blank to keep current"
                                       class="w-full border rounded p-2 text-xs focus:ring-2 focus:ring-sky-500 outline-none">
                            </div>
                        </div>

                        <div class="pt-2">
                            <button type="submit" class="w-full bg-sky-500 hover:bg-sky-600 text-white font-bold py-2 px-6 rounded-lg transition shadow-md">Save Integrations</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-md p-6 border-t-4 border-emerald-500" x-data="globalFilterManager()">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <span class="text-2xl mr-2">🛡️</span> Global Guardrails (Filters)
                        </h2>
                        
                        <label class="flex items-center cursor-pointer">
                            <div class="relative">
                                <input type="checkbox" x-model="filters.is_enabled" class="sr-only">
                                <div class="block bg-gray-300 w-10 h-6 rounded-full transition-colors" :class="{'bg-emerald-500': filters.is_enabled}"></div>
                                <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform" :class="{'transform translate-x-4': filters.is_enabled}"></div>
                            </div>
                            <span class="ml-2 font-bold text-sm" :class="filters.is_enabled ? 'text-emerald-700' : 'text-gray-500'" x-text="filters.is_enabled ? 'ACTIVE' : 'DISABLED'"></span>
                        </label>
                    </div>
                    
                    <p class="text-sm text-gray-600 mb-6 border-b pb-4">
                        Master filters apply to <strong>ALL BOTS</strong>. If enabled, the engine will check these conditions FIRST. If global conditions fail, bot-specific signals are completely ignored.
                    </p>

                    <form @submit.prevent="saveFilters()" id="globalFilterForm">

                        <div class="space-y-6" :class="{'opacity-50 pointer-events-none': !filters.is_enabled}">
                            
                            <div class="border rounded-lg p-4 bg-gray-50">
                                <h3 class="font-bold text-sm text-indigo-800 mb-3 flex items-center">1. Global Base Order (Entry) Filters</h3>
                                <template x-for="(condition, index) in filters.base_buy" :key="index">
                                    <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-2 mb-2 bg-white p-2 rounded border border-gray-200">
                                        <select x-model="condition.type" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                            <option value="rsi">RSI</option>
                                            <option value="qfl">Quickfingers Luc (QFL)</option>
                                            <option value="news_sentiment">News Sentiment AI</option>
                                            <option value="ai_market">🧠 AI Market Analysis (OHLCV)</option>
                                            <option value="bollinger">Bollinger Bands</option>
                                            <option value="tv_webhook">TradingView Webhook</option>
                                        </select>

                                        <select x-show="['rsi', 'bollinger', 'qfl', 'rsi_14', 'ai_market'].includes(condition.type)" x-model="condition.timeframe" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                            <option value="1m">1m</option><option value="5m">5m</option><option value="15m">15m</option><option value="1h">1h</option><option value="4h">4h</option><option value="1d">1d</option>
                                        </select>
                                        <input x-show="condition.type === 'rsi' || condition.type === 'rsi_14'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Length (14)">
                                        <input x-show="condition.type === 'bollinger'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Period (20)">
                                        <input x-show="condition.type === 'bollinger'" type="number" step="0.1" x-model="condition.stddev" class="border rounded p-2 text-sm flex-1 w-full" placeholder="StdDev (2.0)">

                                        <select x-model="condition.value" class="border rounded p-2 text-sm flex-1 w-full bg-white">
                                            <template x-for="opt in getConditionValueOptions(condition.type, 'buy')" :key="opt.value">
                                                <option :value="opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="filters.base_buy.splice(index, 1)" class="text-red-500 hover:bg-red-100 px-2 py-1 rounded font-bold">&times;</button>
                                    </div>
                                </template>
                                <button type="button" @click="filters.base_buy.push({type: 'rsi', value: '', timeframe: '1h', period: 14, stddev: 2.0})" class="text-xs font-bold text-indigo-600 mt-2">+ Add Global Entry Rule</button>
                            </div>

                            <div class="border rounded-lg p-4 bg-gray-50">
                                <h3 class="font-bold text-sm text-yellow-700 mb-3 flex items-center">2. Global DCA (Safety Order) Filters</h3>
                                <template x-for="(condition, index) in filters.dca_buy" :key="index">
                                    <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-2 mb-2 bg-white p-2 rounded border border-gray-200">
                                        <select x-model="condition.type" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                            <option value="rsi">RSI</option>
                                            <option value="qfl">Quickfingers Luc (QFL)</option>
                                            <option value="news_sentiment">News Sentiment AI</option>
                                            <option value="ai_market">🧠 AI Market Analysis (OHLCV)</option>
                                            <option value="bollinger">Bollinger Bands</option>
                                            <option value="tv_webhook">TradingView Webhook</option>
                                        </select>
                                        
                                        <select x-show="['rsi', 'bollinger', 'qfl', 'rsi_14', 'ai_market'].includes(condition.type)" x-model="condition.timeframe" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                            <option value="1m">1m</option><option value="5m">5m</option><option value="15m">15m</option><option value="1h">1h</option><option value="4h">4h</option><option value="1d">1d</option>
                                        </select>
                                        <input x-show="condition.type === 'rsi' || condition.type === 'rsi_14'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Length (14)">
                                        <input x-show="condition.type === 'bollinger'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Period (20)">
                                        <input x-show="condition.type === 'bollinger'" type="number" step="0.1" x-model="condition.stddev" class="border rounded p-2 text-sm flex-1 w-full" placeholder="StdDev (2.0)">

                                        <select x-model="condition.value" class="border rounded p-2 text-sm flex-1 w-full bg-white">
                                            <template x-for="opt in getConditionValueOptions(condition.type, 'buy')" :key="opt.value">
                                                <option :value="opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="filters.dca_buy.splice(index, 1)" class="text-red-500 hover:bg-red-100 px-2 py-1 rounded font-bold">&times;</button>
                                    </div>
                                </template>
                                <button type="button" @click="filters.dca_buy.push({type: 'rsi', value: '', timeframe: '1h', period: 14, stddev: 2.0})" class="text-xs font-bold text-yellow-600 mt-2">+ Add Global DCA Rule</button>
                            </div>

                            <div class="border rounded-lg p-4 bg-gray-50">
                                <h3 class="font-bold text-sm text-red-700 mb-3 flex items-center">3. Global Sell (Master Override) Filters</h3>
                                <template x-for="(condition, index) in filters.sell" :key="index">
                                    <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-2 mb-2 bg-white p-2 rounded border border-gray-200">
                                        <select x-model="condition.type" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                            <option value="rsi">RSI</option>
                                            <option value="news_sentiment">News Sentiment AI</option>
                                            <option value="ai_market">🧠 AI Market Analysis (OHLCV)</option>
                                            <option value="bollinger">Bollinger Bands</option>
                                            <option value="qfl">Quickfingers Luc (QFL)</option>
                                            <option value="tv_webhook">TradingView Webhook</option>
                                        </select>
                                        
                                        <select x-show="['rsi', 'bollinger', 'qfl', 'rsi_14', 'ai_market'].includes(condition.type)" x-model="condition.timeframe" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                            <option value="1m">1m</option><option value="5m">5m</option><option value="15m">15m</option><option value="1h">1h</option><option value="4h">4h</option><option value="1d">1d</option>
                                        </select>
                                        <input x-show="condition.type === 'rsi' || condition.type === 'rsi_14'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Length (14)">
                                        <input x-show="condition.type === 'bollinger'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Period (20)">
                                        <input x-show="condition.type === 'bollinger'" type="number" step="0.1" x-model="condition.stddev" class="border rounded p-2 text-sm flex-1 w-full" placeholder="StdDev (2.0)">

                                        <select x-model="condition.value" class="border rounded p-2 text-sm flex-1 w-full bg-white">
                                            <template x-for="opt in getConditionValueOptions(condition.type, 'sell')" :key="opt.value">
                                                <option :value="opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="filters.sell.splice(index, 1)" class="text-red-500 hover:bg-red-100 px-2 py-1 rounded font-bold">&times;</button>
                                    </div>
                                </template>
                                <button type="button" @click="filters.sell.push({type: 'rsi', value: '', timeframe: '1h', period: 14, stddev: 2.0})" class="text-xs font-bold text-red-600 mt-2">+ Add Global Sell Rule</button>
                            </div>

                            <div class="border rounded-lg p-4 bg-gray-50">
                                <h3 class="font-bold text-sm text-gray-800 mb-2 flex items-center">🚫 4. Global Blacklisted Coins</h3>
                                <p class="text-xs text-gray-500 mb-3">List of coins that are absolutely forbidden from being traded by any of your bots.</p>
                                <select x-ref="blacklistSelect" multiple class="w-full"></select>
                            </div>

                        <div class="mt-6 flex justify-end">
                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-8 rounded-lg transition shadow-md">
                                💾 Save Global Filters
                            </button>
                        </div>
                    </form>

                </div>

                <!-- INSTITUTIONAL RISK & RECOVERY CARD -->
                <div class="bg-white rounded-xl shadow-md p-6 border-t-4 border-orange-500 mt-8" x-data="recoveryCard()">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <span class="text-2xl mr-2">🚑</span> Institutional Risk & Recovery
                            <span class="text-xs text-white bg-orange-500 px-2 py-0.5 rounded ml-3 uppercase">Highly Recommended</span>
                        </h2>
                    </div>
                    
                    <p class="text-sm text-gray-600 mb-6 border-b pb-4">
                        If enabled, the system will automatically quarantine other bots and spawn a dedicated Recovery Bot to aggressively win back any exact amount lost from a Cut Loss.
                    </p>

                    <form @submit.prevent="saveRecovery()">
                        
                        <div class="border rounded-lg p-4 bg-orange-50 border-orange-200">
                            <label class="flex items-center cursor-pointer justify-between">
                                <span class="font-bold text-orange-900">Universal Smart Recovery Mode</span>
                                <div class="relative">
                                    <input type="checkbox" name="smart_recovery_mode" value="1" x-model="smartRecoveryEnabled" @change="if(!smartRecoveryEnabled) { 
                                        let confirmDisable = confirm('Are you sure? Disabling Universal Smart Recovery means the bot will NOT attempt to recover losses after a Cut Loss. This is highly discouraged for long-term portfolio growth.');
                                        if(!confirmDisable) smartRecoveryEnabled = true;
                                    }" class="sr-only">
                                    <div class="block bg-gray-300 w-10 h-6 rounded-full transition-colors" :class="{'bg-orange-500': smartRecoveryEnabled}"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform" :class="{'transform translate-x-4': smartRecoveryEnabled}"></div>
                                </div>
                            </label>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded-lg transition shadow-md">
                                💾 Save Recovery Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('agentSettingsData', () => ({
                agentEnabled: false,
                autonomyMode: 'approval_required',
                maxCapPerBot: 1000,
                maxTotalCap: 10000,
                langPref: 'auto',
                telegramNotif: true,
                allowedActions: [],

                async init() {
                    try {
                        const s = await apiCall('/settings/agent', 'GET');
                        this.agentEnabled = !!s.agent_enabled;
                        this.autonomyMode = s.autonomy_mode || 'approval_required';
                        this.maxCapPerBot = s.max_capital_per_bot ?? 1000;
                        this.maxTotalCap = s.max_total_capital ?? 10000;
                        this.langPref = s.language_preference || 'auto';
                        this.telegramNotif = !!s.telegram_notifications;
                        this.allowedActions = s.allowed_actions || [];
                    } catch (e) { console.error('Agent settings load failed:', e); }
                },

                async saveAgentSettings() {
                    const allowed = [];
                    ['analyze_market','view_data','run_backtest','create_bot','update_config','activate_bot','deactivate_bot'].forEach(a => {
                        const cb = document.querySelector('#agentSettingsForm input[name="allow_' + a + '"]');
                        if (cb && cb.checked) allowed.push(a);
                    });
                    const payload = {
                        agent_enabled: this.agentEnabled,
                        autonomy_mode: this.autonomyMode,
                        max_capital_per_bot: parseFloat(this.maxCapPerBot) || 1000,
                        max_total_capital: parseFloat(this.maxTotalCap) || 10000,
                        language_preference: this.langPref,
                        telegram_notifications: this.telegramNotif,
                        allowed_actions: allowed,
                    };
                    try {
                        await apiCall('/settings/agent', 'PUT', payload);
                        flash('AI Agent settings saved successfully.');
                    } catch (e) {
                        flash('Failed to save agent settings: ' + e.message, true);
                    }
                },
            }));

            Alpine.data('globalFilterManager', () => ({
                filters: { is_enabled: false, blacklist: '', base_buy: [], dca_buy: [], sell: [] },

                async loadFilters() {
                    try {
                        const d = await apiCall('/settings/global-filters', 'GET');
                        if (d.global_filters) {
                            this.filters = Object.assign(this.filters, d.global_filters);
                        }
                    } catch (e) { console.error('Filters load failed:', e); }
                },

                async saveFilters() {
                    try {
                        await apiCall('/settings/global-filters', 'PUT', { global_filters: this.filters });
                        flash('Global Filters saved successfully and now apply to all bots.');
                    } catch (e) {
                        flash('Failed to save global filters: ' + e.message, true);
                    }
                },

                getConditionValueOptions(type, context) {
                    const opts = {
                        rsi: {
                            buy: [
                                {value: '< 15', label: 'RSI < 15 — Extreme Oversold (Rare)'},
                                {value: '< 20', label: 'RSI < 20 — Deep Oversold'},
                                {value: '< 25', label: 'RSI < 25 — Strongly Oversold'},
                                {value: '< 30', label: 'RSI < 30 — Oversold (Classic)'},
                                {value: '< 35', label: 'RSI < 35 — Moderately Oversold'},
                                {value: '< 40', label: 'RSI < 40 — Slightly Oversold'},
                                {value: 'crossing_up_20', label: '↗ Crossing Up 20 — Reversal from Deep'},
                                {value: 'crossing_up_25', label: '↗ Crossing Up 25 — Reversal Confirmed'},
                                {value: 'crossing_up_30', label: '↗ Crossing Up 30 — Classic Reversal'},
                                {value: 'crossing_up_35', label: '↗ Crossing Up 35 — Early Momentum'},
                                {value: 'crossing_up_40', label: '↗ Crossing Up 40 — Mild Recovery'},
                                {value: 'crossing_up_50', label: '↗ Crossing Up 50 — Bullish Midline Cross'}
                            ],
                            sell: [
                                {value: '> 60', label: 'RSI > 60 — Slightly Overbought'},
                                {value: '> 65', label: 'RSI > 65 — Moderately Overbought'},
                                {value: '> 70', label: 'RSI > 70 — Overbought (Classic)'},
                                {value: '> 75', label: 'RSI > 75 — Strongly Overbought'},
                                {value: '> 80', label: 'RSI > 80 — Deep Overbought'},
                                {value: '> 85', label: 'RSI > 85 — Extreme Overbought (Rare)'},
                                {value: 'crossing_down_80', label: '↘ Crossing Down 80 — Reversal from Peak'},
                                {value: 'crossing_down_75', label: '↘ Crossing Down 75 — Weakness Detected'},
                                {value: 'crossing_down_70', label: '↘ Crossing Down 70 — Classic Reversal'},
                                {value: 'crossing_down_65', label: '↘ Crossing Down 65 — Bearish Momentum'},
                                {value: 'crossing_down_50', label: '↘ Crossing Down 50 — Bearish Midline Cross'}
                            ]
                        },
                        bollinger: {
                            buy: [
                                {value: 'below_lower', label: '📉 Price Below Lower Band'},
                                {value: 'touch_lower', label: '🟡 Price Touching Lower Band'},
                                {value: 'crossing_up_lower', label: '↗ Crossing Up Lower Band (Reversal)'},
                                {value: 'below_middle', label: '📊 Price Below Middle Band (SMA)'},
                                {value: 'crossing_up_middle', label: '↗ Crossing Up Middle Band (Bullish)'},
                                {value: 'percent_b_lt_0', label: '%B < 0 — Price Below Lower (Extreme)'},
                                {value: 'percent_b_lt_0.2', label: '%B < 0.2 — Near Lower Band'},
                                {value: 'percent_b_lt_0.5', label: '%B < 0.5 — Below Midline'}
                            ],
                            sell: [
                                {value: 'above_upper', label: '📈 Price Above Upper Band'},
                                {value: 'touch_upper', label: '🟡 Price Touching Upper Band'},
                                {value: 'crossing_down_upper', label: '↘ Crossing Down Upper Band (Reversal)'},
                                {value: 'above_middle', label: '📊 Price Above Middle Band (SMA)'},
                                {value: 'crossing_down_middle', label: '↘ Crossing Down Middle Band (Bearish)'},
                                {value: 'percent_b_gt_1', label: '%B > 1 — Price Above Upper (Extreme)'},
                                {value: 'percent_b_gt_0.8', label: '%B > 0.8 — Near Upper Band'},
                                {value: 'percent_b_gt_0.5', label: '%B > 0.5 — Above Midline'}
                            ]
                        },
                        qfl: {
                            buy: [
                                {value: 'original', label: '🎯 Original — Standard Base Detection'},
                                {value: 'day_trade', label: '⚡ Day Trade — Short-Term Bases (Aggressive)'},
                                {value: 'conservative', label: '🛡️ Conservative — Deep Bases Only (Safest)'},
                                {value: 'below_base_3', label: '📉 3% Below QFL Base'},
                                {value: 'below_base_5', label: '📉 5% Below QFL Base'},
                                {value: 'below_base_7', label: '📉 7% Below QFL Base'},
                                {value: 'below_base_10', label: '📉 10% Below QFL Base (Deep Crash)'}
                            ],
                            sell: [
                                {value: 'above_base', label: '📈 Price Above QFL Base Level'},
                                {value: 'above_base_3', label: '📈 3% Above QFL Base'},
                                {value: 'above_base_5', label: '📈 5% Above QFL Base'},
                                {value: 'above_resistance', label: '🔴 Price Above QFL Resistance Level'},
                                {value: 'approaching_resistance', label: '🟡 Approaching Resistance (90%)'}
                            ]
                        },
                        tv_webhook: {
                            buy: [
                                {value: 'BUY', label: '🟢 BUY — Standard Buy Signal'},
                                {value: 'STRONG_BUY', label: '🟢🟢 STRONG_BUY — High Confidence Buy'},
                                {value: 'LONG', label: '📗 LONG — Open Long Position'},
                                {value: 'ENTER_LONG', label: '📗 ENTER_LONG — Enter Long Trade'},
                                {value: 'DCA_BUY', label: '🔄 DCA_BUY — Safety Order Trigger'}
                            ],
                            sell: [
                                {value: 'SELL', label: '🔴 SELL — Standard Sell Signal'},
                                {value: 'STRONG_SELL', label: '🔴🔴 STRONG_SELL — High Confidence Sell'},
                                {value: 'SHORT', label: '📕 SHORT — Close Long / Go Short'},
                                {value: 'EXIT_LONG', label: '📕 EXIT_LONG — Exit Long Trade'},
                                {value: 'PANIC_SELL', label: '🚨 PANIC_SELL — Emergency Close All'}
                            ]
                        },
                        external_signal: {
                            buy: [
                                {value: 'BUY', label: '🟢 BUY — Standard Buy Signal'},
                                {value: 'STRONG_BUY', label: '🟢🟢 STRONG_BUY — High Confidence Buy'},
                                {value: 'LONG', label: '📗 LONG — Open Long Position'},
                                {value: 'DCA_BUY', label: '🔄 DCA_BUY — Safety Order Trigger'}
                            ],
                            sell: [
                                {value: 'SELL', label: '🔴 SELL — Standard Sell Signal'},
                                {value: 'STRONG_SELL', label: '🔴🔴 STRONG_SELL — High Confidence Sell'},
                                {value: 'SHORT', label: '📕 SHORT — Close Long / Go Short'},
                                {value: 'PANIC_SELL', label: '🚨 PANIC_SELL — Emergency Close All'}
                            ]
                        },
                        news_sentiment: {
                            buy: [
                                {value: 'BULLISH', label: '🟢 BULLISH — Positive Market Mood'},
                                {value: 'VERY_BULLISH', label: '🟢🟢 VERY BULLISH — Strong Positive Mood'},
                                {value: 'NEUTRAL_BULLISH', label: '🔵 NEUTRAL-BULLISH — Slightly Positive'},
                                {value: 'FEAR_EXTREME', label: '😱 EXTREME FEAR — Contrarian Buy (Fear Index)'},
                                {value: 'FEAR', label: '😟 FEAR — Contrarian Buy (Fear Index)'}
                            ],
                            sell: [
                                {value: 'BEARISH', label: '🔴 BEARISH — Negative Market Mood'},
                                {value: 'VERY_BEARISH', label: '🔴🔴 VERY BEARISH — Strong Negative Mood'},
                                {value: 'BEARISH_CRASH', label: '🚨 BEARISH CRASH — Emergency Liquidation'},
                                {value: 'NEUTRAL_BEARISH', label: '🔵 NEUTRAL-BEARISH — Slightly Negative'},
                                {value: 'GREED_EXTREME', label: '🤑 EXTREME GREED — Contrarian Sell (Greed Index)'},
                                {value: 'GREED', label: '💰 GREED — Contrarian Sell (Greed Index)'}
                            ]
                        },
                        ai_market: {
                            buy: [
                                {value: 'BUY', label: '🧠🟢 AI says BUY — Bullish market structure (OHLCV)'}
                            ],
                            sell: [
                                {value: 'SELL', label: '🧠🔴 AI says SELL — Bearish market structure (OHLCV)'}
                            ]
                        },
                        start_asap: {
                            buy: [{value: '', label: '🚀 Execute Immediately (No Condition)'}],
                            sell: [{value: '', label: '🚀 Execute Immediately (No Condition)'}]
                        }
                    };
                    opts['rsi_14'] = opts['rsi'];
                    let typeOpts = opts[type];
                    if (!typeOpts) return [{value: '', label: '— Select Signal —'}];
                    return typeOpts[context] || [{value: '', label: '— Select Signal —'}];
                },

                async init() {
                    await this.loadFilters();
                    try {
                        const pairs = await apiCall('/market/pairs', 'GET');
                        window.availablePairs = pairs || [];
                    } catch (e) { console.error('Pairs load failed:', e); }
                    this.$nextTick(() => {
                        const selectEl = this.$refs.blacklistSelect;
                        if (selectEl) {
                            new TomSelect(selectEl, {
                                plugins: ['remove_button'],
                                create: true,
                                maxItems: null,
                                placeholder: 'e.g., FTT/USDT, LUNA/USDT...',
                                options: (window.availablePairs || []).map(p => ({value: p, text: p})),
                                items: this.filters.blacklist ? this.filters.blacklist.split(',').map(s => s.trim()) : [],
                                onChange: (values) => {
                                    this.filters.blacklist = Array.isArray(values) ? values.join(', ') : values;
                                }
                            });
                        }
                    });
                }
            }));
        });
    </script>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('integrationsCard', () => ({
                fields: {
                    TELEGRAM_BOT_TOKEN: '', TELEGRAM_WEBHOOK_SECRET: '',
                    AI_API_KEY: '', AI_BASE_URL: '', AI_MODEL: '',
                    CRYPTOPANIC_API_KEY: '', TRADINGVIEW_WEBHOOK_SECRET: ''
                },
                status: {},
                async init() {
                    try {
                        const d = await apiCall('/settings/integrations', 'GET');
                        this.status = d.integrations || {};
                        // Pre-fill only the non-secret fields.
                        ['AI_BASE_URL', 'AI_MODEL'].forEach(k => {
                            if (this.status[k] && this.status[k].set) this.fields[k] = this.status[k].masked;
                        });
                    } catch (e) { console.error('Integrations load failed:', e); }
                },
                badge(key) {
                    const s = this.status[key];
                    if (!s || !s.set) return '';
                    return '— currently: ' + s.masked + ' (from ' + s.source.toUpperCase() + ')';
                },
                async saveIntegrations() {
                    const payload = {};
                    Object.keys(this.fields).forEach(k => {
                        if (this.fields[k] !== '') payload[k] = this.fields[k];
                    });
                    try {
                        const d = await apiCall('/settings/integrations', 'PUT', payload);
                        this.status = d.integrations || {};
                        ['AI_BASE_URL', 'AI_MODEL'].forEach(k => {
                            if (this.status[k] && this.status[k].set) this.fields[k] = this.status[k].masked;
                        });
                        flash('Integrations saved. Use "Re-verify All" above to test connections.');
                    } catch (e) {
                        flash('Failed to save integrations: ' + e.message, true);
                    }
                },
            }));
        });
    </script>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('profileCard', () => ({
                email: '',
                telegramChatId: '',
                async init() {
                    try {
                        const d = await apiCall('/settings/profile', 'GET');
                        this.email = d.email || '';
                        this.telegramChatId = d.telegram_chat_id || '';
                    } catch (e) { console.error('Profile load failed:', e); }
                },
                async saveProfile(formEl) {
                    const pw = formEl.querySelector('[name=password]');
                    try {
                        const d = await apiCall('/settings/profile', 'PUT', {
                            email: this.email,
                            password: pw ? pw.value : '',
                            telegram_chat_id: this.telegramChatId,
                        });
                        flash('Profile and Telegram Chat ID updated successfully.');
                        formEl.querySelector('[name=password]').value = '';
                        document.querySelectorAll('nav span').forEach(el => {
                            if (el.textContent.startsWith('User:')) el.textContent = 'User: ' + d.email;
                        });
                    } catch (e) {
                        flash('Failed to update profile: ' + e.message, true);
                    }
                },
            }));

            Alpine.data('envCard', () => ({
                isDemo: true,
                testnetApiKey: '',
                async init() {
                    try {
                        const d = await apiCall('/settings/environment', 'GET');
                        this.isDemo = !!d.is_demo_mode;
                        this.testnetApiKey = d.testnet_api_key || '';
                    } catch (e) { console.error('Environment load failed:', e); }
                },
                async saveEnv(formEl) {
                    const fd = new FormData(formEl);
                    try {
                        await apiCall('/settings/environment', 'PUT', {
                            is_demo_mode: !!fd.get('is_demo_mode'),
                            testnet_api_key: fd.get('testnet_api_key') || '',
                            testnet_api_secret: fd.get('testnet_api_secret') || '',
                        });
                        flash('Environment settings and Testnet keys saved successfully.');
                        const secret = formEl.querySelector('[name=testnet_api_secret]');
                        if (secret) secret.value = '';
                    } catch (e) {
                        flash('Failed to save environment: ' + e.message, true);
                    }
                },
            }));

            Alpine.data('recoveryCard', () => ({
                smartRecoveryEnabled: false,
                async init() {
                    try {
                        const d = await apiCall('/settings/recovery-mode', 'GET');
                        this.smartRecoveryEnabled = !!d.smart_recovery_mode;
                    } catch (e) { console.error('Recovery mode load failed:', e); }
                },
                async saveRecovery() {
                    try {
                        await apiCall('/settings/recovery-mode', 'PUT', { smart_recovery_mode: this.smartRecoveryEnabled });
                        flash('Institutional Risk & Recovery settings saved successfully.');
                    } catch (e) {
                        flash('Failed to save recovery settings: ' + e.message, true);
                    }
                },
            }));
        });
    </script>
</body>
</html>