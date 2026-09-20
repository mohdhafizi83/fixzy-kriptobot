<?php
// File: public/index.php — Dashboard shell (Phase 1: API-first, no direct DB access).
// All data is loaded from /api/v1/index.php by the JavaScript below.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Config\Config;
use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;

Config::load();
if (!Config::isDebug()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
ini_set('pcre.jit', 0); // Fix PCRE JIT memory warning

SecurityHeaders::send();
SessionGuard::requireWeb(); // redirects to login.php when unauthenticated
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Fixzy Kriptobot - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans">

    <nav class="bg-indigo-900 text-white shadow-md shrink-0" x-data="{ navOpen: false }">
        <div class="flex justify-between items-center px-3 py-2 md:px-4 md:py-3">
            <div class="flex items-center space-x-2">
                <button @click="navOpen = !navOpen" class="md:hidden p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="text-lg md:text-xl font-bold tracking-wider">🤖 KRIPTOBOT</div>
            </div>
            <div class="hidden md:flex items-center space-x-4 text-sm font-semibold">
                <a href="index.php" class="text-indigo-300 border-b-2 border-indigo-300 pb-1">Dashboard</a>
                <a href="agent.php" class="hover:text-indigo-300">AI Agent</a>
                <a href="configure.php" class="hover:text-indigo-300">Global Settings</a>
                <a href="faq.php" class="hover:text-indigo-300">FAQ's</a>
                <span class="text-indigo-400">|</span>
                <span class="text-gray-300 font-normal">User: <?= htmlspecialchars($_SESSION['user_email']) ?></span>
                <a href="logout.php" class="text-red-400 hover:text-red-300 ml-2">Logout</a>
            </div>
        </div>
        <div x-show="navOpen" @click.away="navOpen = false" class="md:hidden bg-indigo-950 px-4 py-2 space-y-1 pb-4" x-transition x-cloak>
            <a href="index.php" class="block py-2 text-indigo-300 font-semibold">📊 Dashboard</a>
            <a href="agent.php" class="block py-2 hover:text-indigo-300">🤖 AI Agent</a>
            <a href="settings.php" class="block py-2 hover:text-indigo-300">🆕 New Bot</a>
            <a href="configure.php" class="block py-2 hover:text-indigo-300">⚙️ Settings</a>
            <a href="faq.php" class="block py-2 hover:text-indigo-300">📚 FAQ</a>
            <hr class="border-indigo-800 my-1">
            <span class="block py-1 text-gray-400 text-xs"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
            <a href="logout.php" class="block py-2 text-red-400 font-semibold">🚪 Logout</a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto p-3 md:p-6 mt-2 md:mt-4" x-data="dashboard()" x-init="init()" x-cloak>

        <div x-show="message" class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4" x-text="message"></div>

        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl shadow-lg p-8 text-white mb-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-blue-200">Total Portfolio Value (Estimated)</h2>
            <div class="text-5xl font-bold mt-2">
                $<span x-text="portfolio.total_value.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                <span class="text-lg font-normal text-blue-200">USDT</span>
            </div>
            <div class="mt-4 flex space-x-6 text-sm">
                <div>Connection Status:
                    <span x-show="portfolio.connected" class="text-green-300 font-bold">Connected (Live/Testnet)</span>
                    <span x-show="!portfolio.connected" class="text-yellow-300 font-bold" x-text="portfolio.error ? 'Error: ' + portfolio.error : 'Waiting for API'"></span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <div class="lg:col-span-1 space-y-8">
                <!-- Exchanges -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg">Exchanges</h3>
                        <button @click="showApiModal = true" class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded text-sm font-bold hover:bg-indigo-200">+ Add New</button>
                    </div>
                    <ul class="space-y-3">
                        <template x-if="apiKeys.length === 0">
                            <li class="text-sm text-gray-500">No API connected.</li>
                        </template>
                        <template x-for="api in apiKeys" :key="api.id">
                            <li class="border-b pb-2">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center">
                                        <span class="w-3 h-3 rounded-full mr-2 transition-colors"
                                              :class="api._status === 'success' ? 'bg-green-500' : (api._status === 'error' ? 'bg-red-500' : 'bg-gray-400')"></span>
                                        <span class="capitalize font-bold text-gray-700" x-text="api.exchange_name"></span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-[10px] text-gray-400 truncate w-16" x-text="(api.api_key || '').substring(0, 8) + '...'"></span>
                                        <button @click="testConn(api)" :disabled="api._status === 'loading'"
                                                class="text-[10px] bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-2 py-1 rounded border border-indigo-200 font-bold disabled:opacity-50 transition-all focus:outline-none">
                                            <span x-show="api._status !== 'loading'">Test</span>
                                            <span x-show="api._status === 'loading'">⏳</span>
                                        </button>
                                    </div>
                                </div>
                                <div x-show="api._message" class="text-[10px] mt-1 px-2 py-1 rounded w-full break-words relative"
                                     :class="api._status === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'">
                                    <span x-text="api._message"></span>
                                    <button @click="api._message = ''" class="absolute top-1 right-2 font-bold hover:opacity-70 focus:outline-none">&times;</button>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>

                <!-- Asset Balances -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h3 class="font-bold text-lg mb-4">Asset Balances</h3>
                    <ul class="space-y-3">
                        <template x-if="Object.keys(portfolio.assets || {}).length === 0">
                            <li class="text-sm text-gray-500">No assets / API not ready yet.</li>
                        </template>
                        <template x-for="(data, coin) in portfolio.assets || {}" :key="coin">
                            <li class="flex justify-between border-b pb-2">
                                <span class="font-bold" x-text="coin"></span>
                                <span class="text-right">
                                    <span class="font-mono text-gray-600" x-text="Number(data.qty).toLocaleString(undefined, {maximumFractionDigits: 6})"></span>
                                    <span class="text-xs text-gray-400 ml-2" x-text="'$' + Number(data.value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                                </span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <!-- Live Price Ticks -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-md p-6">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="font-bold text-lg">📈 Live Price Ticks <span class="text-xs text-gray-400 font-normal">(last 250, auto-refresh 5s)</span></h3>
                        <span id="tick-status" class="text-xs text-gray-400">⏳ Loading...</span>
                    </div>
                    <div style="max-height:300px;overflow-y:auto" class="border rounded-lg">
                        <table class="w-full text-xs font-mono">
                            <thead class="sticky top-0 bg-gray-50">
                                <tr><th class="p-2 text-left">Time</th><th class="p-2 text-right">Price</th><th class="p-2 text-right">PNL</th></tr>
                            </thead>
                            <tbody id="tick-body">
                                <tr><td colspan="3" class="p-4 text-center text-gray-400">Loading ticks...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- My Bots -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-md p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-bold text-xl">My Bots</h3>
                        <a href="settings.php" class="bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 shadow transition-colors">+ Create New Bot</a>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-if="bots.length === 0">
                            <div class="col-span-full p-6 text-center text-gray-500 bg-gray-50 rounded-xl">
                                No bots configured. Please click "+ Create New Bot" to get started.
                            </div>
                        </template>
                        <template x-for="bot in bots" :key="bot.id">
                            <div class="bg-white rounded-xl shadow-md border p-5 hover:shadow-lg transition-shadow"
                                 :class="bot.active ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-gray-300'">
                                <!-- Header -->
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h4 class="font-bold text-lg text-gray-800" x-text="bot.name"></h4>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span x-show="bot.active" class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs font-bold">● ON</span>
                                            <span x-show="!bot.active" class="bg-gray-200 text-gray-500 px-2 py-0.5 rounded text-xs font-bold">○ OFF</span>
                                            <span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded text-xs font-bold" x-text="bot.pair"></span>
                                            <span x-show="bot.isRecovery" class="bg-orange-100 text-orange-700 px-2 py-0.5 rounded text-xs font-bold">🚑 Recovery</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-lg font-bold text-gray-800" x-text="'$' + Number(bot.capital).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></div>
                                        <div class="text-xs text-gray-400">capital</div>
                                    </div>
                                </div>

                                <!-- Status & PNL -->
                                <div x-show="bot.holdings > 0" class="bg-gray-50 rounded-lg p-3 mb-3">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="text-xs text-gray-500">Entry</span>
                                            <span class="font-mono font-bold ml-1" x-text="'$' + Number(bot.entry).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">Holdings</span>
                                            <span class="font-mono font-bold ml-1" x-text="Number(bot.holdings).toLocaleString(undefined, {maximumFractionDigits: 6})"></span>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">PNL</span>
                                            <span class="font-bold text-lg ml-1" :class="bot.pnl >= 0 ? 'text-green-600' : 'text-red-500'"
                                                  x-text="(bot.pnl >= 0 ? '+' : '') + Number(bot.pnl).toFixed(2) + '%'"></span>
                                        </div>
                                    </div>
                                </div>
                                <div x-show="!(bot.holdings > 0)" class="bg-gray-50 rounded-lg p-3 mb-3 text-center">
                                    <span class="text-sm text-gray-400">⏳ Idle — waiting for entry signal</span>
                                </div>

                                <!-- Conditions -->
                                <div class="flex flex-wrap gap-1.5 mb-3">
                                    <template x-for="(c, i) in bot.baseConds" :key="'b'+i">
                                        <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs" x-text="c.type"></span>
                                    </template>
                                    <template x-if="bot.dcaSteps > 0">
                                        <span class="bg-purple-50 text-purple-700 px-2 py-0.5 rounded text-xs" x-text="'DCA: ' + bot.dcaSteps + 'x'"></span>
                                    </template>
                                    <span class="bg-green-50 text-green-700 px-2 py-0.5 rounded text-xs" x-text="'TP: ' + bot.tp + '%'"></span>
                                    <template x-if="bot.slEnabled">
                                        <span class="bg-red-50 text-red-700 px-2 py-0.5 rounded text-xs" x-text="'SL: ' + bot.sl + '%'"></span>
                                    </template>
                                    <template x-for="(c, i) in bot.sellConds" :key="'s'+i">
                                        <span class="bg-yellow-50 text-yellow-700 px-2 py-0.5 rounded text-xs" x-text="'Sell: ' + c.type"></span>
                                    </template>
                                </div>

                                <!-- Composite Deals -->
                                <div x-show="bot.clones.length > 0" class="border-t pt-2 mt-2">
                                    <span class="text-xs font-bold text-indigo-600" x-text="'Active Deals (' + bot.clones.length + '/' + bot.maxDeals + ')'"></span>
                                    <template x-for="clone in bot.clones" :key="clone.id">
                                        <div class="flex justify-between items-center text-xs mt-1 py-1 px-2 bg-indigo-50 rounded">
                                            <span class="font-bold text-indigo-800" x-text="'↳ ' + clone.coin_pair"></span>
                                            <span class="font-bold" :class="(clone.runtime_state.live_pnl_percent || 0) >= 0 ? 'text-green-600' : 'text-red-500'"
                                                  x-text="((clone.runtime_state.live_pnl_percent || 0) >= 0 ? '+' : '') + Number(clone.runtime_state.live_pnl_percent || 0).toFixed(2) + '%'"></span>
                                        </div>
                                    </template>
                                </div>

                                <!-- Actions -->
                                <div class="flex gap-2 mt-3 pt-3 border-t">
                                    <a :href="'settings.php?id=' + bot.id" class="text-indigo-600 hover:bg-indigo-50 px-3 py-1 rounded text-sm font-medium transition">✏️ Edit</a>
                                    <a :href="'settings.php?id=' + bot.id + '&backtest=1'" class="text-orange-600 hover:bg-orange-50 px-3 py-1 rounded text-sm font-medium transition">📊 Backtest</a>
                                    <button x-show="bot.isRecovery" @click="deleteBot(bot)" class="text-red-500 hover:bg-red-50 px-3 py-1 rounded text-sm font-medium transition ml-auto">🛑 Stop Recovery</button>
                                    <button x-show="!bot.isRecovery" @click="deleteBot(bot)" class="text-red-400 hover:bg-red-50 px-3 py-1 rounded text-sm font-medium transition ml-auto">🗑️ Delete</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

        </div>

        <!-- Add Exchange API Modal -->
        <div x-show="showApiModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div @click.away="showApiModal = false" class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold">Bind Exchange API</h2>
                    <button @click="showApiModal = false" class="text-gray-500 hover:text-red-500 text-2xl font-bold">&times;</button>
                </div>

                <form @submit.prevent="addApiKey()" class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold mb-1">Exchange</label>
                        <select x-model="newApi.exchange_name" class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500 bg-white">
                            <option value="binance">Binance</option>
                            <option value="kucoin">KuCoin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">API Key</label>
                        <input type="text" x-model="newApi.api_key" required class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">API Secret / Private Key</label>
                        <input type="password" x-model="newApi.api_secret" required class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div class="pt-4">
                        <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-2 rounded hover:bg-indigo-700">Save API Keys</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    const API = 'api/v1/index.php';
    const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

    async function apiFetch(path, options = {}) {
        const opts = { headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, ...options };
        const resp = await fetch(API + path, opts);
        const json = await resp.json().catch(() => ({ ok: false, error: 'Invalid JSON response' }));
        if (!json.ok) throw new Error(json.error || ('HTTP ' + resp.status));
        return json.data;
    }

    function dashboard() {
        return {
            message: '',
            showApiModal: false,
            newApi: { exchange_name: 'binance', api_key: '', api_secret: '' },
            portfolio: { total_value: 0, connected: false, assets: {}, error: null },
            apiKeys: [],
            bots: [],

            async init() {
                await Promise.all([this.loadPortfolio(), this.loadApiKeys(), this.loadBots()]);
            },

            async loadPortfolio() {
                try {
                    const data = await apiFetch('/portfolio');
                    this.portfolio = data.portfolio;
                } catch (e) {
                    this.portfolio = { total_value: 0, connected: false, assets: {}, error: e.message };
                }
            },

            async loadApiKeys() {
                try {
                    const keys = await apiFetch('/api-keys');
                    this.apiKeys = keys.map(k => ({ ...k, _status: 'idle', _message: '' }));
                } catch (e) {
                    console.error('Failed to load API keys:', e);
                }
            },

            async loadBots() {
                try {
                    const grouped = await apiFetch('/bots/grouped');
                    this.bots = grouped.parents.map(bot => {
                        const cfg = bot.configuration || {};
                        const state = bot.runtime_state || {};
                        const rm = cfg.risk_management || {};
                        return {
                            id: bot.id,
                            name: (cfg.general && cfg.general.name) || ('Bot #' + bot.id),
                            active: !!bot.status,
                            pair: bot.coin_pair || 'Multi-Pair',
                            capital: bot.allocated_capital,
                            maxDeals: (cfg.general && cfg.general.max_active_deals) || 1,
                            holdings: state.current_holdings || 0,
                            pnl: state.live_pnl_percent || 0,
                            entry: state.average_entry_price || 0,
                            tp: rm.target_profit !== undefined ? rm.target_profit : 2,
                            sl: rm.cut_loss_percent !== undefined ? rm.cut_loss_percent : 0,
                            slEnabled: !!rm.cut_loss_enabled,
                            dcaSteps: (cfg.dca && cfg.dca.max_steps) || 0,
                            baseConds: (cfg.base_order && cfg.base_order.conditions) || [],
                            sellConds: rm.sell_conditions || [],
                            isRecovery: !!state.is_recovery_bot,
                            clones: grouped.clones[bot.id] || [],
                        };
                    });
                } catch (e) {
                    console.error('Failed to load bots:', e);
                }
            },

            async testConn(api) {
                api._status = 'loading';
                api._message = '';
                try {
                    const res = await apiFetch('/api-keys/' + api.id + '/test', { method: 'POST' });
                    api._status = res.connected ? 'success' : 'error';
                    api._message = res.message;
                } catch (e) {
                    api._status = 'error';
                    api._message = 'Network or server error.';
                }
            },

            async addApiKey() {
                try {
                    await apiFetch('/api-keys', { method: 'POST', body: JSON.stringify(this.newApi) });
                    this.showApiModal = false;
                    this.newApi = { exchange_name: 'binance', api_key: '', api_secret: '' };
                    this.message = 'Exchange API added successfully!';
                    await this.loadApiKeys();
                    await this.loadPortfolio();
                } catch (e) {
                    alert('Error: ' + e.message);
                }
            },

            async deleteBot(bot) {
                const label = bot.isRecovery ? 'Stop recovery?' : 'Delete bot?';
                if (!confirm(label)) return;
                try {
                    const res = await apiFetch('/bots/' + bot.id, { method: 'DELETE' });
                    this.message = res.recovery_cancelled
                        ? 'Recovery Mode cancelled manually. All other bots have been re-enabled.'
                        : 'Bot deleted successfully.';
                    await this.loadBots();
                } catch (e) {
                    alert('Error: ' + e.message);
                }
            },
        };
    }
    </script>
    <script>
    // Live Price Ticks — auto-refresh every 5 seconds.
    // Uses the first active bot (falls back to bot 1).
    let tickBotId = null;
    async function resolveTickBot() {
        try {
            const r = await fetch(API + '/bots', { headers: { 'X-CSRF-Token': CSRF } });
            const json = await r.json();
            if (json.ok && Array.isArray(json.data)) {
                const active = json.data.find(b => b.status == 1 && !b.parent_id);
                if (active) { tickBotId = active.id; return; }
            }
        } catch (e) { /* ignore */ }
        tickBotId = 1;
    }
    async function loadTicks() {
        if (tickBotId === null) await resolveTickBot();
        try {
            const r = await fetch(API + '/market/ticks/' + tickBotId + '?_=' + Date.now());
            const json = await r.json();
            const ticks = json.ok ? json.data : [];
            const tbody = document.getElementById('tick-body');
            const status = document.getElementById('tick-status');

            if (!ticks.length) {
                tbody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-gray-400">Waiting for daemon ticks...</td></tr>';
                status.innerHTML = '⏳ No data yet';
                return;
            }

            const last = ticks[ticks.length - 1];
            status.innerHTML = '🟢 Live · ' + ticks.length + ' ticks · $' + Number(last.price).toLocaleString();

            tbody.innerHTML = ticks.map(t => {
                const time = t.created_at ? t.created_at.slice(-8) : '';
                const price = '$' + Number(t.price).toLocaleString(undefined, {minimumFractionDigits: 2});
                const pnl = Number(t.pnl_percent);
                const pnlClass = pnl > 0 ? 'text-green-600' : (pnl < 0 ? 'text-red-500' : 'text-gray-400');
                const pnlStr = pnl !== 0 ? (pnl > 0 ? '+' : '') + pnl.toFixed(2) + '%' : '0.00%';
                return '<tr class="border-b border-gray-100 hover:bg-gray-50">' +
                    '<td class="p-2 text-gray-400">' + time + '</td>' +
                    '<td class="p-2 text-right font-bold">' + price + '</td>' +
                    '<td class="p-2 text-right font-bold ' + pnlClass + '">' + pnlStr + '</td>' +
                    '</tr>';
            }).join('');
        } catch(e) {
            document.getElementById('tick-status').innerHTML = '🔴 Error';
        }
    }
    loadTicks();
    setInterval(loadTicks, 5000);
    </script>
</body>
</html>
