<?php
// File: public/settings.php — Bot editor shell (Phase 1: API-first, no direct DB access).
// All data is loaded from the v1 REST API by the JavaScript below.

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
    <title>Fixzy Kriptobot - Advanced Bot Configuration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
        .locked-input {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #f9fafb !important;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans pb-24">

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
                <a href="settings.php" class="text-indigo-300 border-b-2 border-indigo-300 pb-1">New Bot</a>
                <a href="configure.php" class="hover:text-indigo-300">Global Settings</a>
                <a href="faq.php" class="hover:text-indigo-300">FAQ's</a>
                <span class="text-indigo-400">|</span>
                <span class="text-gray-300 font-normal">User: <?= htmlspecialchars($_SESSION['user_email']) ?></span>
                <a href="logout.php" class="text-red-400 hover:text-red-300 ml-2">Logout</a>
            </div>
        </div>
        <div x-show="navOpen" @click.away="navOpen = false" class="md:hidden bg-indigo-950 px-4 py-2 space-y-1 pb-4" x-transition x-cloak>
            <a href="index.php" class="block py-2 hover:text-indigo-300">📊 Dashboard</a>
            <a href="agent.php" class="block py-2 hover:text-indigo-300">🤖 AI Agent</a>
            <a href="settings.php" class="block py-2 text-indigo-300 font-semibold">🆕 New Bot</a>
            <a href="configure.php" class="block py-2 hover:text-indigo-300">⚙️ Settings</a>
            <a href="faq.php" class="block py-2 hover:text-indigo-300">📚 FAQ</a>
            <hr class="border-indigo-800 my-1">
            <span class="block py-1 text-gray-400 text-xs"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
            <a href="logout.php" class="block py-2 text-red-400 font-semibold">🚪 Logout</a>
        </div>
    </nav>

<script>
        // Phase 1: all page data comes from the v1 REST API (no server-side SQL here).
        window.API_BASE = 'api/v1/index.php';
        window.csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
        window.currentBotId = new URLSearchParams(window.location.search).get('id') || null;
        window.initialBotData = null;      // hydrated from the API below
        window.isDemoMode = true;         // hydrated from the API below
        window.availableExchanges = [];   // hydrated from the API below
        window.availablePairs = [];       // hydrated from the API below
        window.featureStatuses = [];     // feature gating status (enabled/disabled + reason)

        async function apiLoad() {
            const headers = { 'X-CSRF-Token': window.csrfToken };
            try {
                const env = await fetch(window.API_BASE + '/settings/environment', { headers }).then(r => r.json());
                if (env.ok) window.isDemoMode = !!env.data.is_demo_mode;
            } catch (e) { console.error('env load failed', e); }
            try {
                const ex = await fetch(window.API_BASE + '/api-keys', { headers }).then(r => r.json());
                if (ex.ok) window.availableExchanges = [...new Set(ex.data.map(k => k.exchange_name))];
            } catch (e) { console.error('exchanges load failed', e); }
            try {
                const pr = await fetch(window.API_BASE + '/market/pairs', { headers }).then(r => r.json());
                if (pr.ok) window.availablePairs = pr.data;

            } catch (e) { console.error('pairs load failed', e); }
            try {
                const fs = await fetch(window.API_BASE + '/settings/features', { headers }).then(r => r.json());
                if (fs.ok) window.featureStatuses = fs.data.features || [];
            } catch (e) { console.error('feature status load failed', e); }
            if (window.currentBotId) {
                try {
                    const bot = await fetch(window.API_BASE + '/bots/' + encodeURIComponent(window.currentBotId), { headers }).then(r => r.json());
                    if (bot.ok) {
                        const d = bot.data;
                        const cfg = d.configuration || {};
                        cfg.is_enabled = !!d.status;
                        cfg.runtime_state = d.runtime_state;
                        if (cfg.general) cfg.general.is_custom_capital = (cfg.general.capital ?? 'AUTO') !== 'AUTO';
                        window.initialBotData = cfg;
                    } else {
                        window.location.href = 'index.php';
                    }
                } catch (e) { console.error('bot load failed', e); window.location.href = 'index.php'; }
            }
        }
        const pageDataReady = apiLoad();
    </script>

    <div class="max-w-5xl mx-auto p-3 md:p-6 mt-2 md:mt-4" x-data="botEditor()" x-cloak>

        <!-- FEATURE STATUS BANNER: shows which integrations are enabled/verified and why others are disabled -->
        <div x-show="featureStatuses.some(f => f.status !== 'enabled')" class="mb-6 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                <h3 class="font-bold text-gray-700 text-sm">🔌 Integration & Feature Status</h3>
                <button type="button" @click="verifyFeatures()" :disabled="verifyingFeatures"
                        class="text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded px-3 py-1.5 transition-colors"
                        x-text="verifyingFeatures ? 'Verifying…' : 'Re-verify All'"></button>
            </div>
            <div class="px-5 py-3 space-y-2">
                <template x-for="f in featureStatuses.filter(f => f.status !== 'enabled')" :key="f.key">
                    <div class="flex items-start gap-2 text-xs" :class="f.required ? 'text-red-700' : 'text-amber-700'">
                        <span x-text="f.required ? '❌' : '⚠️'"></span>
                        <div>
                            <strong x-text="f.label"></strong>
                            <span class="font-semibold" x-text="f.required ? ' (COMPULSORY) — ' : ' (optional) — '"></span>
                            <span x-text="f.reason"></span>
                            <div class="text-gray-500 mt-0.5" x-text="'How to enable: ' + f.hint"></div>
                        </div>
                    </div>
                </template>
                <template x-for="f in featureStatuses.filter(f => f.status === 'enabled')" :key="'on-'+f.key">
                    <div class="flex items-start gap-2 text-xs text-green-700">
                        <span>✅</span>
                        <div><strong x-text="f.label"></strong> enabled &amp; verified<span x-show="f.last_verified_at" class="text-gray-400" x-text="' (' + f.last_verified_at + ')'"></span></div>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800" x-text="window.currentBotId ? 'Edit Bot #' + window.currentBotId : 'Create New Bot'"></h1>
                <p class="text-gray-500 mt-1">Configure your trading strategy workflow.</p>
            </div>
            
            <div class="flex items-center gap-4">
                <!-- Backtest button (only shown when editing an existing bot) -->
                <template x-if="window.currentBotId">
                    <button type="button" @click="openBacktestModal()" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-lg shadow-sm hover:shadow-md hover:from-purple-700 hover:to-indigo-700 transition-all text-sm font-bold">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        Backtest Strategy
                    </button>
                </template>

                <label class="flex items-center cursor-pointer bg-white px-4 py-2 rounded-lg shadow-sm border border-gray-200">
                    <div class="relative">
                        <input type="checkbox" x-model="form.is_enabled" class="sr-only">
                        <div class="block bg-gray-300 w-12 h-7 rounded-full transition-colors duration-300" :class="{'bg-green-500': form.is_enabled}"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-5 h-5 rounded-full transition-transform duration-300" :class="{'transform translate-x-5': form.is_enabled}"></div>
                    </div>
                    <div class="ml-3 font-bold text-gray-700">
                        <span x-text="form.is_enabled ? 'BOT: ACTIVE' : 'BOT: DISABLED'"></span>
                    </div>
                </label>
            </div>
        </div>

        <form @submit.prevent="saveBot" class="space-y-6">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-bold mb-4 flex items-center text-indigo-800 border-b pb-2">
                    <span class="bg-indigo-100 text-indigo-800 w-8 h-8 rounded-full inline-flex items-center justify-center mr-3">1</span>
                    General Settings
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-2">Bot Name</label>
                        <input type="text" x-model="form.general.name" required class="w-full border rounded p-2 outline-none" placeholder="e.g., Composite Aggressive Bot">
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">
                            Exchange
                            <button type="button" @click="showHelp('exchange')" class="ml-1 text-indigo-400 hover:text-indigo-600 focus:outline-none transition-colors">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                            </button>
                        </label>
                        <select x-model="form.general.exchange" required :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="w-full border rounded p-2 bg-white outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="" disabled>-- Select Live Exchange --</option>
                        
                        <!-- 2. REMOVE 'window.' HERE -->
                        <template x-for="ex in availableExchanges" :key="ex">
                            <option :value="ex" x-text="ex.toUpperCase()"></option>
                        </template>
                    </select>

                    <!-- If you have an error message for an empty list, update it too: -->
                    <template x-if="availableExchanges.length === 0">
                        <p class="text-[10px] text-red-500 mt-1 font-bold">⚠️ Please enter the API Key in the Settings page first.</p>
                    </template>

                        <!-- Smart notification when Demo Mode is active -->
                        <template x-if="window.isDemoMode">
                            <div class="mt-2 bg-amber-50 border border-amber-200 rounded p-2 flex items-start">
                                <span class="text-amber-500 mr-2">ℹ️</span>
                                <p class="text-[10px] text-amber-800 font-semibold leading-tight">
                                    Demo Mode Active: The above selection is for Live reference only. The engine will automatically run on <strong>Binance Testnet</strong> in the background.
                                </p>
                            </div>
                        </template>
                    </div>
                    <div>
                        <label class="flex items-center space-x-2 text-xs font-bold text-gray-600 mb-2">
                            <span>Allocated Capital (USDT)</span>
                            <button type="button" @click="showHelp('capital')" class="text-indigo-400 hover:text-indigo-600 focus:outline-none transition-colors">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                            </button>
                             <input type="checkbox" x-model="form.general.is_custom_capital" @change="if(!form.general.is_custom_capital) form.general.capital = 'AUTO'" :disabled="isLockedActive()" class="rounded text-indigo-500 focus:ring-indigo-500 ml-2">
                            <span class="text-[10px] text-gray-500 font-normal" :class="{'opacity-50': isLockedActive()}">Custom</span>
                        </label>
                        
                        <div x-show="!form.general.is_custom_capital">
                            <input type="text" readonly value="AUTO (90% of Live Wallet)" class="w-full border rounded p-2 outline-none bg-indigo-50 text-indigo-800 font-bold border-indigo-200 cursor-not-allowed">
                        </div>

                         <div x-show="form.general.is_custom_capital">
                            <input type="number" step="0.01" :min="calculateMinCapital()" x-model="form.general.capital" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="w-full border rounded p-2 outline-none" placeholder="e.g., 100">
                            <p class="text-[10px] text-indigo-600 font-bold mt-1">
                                Min Required: $<span x-text="calculateMinCapital()"></span> 
                                <span class="text-gray-400 font-normal ml-1">(Base Order: $10.50)</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-gray-50 p-4 rounded border border-gray-100">
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">
                            Coin Pair Strategy
                            <button type="button" @click="showHelp('pair_strategy')" class="ml-1 text-indigo-400 hover:text-indigo-600 focus:outline-none transition-colors">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                            </button>
                        </label>
                         <select x-model="form.general.pair_strategy" @change="if(form.general.pair_strategy === 'single') form.general.custom_pairs = ''" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="w-full border rounded p-2 bg-white outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="single">Single Pair (e.g., BTC/USDT)</option>
                            <option value="custom_list">Custom Multiple Pairs</option>
                            <option value="top_10_volume">Top 10 by Volume (24h)</option>
                            <option value="top_10_volatility">Top 10 by Volatility (24h)</option>
                            <option value="top_50_global">Top 50 Global Market Cap</option>
                        </select>

                        <div x-show="form.general.pair_strategy === 'single' || form.general.pair_strategy === 'custom_list'">
                            <select x-ref="customPairsSelect" multiple class="w-full"></select>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="flex items-center text-xs font-bold text-gray-600 mb-2">
                                Max Active Deals
                                <button type="button" @click="showHelp('max_deals')" class="ml-1 text-indigo-400 hover:text-indigo-600 focus:outline-none transition-colors">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                                </button>
                            </label>
                             <input type="number" x-model="form.general.max_active_deals" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="w-full border rounded p-2 focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="1">
                            <p class="text-[10px] text-gray-400 mt-1">Composite Bot architecture: the number of simultaneous pairs this bot is allowed to trade.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-bold mb-4 flex items-center text-blue-800 border-b pb-2">
                    <span class="bg-blue-100 text-blue-800 w-8 h-8 rounded-full inline-flex items-center justify-center mr-3">2</span>
                    Base Order (Initial Entry)
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">
                            Start Order Type
                            <button type="button" @click="showHelp('order_type')" class="ml-1 text-indigo-400 hover:text-indigo-600 focus:outline-none transition-colors">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                            </button>
                        </label>
                         <div class="flex space-x-6 p-2 border rounded bg-gray-50" :class="{'opacity-50 cursor-not-allowed': isLockedActive()}">
                            <label class="flex items-center cursor-pointer"><input type="radio" x-model="form.base_order.order_type" value="market" :disabled="isLockedActive()" class="mr-2"> Market</label>
                            <label class="flex items-center cursor-pointer"><input type="radio" x-model="form.base_order.order_type" value="limit" :disabled="isLockedActive()" class="mr-2"> Try Limit 1st</label>
                        </div>
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">
                            Buy Cooldown (Seconds)
                            <button type="button" @click="showHelp('buy_cooldown')" class="ml-1 text-indigo-400 hover:text-indigo-600 focus:outline-none transition-colors">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                            </button>
                        </label>
                        <input type="number" x-model="form.base_order.cooldown_seconds" class="w-full border rounded p-2 focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="7200">
                    </div>
                </div>

                <div class="mb-6 p-4 border border-blue-100 bg-blue-50 rounded">
                    <label class="flex items-center font-bold cursor-pointer text-blue-900 mb-2">
                        <input type="checkbox" x-model="form.base_order.trailing_enabled" class="mr-3 w-5 h-5 rounded">
                        Enable Trailing Buy
                        <button type="button" @click="showHelp('trailing_buy')" class="ml-2 text-blue-400 hover:text-blue-600 focus:outline-none transition-colors">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                        </button>
                    </label>
                    <div x-show="form.base_order.trailing_enabled" class="pl-8" x-transition>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Trailing Deviation (%)</label>
                        <input type="number" step="0.1" x-model="form.base_order.trailing_deviation" class="w-48 border rounded p-2" placeholder="0.5">
                    </div>
                </div>

                <div class="border rounded-lg p-4">
                    <div class="flex items-center mb-3">
                        <h3 class="font-bold text-sm text-gray-800">Trade Start Conditions (Logical AND)</h3>
                        <button type="button" @click="showHelp('start_conditions')" class="ml-2 text-blue-400 hover:text-blue-600 focus:outline-none transition-colors">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                        </button>
                    </div>
                    
                    <template x-for="(condition, index) in form.base_order.conditions" :key="index">
                        <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-3 mb-3 bg-gray-50 p-3 border rounded shadow-sm">
                            <select x-model="condition.type" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                <option value="start_asap">🚀 Start ASAP (Execute Immediately)</option>
                                <option value="tv_webhook">TradingView Webhook</option>
                                <option value="external_signal">🔌 External API Signal</option>
                                <option value="news_sentiment">News Sentiment AI</option>
                                <option value="ai_market">🧠 AI Market Analysis (OHLCV)</option>
                                <option value="rsi">RSI</option>
                                <option value="bollinger">Bollinger Bands</option>
                                <option value="qfl">Quickfingers Luc (QFL)</option>
                            </select>

                            <select x-show="['rsi', 'bollinger', 'qfl', 'ai_market'].includes(condition.type)" x-model="condition.timeframe" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                <option value="1m">1m</option>
                                <option value="5m">5m</option>
                                <option value="15m">15m</option>
                                <option value="1h">1h</option>
                                <option value="4h">4h</option>
                                <option value="1d">1d</option>
                            </select>

                            <input x-show="condition.type === 'rsi'" type="number" x-model="condition.period" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Length (e.g. 14)">
                            
                            <input x-show="condition.type === 'bollinger'" type="number" x-model="condition.period" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Period (e.g. 20)">
                            <input x-show="condition.type === 'bollinger'" type="number" step="0.1" x-model="condition.stddev" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="border rounded p-2 text-sm flex-1 w-full" placeholder="StdDev (e.g. 2.0)">

                            <select x-model="condition.value" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="border rounded p-2 text-sm flex-1 w-full bg-white">
                                <template x-for="opt in getConditionValueOptions(condition.type, 'buy')" :key="opt.value">
                                    <option :value="opt.value" x-text="opt.label"></option>
                                </template>
                            </select>
                             <button type="button" @click="removeCondition('base_order', index)" :disabled="isLockedActive()" :class="{'opacity-50 cursor-not-allowed': isLockedActive()}" class="text-red-500 font-bold px-2 py-1 bg-red-100 rounded">&times; Remove</button>
                        </div>
                    </template>
                    
                    <button type="button" @click="addCondition('base_order')" :disabled="isLockedActive()" :class="{'opacity-50 cursor-not-allowed': isLockedActive()}" class="bg-blue-100 text-blue-700 px-4 py-2 rounded text-sm font-bold mt-2 hover:bg-blue-200 transition">+ Add Start Condition</button>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center mb-4 border-b pb-2">
                    <h2 class="text-lg font-bold flex items-center text-yellow-600">
                        <span class="bg-yellow-100 text-yellow-700 w-8 h-8 rounded-full inline-flex items-center justify-center mr-3">3</span>
                        Safety Orders (DCA)
                    </h2>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">Max Safety Trades Count
                            <button type="button" @click="showHelp('dca_max_steps')" class="ml-1 text-gray-400 hover:text-yellow-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                         <input type="number" x-model="form.dca.max_steps" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="w-full border rounded p-2" placeholder="4">
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">
                            <span x-text="form.dca.custom_conditions_enabled ? 'Minimum Price Drop Depth (%)' : 'Price Drop Trigger (%)'"></span>
                            <button type="button" @click="showHelp('dca_price_drop')" class="ml-1 text-gray-400 hover:text-yellow-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                         <input type="number" step="0.1" x-model="form.dca.price_drop_trigger" :disabled="isLockedDca()" :class="{'locked-input': isLockedDca()}" class="w-full border rounded p-2" placeholder="5.0">
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">Volume Scale (Capital Multiplier)
                            <button type="button" @click="showHelp('dca_volume_scale')" class="ml-1 text-gray-400 hover:text-yellow-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                         <input type="number" step="0.1" x-model="form.dca.volume_scale" :disabled="isLockedActive()" :class="{'locked-input': isLockedActive()}" class="w-full border rounded p-2" placeholder="1.5">
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">Step Scale (Distance Multiplier)
                            <button type="button" @click="showHelp('dca_step_scale')" class="ml-1 text-gray-400 hover:text-yellow-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                         <input type="number" step="0.1" x-model="form.dca.step_scale" :disabled="isLockedDca()" :class="{'locked-input': isLockedDca()}" class="w-full border rounded p-2" placeholder="1.2">
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">Place Orders on Exchange?
                            <button type="button" @click="showHelp('dca_placed_exchange')" class="ml-1 text-gray-400 hover:text-yellow-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <select x-model="form.dca.placed_on_exchange" :disabled="form.dca.custom_conditions_enabled || form.dca.trailing_enabled" :class="{'bg-gray-100 cursor-not-allowed': form.dca.custom_conditions_enabled || form.dca.trailing_enabled, 'bg-white': !(form.dca.custom_conditions_enabled || form.dca.trailing_enabled)}" class="w-full border rounded p-2">
                            <option value="no">No (Trigger market buy live)</option>
                            <option value="yes">Yes (Place limit orders in advance)</option>
                        </select>
                        <p x-show="form.dca.custom_conditions_enabled || form.dca.trailing_enabled" class="text-[10px] text-red-600 mt-1 font-semibold">Locked to 'NO' because Custom/Trailing DCA requires live tracking.</p>
                    </div>
                </div>

                <div class="mb-6 border rounded-lg p-4">
                    <label class="flex items-center font-bold cursor-pointer text-gray-800 mb-2">
                        <input type="checkbox" x-model="form.dca.custom_conditions_enabled" 
                               @change="if(form.dca.custom_conditions_enabled) form.dca.placed_on_exchange = 'no'" 
                               class="mr-3 w-5 h-5 rounded text-yellow-600 focus:ring-yellow-500">
                        Enable Custom DCA Conditions (Smart Averaging)
                        <button type="button" @click="showHelp('dca_custom_conditions')" class="ml-2 text-gray-400 hover:text-yellow-600"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                    </label>
                    <p class="text-xs text-gray-500 mb-4 ml-8">If enabled, the Price Drop (%) above acts as a <strong class="text-gray-800">minimum depth requirement</strong>. The bot will ONLY execute the DCA step if the price has dropped deep enough AND the conditions below are met.</p>
                    
                    <div x-show="form.dca.custom_conditions_enabled" class="ml-8 border-t pt-4" x-transition>
                        <template x-for="(condition, index) in form.dca.conditions" :key="index">
                            <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-3 mb-3 bg-gray-50 p-3 border rounded shadow-sm">
                                <select x-model="condition.type" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                    <option value="start_asap">🚀 ASAP (Buy right after the Drop %)</option>
                                    <option value="tv_webhook">TradingView Webhook</option>
                                    <option value="external_signal">🔌 External API Signal</option>
                                    <option value="news_sentiment">News Sentiment AI</option>
                                <option value="ai_market">🧠 AI Market Analysis (OHLCV)</option>
                                    <option value="rsi">RSI</option>
                                    <option value="bollinger">Bollinger Bands</option>
                                    <option value="qfl">Quickfingers Luc (QFL)</option>
                                </select>
                                
                                <select x-show="['rsi', 'bollinger', 'qfl', 'ai_market'].includes(condition.type)" x-model="condition.timeframe" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                    <option value="1m">1m</option>
                                    <option value="5m">5m</option>
                                    <option value="15m">15m</option>
                                    <option value="1h">1h</option>
                                    <option value="4h">4h</option>
                                    <option value="1d">1d</option>
                                </select>

                                <input x-show="condition.type === 'rsi'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Length (e.g. 14)">
                                
                                <input x-show="condition.type === 'bollinger'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Period (e.g. 20)">
                                <input x-show="condition.type === 'bollinger'" type="number" step="0.1" x-model="condition.stddev" class="border rounded p-2 text-sm flex-1 w-full" placeholder="StdDev (e.g. 2.0)">

                                <select x-model="condition.value" class="border rounded p-2 text-sm flex-1 w-full bg-white">
                                    <template x-for="opt in getConditionValueOptions(condition.type, 'buy')" :key="opt.value">
                                        <option :value="opt.value" x-text="opt.label"></option>
                                    </template>
                                </select>
                                <button type="button" @click="removeCondition('dca', index)" class="text-red-500 font-bold px-2 py-1 bg-red-100 rounded">&times; Remove</button>
                            </div>
                        </template>
                        
                        <button type="button" @click="addCondition('dca')" class="bg-yellow-100 text-yellow-800 px-4 py-2 rounded text-sm font-bold mt-2 hover:bg-yellow-200 transition">+ Add DCA Condition</button>
                    </div>
                </div>

                <div class="p-4 border border-yellow-100 bg-yellow-50 rounded">
                    <label class="flex items-center font-bold cursor-pointer text-yellow-800 mb-2">
                        <input type="checkbox" x-model="form.dca.trailing_enabled" 
                               @change="if(form.dca.trailing_enabled) form.dca.placed_on_exchange = 'no'"
                               class="mr-3 w-5 h-5 rounded text-yellow-600">
                        Enable Trailing for Safety Orders
                        <button type="button" @click="showHelp('dca_trailing')" class="ml-2 text-yellow-600 hover:text-yellow-800"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                    </label>
                    <div x-show="form.dca.trailing_enabled" class="pl-8" x-transition>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Trailing Deviation (%)</label>
                        <input type="number" step="0.1" x-model="form.dca.trailing_deviation" class="w-48 border rounded p-2 bg-white" placeholder="0.5">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-bold mb-4 flex items-center text-green-700 border-b pb-2">
                    <span class="bg-green-100 text-green-800 w-8 h-8 rounded-full inline-flex items-center justify-center mr-3">4</span>
                    Take Profit & Risk Management
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">Take Profit Type
                            <button type="button" @click="showHelp('tp_type')" class="ml-1 text-gray-400 hover:text-green-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <select x-model="form.risk_management.tp_type" class="w-full border rounded p-2 bg-white">
                            <option value="average_price">% from Average Price (Recommended for DCA)</option>
                            <option value="base_order">% from Base Order Price</option>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center text-xs font-bold text-gray-600 mb-2">Target Profit (%)
                            <button type="button" @click="showHelp('tp_target')" class="ml-1 text-gray-400 hover:text-green-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <input type="number" step="0.1" x-model="form.risk_management.target_profit" class="w-full border rounded p-2" placeholder="2.0">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="p-4 border border-green-100 bg-green-50 rounded">
                        <label class="flex items-center font-bold cursor-pointer text-green-900 mb-2">
                            <input type="checkbox" x-model="form.risk_management.trailing_tp_enabled" class="mr-3 w-5 h-5 rounded text-green-600">
                            Enable Trailing Take Profit
                            <button type="button" @click="showHelp('tp_trailing')" class="ml-2 text-gray-400 hover:text-green-600"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <div x-show="form.risk_management.trailing_tp_enabled" class="pl-8" x-transition>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Trailing Deviation (%)</label>
                            <input type="number" step="0.1" x-model="form.risk_management.trailing_tp_deviation" class="w-full border rounded p-2 bg-white" placeholder="0.2">
                        </div>
                    </div>

                    <div class="p-4 border border-red-100 bg-red-50 rounded">
                        <label class="flex items-center font-bold cursor-pointer text-red-900 mb-2">
                            <input type="checkbox" x-model="form.risk_management.cut_loss_enabled" class="mr-3 w-5 h-5 rounded text-red-600">
                            Enable Stop Loss / Cut Loss
                            <button type="button" @click="showHelp('tp_cut_loss')" class="ml-2 text-gray-400 hover:text-red-600"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <div x-show="form.risk_management.cut_loss_enabled" class="pl-8" x-transition>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Stop Loss Percentage (%)</label>
                            <input type="number" step="0.1" x-model="form.risk_management.cut_loss_percent" class="w-full border rounded p-2 bg-white mb-3" placeholder="5.0">
                            
                            <label class="flex items-center font-bold cursor-pointer text-red-800 text-sm">
                                <input type="checkbox" x-model="form.risk_management.trailing_sl_enabled" class="mr-2 rounded text-red-600">
                                Enable Trailing Stop Loss
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 border-t pt-4">
                    <div class="bg-gray-50 border p-4 rounded">
                        <label class="flex items-center font-bold cursor-pointer text-gray-800 mb-2">
                            <input type="checkbox" x-model="form.risk_management.min_guard_enabled" class="mr-3 w-5 h-5 text-indigo-600 rounded">
                            🛡️ Enable Minimum Profit Guard
                            <button type="button" @click="showHelp('tp_min_guard')" class="ml-2 text-gray-400 hover:text-indigo-600"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <div x-show="form.risk_management.min_guard_enabled" class="grid grid-cols-2 gap-4 pl-8" x-transition>
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">Min Profit (%)</label>
                                <input type="number" step="0.1" x-model="form.risk_management.min_guard_percent" class="w-full border rounded p-2">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">Timeout Bypass (Hours)</label>
                                <input type="number" x-model="form.risk_management.min_guard_timeout" class="w-full border rounded p-2">
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 border p-4 rounded">
                        <label class="flex items-center font-bold cursor-pointer text-gray-800 mb-2">
                            <input type="checkbox" x-model="form.risk_management.partial_sell_enabled" class="mr-3 w-5 h-5 text-indigo-600 rounded">
                            ✂️ Enable Partial Sell (Scaling Out)
                            <button type="button" @click="showHelp('tp_partial_sell')" class="ml-2 text-gray-400 hover:text-indigo-600"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                        </label>
                        <div x-show="form.risk_management.partial_sell_enabled" class="pl-8" x-transition>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Targets (%) - Comma separated</label>
                            <input type="text" x-model="form.risk_management.partial_targets" class="w-full border rounded p-2" placeholder="1.0, 1.5, 2.0">
                        </div>
                    </div>
                </div>

                <div class="border rounded-lg p-4 mt-6">
                    <h3 class="flex items-center font-bold text-sm text-gray-800 mb-3">Custom Sell / Cut Loss Conditions (Logical OR)
                        <button type="button" @click="showHelp('tp_custom_sell')" class="ml-2 text-gray-400 hover:text-gray-600"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg></button>
                    </h3>
                    <template x-for="(condition, index) in form.risk_management.sell_conditions" :key="index">
                        <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-3 mb-3 bg-gray-50 p-3 border rounded shadow-sm">
                            <select x-model="condition.type" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                <option value="tv_webhook">TradingView Webhook</option>
                                <option value="external_signal">🔌 External API Signal</option>
                                <option value="news_sentiment">News Sentiment AI</option>
                                <option value="ai_market">🧠 AI Market Analysis (OHLCV)</option>
                                <option value="rsi">RSI</option>
                                <option value="bollinger">Bollinger Bands</option>
                                <option value="qfl">Quickfingers Luc (QFL)</option>
                            </select>

                            <select x-show="['rsi', 'bollinger', 'qfl', 'ai_market'].includes(condition.type)" x-model="condition.timeframe" class="border rounded p-2 text-sm bg-white w-full md:w-auto">
                                <option value="1m">1m</option>
                                <option value="5m">5m</option>
                                <option value="15m">15m</option>
                                <option value="1h">1h</option>
                                <option value="4h">4h</option>
                                <option value="1d">1d</option>
                            </select>

                            <input x-show="condition.type === 'rsi'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Length (e.g. 14)">
                            
                            <input x-show="condition.type === 'bollinger'" type="number" x-model="condition.period" class="border rounded p-2 text-sm flex-1 w-full" placeholder="Period (e.g. 20)">
                            <input x-show="condition.type === 'bollinger'" type="number" step="0.1" x-model="condition.stddev" class="border rounded p-2 text-sm flex-1 w-full" placeholder="StdDev (e.g. 2.0)">

                            <select x-model="condition.value" class="border rounded p-2 text-sm flex-1 w-full bg-white">
                                <template x-for="opt in getConditionValueOptions(condition.type, 'sell')" :key="opt.value">
                                    <option :value="opt.value" x-text="opt.label"></option>
                                </template>
                            </select>
                            <button type="button" @click="removeCondition('risk_management', index)" class="text-red-500 font-bold px-2 py-1 bg-red-100 rounded">&times; Remove</button>
                        </div>
                    </template>
                    <button type="button" @click="addCondition('risk_management')" class="bg-indigo-100 text-indigo-700 px-4 py-2 rounded text-sm font-bold mt-2 hover:bg-indigo-200 transition">+ Add Sell Condition</button>
                </div>
                
                <div class="mt-6 p-4 bg-gray-900 text-gray-300 text-sm rounded-lg flex items-start shadow-inner">
                    <span class="text-xl mr-3">💡</span>
                    <p><strong>Universal Smart Recovery</strong> is automatically active in the background. If any deal closes in a loss (via Cut Loss or Timeout), the system isolates the remaining capital to aggressively recover the exact lost amount in the next cycle.</p>
                </div>
            </div>

            <div class="fixed bottom-0 left-0 w-full bg-white border-t p-4 flex justify-end shadow-[0_-10px_20px_-10px_rgba(0,0,0,0.15)] z-40">
                <div class="max-w-5xl mx-auto w-full flex justify-between items-center">
                    <span id="saveStatus" class="text-sm font-bold text-gray-500 hidden md:block"></span>
                    <button type="submit" id="saveBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-10 rounded-lg shadow-md transition-all">
                        <span id="btnText">💾 Save Bot Configuration</span>
                    </button>
                </div>
            </div>
            
        </form>
<div class="mt-8 bg-indigo-50 border border-indigo-200 p-6 rounded-xl shadow-sm">
            <h3 class="text-indigo-900 text-lg font-bold mb-4 flex items-center">
                <span class="text-2xl mr-2">📖</span> Plain English Bot Strategy Summary
            </h3>
            
            <div class="space-y-4 text-sm text-gray-700 leading-relaxed">
                <div class="flex items-start">
                    <div class="bg-indigo-200 text-indigo-800 rounded-full w-6 h-6 flex items-center justify-center font-bold mr-3 mt-0.5 flex-shrink-0">1</div>
                    <div>
                        This bot, <strong class="text-indigo-900" x-text="form.general.name || '[Unnamed Bot]'"></strong>, will be deployed on <strong class="uppercase text-indigo-900" x-text="form.general.exchange"></strong> to trade 
                        <strong class="text-indigo-900" x-text="form.general.pair_strategy === 'single' || form.general.pair_strategy === 'custom_list' ? form.general.custom_pairs || '[No Pairs]' : form.general.pair_strategy"></strong>. 
                        It will use an initial capital of <strong class="text-green-700">$<span x-text="form.general.capital || '0.00'"></span></strong> per deal. 
                        It will open a <strong class="uppercase" x-text="form.base_order.order_type"></strong> order ONLY when ALL of the following start conditions are met:
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="(cond, index) in form.base_order.conditions" :key="index">
                                <span class="bg-white border border-indigo-300 text-indigo-700 px-2 py-1 rounded-md text-xs font-mono shadow-sm" x-text="formatConditionText(cond)"></span>
                            </template>
                        </div>
                        <p x-show="form.base_order.trailing_enabled" class="mt-2 text-indigo-600 text-xs font-semibold">↳ Trailing Buy is ON: It will wait for the price to reverse by <span x-text="form.base_order.trailing_deviation"></span>% before buying to get a cheaper entry.</p>
                    </div>
                </div>

                <div class="flex items-start">
                    <div class="bg-yellow-200 text-yellow-800 rounded-full w-6 h-6 flex items-center justify-center font-bold mr-3 mt-0.5 flex-shrink-0">2</div>
                    <div>
                        If the market goes against the bot and drops by <strong class="text-yellow-700"><span x-text="form.dca.price_drop_trigger"></span>%</strong>, it prepares a Safety Order (DCA). 
                        It will repeat this averaging-down process a maximum of <strong class="text-yellow-700" x-text="form.dca.max_steps"></strong> times.
                        
                        <div x-show="!form.dca.custom_conditions_enabled" class="mt-1 text-gray-600">
                            It will execute the safety buy <strong class="text-gray-800">immediately</strong> once the drop percentage is reached.
                        </div>
                        
                        <div x-show="form.dca.custom_conditions_enabled" class="mt-2">
                            However, Smart Averaging is ON. It will <strong class="text-red-600">WAIT</strong> and ONLY buy if these conditions are met after the drop:
                            <div class="mt-2 flex flex-wrap gap-2">
                                <template x-for="(cond, index) in form.dca.conditions" :key="index">
                                    <span class="bg-white border border-yellow-300 text-yellow-800 px-2 py-1 rounded-md text-xs font-mono shadow-sm" x-text="formatConditionText(cond)"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-start">
                    <div class="bg-green-200 text-green-800 rounded-full w-6 h-6 flex items-center justify-center font-bold mr-3 mt-0.5 flex-shrink-0">3</div>
                    <div>
                        The bot aims to close the deal and take profit when it reaches <strong class="text-green-700"><span x-text="form.risk_management.target_profit"></span>%</strong> above the 
                        <span class="italic text-gray-600" x-text="form.risk_management.tp_type === 'average_price' ? 'Average Entry Price (incorporating all DCA buys)' : 'Initial Base Order Price'"></span>.
                        <p x-show="form.risk_management.trailing_tp_enabled" class="mt-1 text-green-700 text-xs font-semibold">
                            ↳ Trailing Take Profit is ON: Instead of selling immediately, it will let profits run and only sell when the price drops back by <span x-text="form.risk_management.trailing_tp_deviation"></span>% from the peak.
                        </p>
                    </div>
                </div>

                <div class="flex items-start" x-show="form.risk_management.cut_loss_enabled || form.risk_management.sell_conditions.length > 0">
                    <div class="bg-red-200 text-red-800 rounded-full w-6 h-6 flex items-center justify-center font-bold mr-3 mt-0.5 flex-shrink-0">4</div>
                    <div>
                        <strong class="text-red-700">Risk Mitigation Active:</strong>
                        <ul class="list-disc ml-5 mt-1 text-gray-600 space-y-1">
                            <li x-show="form.risk_management.cut_loss_enabled">It will trigger a hard Stop Loss if the price plummets by <strong class="text-red-600"><span x-text="form.risk_management.cut_loss_percent"></span>%</strong>.</li>
                            <li x-show="form.risk_management.sell_conditions.length > 0">
                                It will Panic Sell / Force Close if ANY of these emergency signals are received:
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <template x-for="(cond, index) in form.risk_management.sell_conditions" :key="index">
                                        <span class="bg-white border border-red-300 text-red-700 px-2 py-1 rounded-md text-xs font-mono shadow-sm" x-text="formatConditionText(cond)"></span>
                                    </template>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-8 border-t pt-6">
                    <h3 class="text-indigo-900 font-bold mb-4 flex items-center">
                        📊 Capital & Price Drop Breakdown
                        <span x-show="form.general.capital === 'AUTO'" class="ml-2 text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded uppercase">Example based on $1000</span>
                    </h3>
                    <div class="overflow-hidden rounded-lg border border-gray-200 shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-indigo-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-900 uppercase">Step</th>
                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-900 uppercase">Amount (USDT)</th>
                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-900 uppercase">Total Drop (%)</th>
                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-900 uppercase">Pair Price ($)</th>
                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-indigo-900 uppercase">Avg Price ($)</th>
                                    <th class="px-4 py-2 text-left text-[10px] font-bold text-green-700 uppercase" title="How much % the price must rise from this level to take profit">Rise to TP (%)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100 text-xs text-gray-700">
                                <template x-for="step in calculateStrategySteps()" :key="step.label">
                                    <tr :class="step.label.includes('DCA') ? 'bg-amber-50/30' : 'bg-green-50/30'">
                                        <td class="px-4 py-2 font-bold text-gray-800" x-text="step.label"></td>
                                        <td class="px-4 py-2 font-mono">$<span x-text="step.amount"></span></td>
                                        <td class="px-4 py-2 font-bold text-red-600" x-text="step.label === 'Base Order' ? 'ENTRY' : '-' + step.cumulative + '%'"></td>
                                        <td class="px-4 py-2 font-mono" x-text="step.price"></td>
                                        <td class="px-4 py-2 font-mono text-indigo-700" x-text="step.avgPrice"></td>
                                        <td class="px-4 py-2 font-bold text-green-600">
                                            +<span x-text="step.riseToTp"></span>%
                                            <span x-show="form.risk_management.min_guard_enabled && parseFloat(step.riseToMin) > 0" class="block text-[9px] text-gray-500 font-normal mt-0.5">Min: +<span x-text="step.riseToMin"></span>%</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-[10px] text-gray-500 italic">
                        * Note: If you are using 'Smart Averaging', the bot will wait for a technical signal after the 'Total Drop %' is reached before buying.
                    </p>
                </div>
            </div>
        </div>
        <div class="mt-8 mb-20 bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto shadow-inner">
            <h3 class="text-white mb-2 font-bold">JSON PAYLOAD PREVIEW (Latar Belakang):</h3>
            <pre x-text="JSON.stringify(form, null, 2)"></pre>
        </div>
        </form>

        <!-- Help Modal Overlay -->
        <div x-show="helpModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm transition-opacity" x-cloak x-transition.opacity>
            <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-sm w-full mx-4 relative transform transition-all" @click.away="helpModal = false" x-show="helpModal" x-transition.scale.origin.bottom>
                <button @click="helpModal = false" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
                <h3 class="text-xl font-extrabold text-indigo-900 mb-3 flex items-center">
                    <span class="mr-2 text-2xl">💡</span> <span x-text="helpTitle"></span>
                </h3>
                <p class="text-sm text-gray-600 mb-6 leading-relaxed" x-html="helpText"></p>
                <a :href="helpLink" target="_blank" class="flex items-center justify-center w-full bg-indigo-50 text-indigo-700 hover:bg-indigo-100 hover:text-indigo-800 font-bold py-3 px-4 rounded-xl transition-all shadow-sm">
                    📖 Read full details in FAQ
                </a>
            </div>
        </div>

        <!-- Backtest Modal -->
        <div x-show="showBacktestModal" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-60 backdrop-blur-sm overflow-y-auto">
            <div @click.outside="showBacktestModal = false" class="bg-slate-50 w-full max-w-6xl rounded-2xl shadow-2xl p-6 m-4 relative flex flex-col max-h-[90vh]">
                <div class="flex justify-between items-center border-b pb-4 mb-4">
                    <h2 class="text-2xl font-bold text-slate-800 flex items-center">
                        <span class="bg-indigo-100 text-indigo-700 p-2 rounded-lg mr-3">🚀</span>
                        Strategy Backtester
                    </h2>
                    <button @click="showBacktestModal = false" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <div class="flex-1 overflow-y-auto pr-2">
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                        <!-- Controls Sidebar -->
                        <div class="lg:col-span-4 space-y-6">
                            <div class="bg-white rounded-xl p-5 shadow-sm border border-slate-200">
                                <h3 class="font-bold text-slate-800 mb-4 text-sm uppercase tracking-wider">Simulation Parameters</h3>
                                
                                <div class="space-y-4">
                                    <!-- Pair Selection for Composite/Dynamic Bots -->
                                    <template x-if="form.general.pair_strategy !== 'single'">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Test Pair</label>
                                            <select x-model="backtestPair" @change="checkBacktestStatus" class="w-full rounded-lg border-slate-200 bg-slate-50 text-slate-700 text-sm">
                                                <option value="">Select a pair to test...</option>
                                                <template x-if="getCompositePairs().length > 0">
                                                    <template x-for="p in getCompositePairs()" :key="p">
                                                        <option :value="p" x-text="p"></option>
                                                    </template>
                                                </template>
                                                <template x-if="getCompositePairs().length === 0">
                                                    <template x-for="p in window.availablePairs" :key="p">
                                                        <option :value="p" x-text="p"></option>
                                                    </template>
                                                </template>
                                            </select>
                                        </div>
                                    </template>

                                    <!-- Status Alert -->
                                    <template x-if="backtestDataStatus && backtestPair">
                                        <div :class="backtestDataStatus.has_data ? 'bg-emerald-50 border-emerald-100 text-emerald-800' : 'bg-amber-50 border-amber-100 text-amber-800'" class="p-3 rounded-lg border text-sm">
                                            <div class="flex items-start">
                                                <span class="mr-2" x-text="backtestDataStatus.has_data ? '✅' : '⚠️'"></span>
                                                <div>
                                                    <p class="font-bold" x-text="backtestDataStatus.has_data ? 'Data Ready' : 'Data Missing'"></p>
                                                    <p class="text-[10px] mt-1 opacity-90" x-show="backtestDataStatus.has_data">
                                                        Range: <span x-text="backtestDataStatus.earliest_date"></span> - <span x-text="backtestDataStatus.latest_date"></span>
                                                    </p>
                                                    <button @click="importBacktestData" :disabled="backtestIsImporting" class="mt-2 text-xs font-bold underline flex items-center hover:text-indigo-600 transition-colors">
                                                        <span x-text="backtestIsImporting ? '📥 Importing Data (Please wait...)' : '📥 Sync Data (' + backtestFromDate + ' to ' + backtestToDate + ')'"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">From</label>
                                            <input type="date" x-model="backtestFromDate" class="w-full rounded-lg border-slate-200 bg-slate-50 text-slate-700 text-xs">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">To</label>
                                            <input type="date" x-model="backtestToDate" class="w-full rounded-lg border-slate-200 bg-slate-50 text-slate-700 text-xs">
                                        </div>
                                    </div>
                                    
                                    <button @click="runBacktest" :disabled="backtestIsRunning || !backtestDataStatus?.has_data || !backtestPair" class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold py-3 rounded-xl shadow-md flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all mt-4">
                                        <template x-if="!backtestIsRunning">
                                            <span>▶ Run Simulation</span>
                                        </template>
                                        <template x-if="backtestIsRunning">
                                            <span class="flex items-center">
                                                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                                Simulating...
                                            </span>
                                        </template>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Results Area -->
                        <div class="lg:col-span-8">
                            <!-- Empty State -->
                            <template x-if="!backtestResults && !backtestIsRunning">
                                <div class="h-full bg-white rounded-xl p-12 flex flex-col items-center justify-center text-center opacity-80 border border-slate-200 border-dashed">
                                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mb-4">
                                        <span class="text-3xl">📊</span>
                                    </div>
                                    <h3 class="text-lg font-bold text-slate-800">No Data Yet</h3>
                                    <p class="text-slate-500 mt-2 max-w-sm text-sm">Select a pair and run the simulation to see how your exact bot settings perform against historical 1-minute data.</p>
                                </div>
                            </template>

                            <!-- Loading State -->
                            <template x-if="backtestIsRunning">
                                <div class="h-full bg-white rounded-xl p-12 flex flex-col items-center justify-center text-center border border-slate-200">
                                    <div class="relative w-16 h-16 mb-6">
                                        <div class="absolute inset-0 border-4 border-indigo-100 rounded-full"></div>
                                        <div class="absolute inset-0 border-4 border-t-indigo-600 rounded-full animate-spin"></div>
                                    </div>
                                    <h3 class="text-lg font-bold text-slate-800">Processing Candlesticks...</h3>
                                    <p class="text-slate-500 mt-2 text-sm">Testing your strategy logic tick-by-tick.</p>
                                </div>
                            </template>

                            <!-- Results Board -->
                            <template x-if="backtestResults">
                                <div class="space-y-4 animate-fade-in">
                                    <!-- Stats Grid -->
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-indigo-500 border border-slate-100">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase mb-1">Total PNL</p>
                                            <p :class="backtestResults.stats.total_pnl_usdt >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="text-xl font-black">
                                                <span x-text="backtestResults.stats.total_pnl_usdt >= 0 ? '+' : ''"></span><span x-text="backtestResults.stats.total_pnl_usdt.toFixed(2)"></span> USDT
                                            </p>
                                            <p class="text-[10px] mt-1 text-slate-500" x-text="'(' + backtestResults.stats.total_pnl_pct + '%)'"></p>
                                        </div>
                                        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase mb-1">Win Rate</p>
                                            <p class="text-xl font-black text-slate-800" x-text="backtestResults.stats.win_rate + '%'"></p>
                                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2">
                                                <div class="bg-indigo-500 h-1.5 rounded-full" :style="'width: ' + backtestResults.stats.win_rate + '%'"></div>
                                            </div>
                                        </div>
                                        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase mb-1">Trades</p>
                                            <p class="text-xl font-black text-slate-800" x-text="backtestResults.stats.total_trades"></p>
                                            <p class="text-[10px] mt-1 text-slate-400" x-text="backtestResults.stats.winning_trades + ' Win / ' + backtestResults.stats.losing_trades + ' Loss'"></p>
                                        </div>
                                        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase mb-1">Max Drawdown</p>
                                            <p class="text-xl font-black text-rose-500" x-text="backtestResults.stats.max_drawdown_pct + '%'"></p>
                                            <p class="text-[10px] mt-1 text-slate-400">Capital Protection</p>
                                        </div>
                                    </div>

                                    <!-- Chart -->
                                    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
                                        <div class="flex justify-between items-center mb-4">
                                            <h3 class="font-bold text-slate-800 text-sm">Hourly Price & Trade Markers</h3>
                                            <div class="flex gap-3">
                                                <span class="flex items-center text-[10px] text-slate-400"><span class="w-2 h-2 rounded-full bg-emerald-500 mr-1"></span> Base</span>
                                                <span class="flex items-center text-[10px] text-slate-400"><span class="w-2 h-2 rounded-full bg-amber-500 mr-1"></span> DCA</span>
                                                <span class="flex items-center text-[10px] text-slate-400"><span class="w-2 h-2 rounded-full bg-rose-500 mr-1"></span> Exit</span>
                                            </div>
                                        </div>
                                        <div class="h-64">
                                            <canvas id="backtestPnlChart"></canvas>
                                        </div>
                                    </div>

                                    <!-- Trade Journal -->
                                    <div class="bg-white rounded-xl border border-slate-100 shadow-sm overflow-hidden">
                                        <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                                            <h3 class="font-bold text-slate-800 text-sm">Trade Journal (Detailed Events)</h3>
                                            <span class="text-[10px] font-bold text-slate-500 px-2 py-1 bg-white rounded border" x-text="backtestResults.trades.length + ' Trades'"></span>
                                        </div>
                                        <div class="max-h-80 overflow-y-auto">
                                            <table class="w-full text-left text-xs">
                                                <thead class="bg-white sticky top-0 shadow-sm z-10">
                                                    <tr>
                                                        <th class="px-4 py-2 font-bold text-slate-500">Deal Execution</th>
                                                        <th class="px-4 py-2 font-bold text-slate-500">Timeline & Signals</th>
                                                        <th class="px-4 py-2 font-bold text-slate-500 text-right">Result</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-50">
                                                    <template x-for="trade in backtestResults.trades" :key="trade.entry_time">
                                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                                            <td class="px-4 py-4 align-top">
                                                                <div class="font-bold text-slate-700" x-text="trade.entry_time"></div>
                                                                <div class="text-[10px] text-slate-400 mt-1">
                                                                    Avg Entry: <span class="text-slate-600" x-text="'$' + trade.avg_entry_price.toFixed(2)"></span>
                                                                </div>
                                                                <div class="text-[10px] text-slate-400">
                                                                    Exit: <span class="text-slate-600" x-text="trade.exit_time"></span>
                                                                </div>
                                                                <div class="mt-2 flex gap-1">
                                                                     <span :class="trade.close_reason === 'TAKE_PROFIT' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'" class="px-1.5 py-0.5 rounded text-[9px] font-bold" x-text="trade.close_reason"></span>
                                                                     <span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded text-[9px] font-bold" x-text="trade.dca_steps_used + ' DCA'"></span>
                                                                </div>
                                                            </td>
                                                            <td class="px-4 py-4 align-top">
                                                                <div class="space-y-2">
                                                                    <template x-for="(evt, idx) in trade.events" :key="idx">
                                                                        <div class="flex items-start gap-2 group">
                                                                            <div class="mt-1 w-1.5 h-1.5 rounded-full" :class="evt.type === 'BUY' ? (evt.label === 'Base Order' ? 'bg-emerald-500' : 'bg-amber-500') : 'bg-rose-500'"></div>
                                                                            <div>
                                                                                <div class="flex items-center gap-2">
                                                                                    <span class="font-bold text-slate-600 text-[10px]" x-text="evt.label"></span>
                                                                                    <span class="text-slate-400 text-[9px]" x-text="'@ $' + evt.price.toFixed(2)"></span>
                                                                                </div>
                                                                                <div class="text-slate-500 text-[9px] italic" x-text="'Trigger: ' + (evt.reason || 'Manual/System')"></div>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </td>
                                                            <td class="px-4 py-4 align-top text-right">
                                                                <div :class="trade.pnl_usdt >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="text-sm font-black">
                                                                    <span x-text="(trade.pnl_usdt >= 0 ? '+$' : '-$') + Math.abs(trade.pnl_usdt).toFixed(2)"></span>
                                                                </div>
                                                                <div :class="trade.pnl_usdt >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'" class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold mt-1" x-text="trade.pnl_pct.toFixed(2) + '%'"></div>
                                                                <div class="text-[9px] text-slate-400 mt-2" x-text="'Total Spent: $' + trade.total_spent.toFixed(2)"></div>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('botEditor', () => ({
                // Feature gating state
                featureStatuses: [],
                verifyingFeatures: false,
                async verifyFeatures() {
                    this.verifyingFeatures = true;
                    try {
                        const headers = { 'X-CSRF-Token': window.csrfToken };
                        const res = await fetch(window.API_BASE + '/settings/features/verify', { method: 'POST', headers }).then(r => r.json());
                        if (res.ok) {
                            window.featureStatuses = res.data.features || [];
                            this.featureStatuses = window.featureStatuses;
                            const disabled = window.featureStatuses.filter(f => f.status !== 'enabled');
                            if (disabled.length === 0) {
                                alert('All integrations verified and enabled. ✅');
                            } else {
                                alert('Verification complete. Still disabled:\n\n' + disabled.map(f => (f.required ? '❌ ' : '⚠️ ') + f.label + ': ' + f.reason).join('\n'));
                            }
                        } else {
                            alert('Verification failed: ' + (res.error || 'unknown error'));
                        }
                    } catch (e) {
                        alert('Verification failed. Check the console.');
                    } finally {
                        this.verifyingFeatures = false;
                    }
                },

                // Help Modal State
                helpModal: false,
                helpTitle: '',
                helpText: '',
                helpLink: '',

                // Backtest Modal State
                showBacktestModal: false,
                backtestIsRunning: false,
                backtestIsImporting: false,
                backtestDataStatus: null,
                backtestResults: null,
                backtestChart: null,
                backtestPair: '',
                backtestFromDate: (() => { const d = new Date(); d.setDate(d.getDate() - 30); return d.toISOString().split('T')[0]; })(),
                backtestToDate: (() => { const d = new Date(); d.setDate(d.getDate() - 1); return d.toISOString().split('T')[0]; })(),
                
                showHelp(topic) {
                    if (topic === 'exchange') {
                        this.helpTitle = 'Exchange Selection';
                        this.helpText = 'Choose the live exchange for this bot. <br><br><span class="text-amber-600 font-semibold">Demo Mode Notice:</span> If Demo Mode is ON in Global Settings, this selection is safely bypassed and Binance Testnet is used automatically.';
                        this.helpLink = 'faq.php#exchange';
                    } else if (topic === 'capital') {
                        this.helpTitle = 'Allocated Capital (USDT)';
                        this.helpText = '<strong class="text-indigo-700">AUTO (Recommended):</strong> Dynamically calculates and allocates a fair share of your wallet balance among waiting bots at the exact moment a trade starts. Extremely safe and capital efficient.<br><br><strong class="text-gray-700">Custom:</strong> Enforces a strict, unchangeable maximum budget limit for this specific bot.';
                        this.helpLink = 'faq.php#capital';
                    } else if (topic === 'pair_strategy') {
                        this.helpTitle = 'Coin Pair Strategy';
                        this.helpText = 'Allows the bot to hunt across multiple coins dynamically.<br><br>Once a coin meets the buy conditions, the bot locks onto it and focuses entirely on its DCA until it sells. The radar then turns back on to hunt again.';
                        this.helpLink = 'faq.php#pair_strategy';
                    } else if (topic === 'max_deals') {
                        this.helpTitle = 'Max Active Deals';
                        this.helpText = 'The maximum number of simultaneous trades this bot can handle.<br><br>Capital is automatically split equally among these deals. E.g., $1000 with 2 deals = $500 per coin.';
                        this.helpLink = 'faq.php#max_deals';
                    } else if (topic === 'order_type') {
                        this.helpTitle = 'Start Order Type';
                        this.helpText = 'Market Order executes instantly and guarantees entry. Limit Order saves fees but risks the order remaining unfilled if the price moves quickly.';
                        this.helpLink = 'faq.php#order-type';
                    } else if (topic === 'buy_cooldown') {
                        this.helpTitle = 'Buy Cooldown';
                        this.helpText = 'Prevents the bot from immediately re-buying the same coin after it was just sold. Protects against sudden "whipsaw" market crashes.';
                        this.helpLink = 'faq.php#buy-cooldown';
                    } else if (topic === 'trailing_buy') {
                        this.helpTitle = 'Trailing Buy';
                        this.helpText = 'Instead of buying instantly when a signal triggers, the bot waits for the price to hit the absolute bottom and bounce back up by X% before buying.';
                        this.helpLink = 'faq.php#trailing-buy';
                    } else if (topic === 'start_conditions') {
                        this.helpTitle = 'Trade Start Conditions';
                        this.helpText = 'The triggers that tell the bot when to execute a Base Order. You can mix multiple conditions (AND logic) to create powerful strategies like RSI + AI sentiment + Bollinger Bands.';
                        this.helpLink = 'faq.php#start-conditions';
                    } else if (topic === 'dca_max_steps') {
                        this.helpTitle = 'Max Safety Trades Count';
                        this.helpText = 'The maximum number of safety orders (DCA steps) the bot is allowed to execute for one active deal.';
                        this.helpLink = 'faq.php#dca-max-steps';
                    } else if (topic === 'dca_price_drop') {
                        this.helpTitle = 'Price Drop Trigger (%)';
                        this.helpText = 'The percentage the price must drop from your Average Entry Price before the bot executes the safety order.';
                        this.helpLink = 'faq.php#dca-price-drop';
                    } else if (topic === 'dca_volume_scale') {
                        this.helpTitle = 'Volume Scale (Capital Multiplier)';
                        this.helpText = 'Multiplier for the trade size. Buys larger amounts at cheaper prices to rapidly pull your average price down.';
                        this.helpLink = 'faq.php#dca-volume-scale';
                    } else if (topic === 'dca_step_scale') {
                        this.helpTitle = 'Step Scale (Distance Multiplier)';
                        this.helpText = 'Multiplier for the price drop distance. Widens your safety net to prevent spending all ammo too quickly in a flash crash.';
                        this.helpLink = 'faq.php#dca-step-scale';
                    } else if (topic === 'dca_placed_exchange') {
                        this.helpTitle = 'Place Orders on Exchange?';
                        this.helpText = 'If Yes, places Limit Orders in advance on Binance. If No, tracks live and uses Market Buy (required for Trailing or Custom Conditions).';
                        this.helpLink = 'faq.php#dca-placed-exchange';
                    } else if (topic === 'dca_custom_conditions') {
                        this.helpTitle = 'Custom DCA Conditions (Smart Averaging)';
                        this.helpText = 'Requires a custom signal (like RSI or QFL) to confirm the price has bottomed out before executing the DCA step.';
                        this.helpLink = 'faq.php#dca-custom-conditions';
                    } else if (topic === 'dca_trailing') {
                        this.helpTitle = 'Trailing for Safety Orders';
                        this.helpText = 'Tracks a crashing price downwards and waits for it to hit the bottom and bounce back by your Deviation % before executing.';
                        this.helpLink = 'faq.php#dca-trailing';
                    } else if (topic === 'tp_type') {
                        this.helpTitle = 'Take Profit Type';
                        this.helpText = '% from Average: Exit target dynamically drops lower every time a DCA happens. % from Base Order: Forces the exit target to remain tied to your original entry price.';
                        this.helpLink = 'faq.php#tp-type';
                    } else if (topic === 'tp_target') {
                        this.helpTitle = 'Target Profit (%)';
                        this.helpText = 'The percentage of profit the bot aims to achieve before selling the entire position to close the deal.';
                        this.helpLink = 'faq.php#tp-target';
                    } else if (topic === 'tp_trailing') {
                        this.helpTitle = 'Trailing Take Profit';
                        this.helpText = 'Instead of selling immediately at the target, the bot tracks the price upwards to catch the peak, and only sells when the price drops by your deviation %.';
                        this.helpLink = 'faq.php#tp-trailing';
                    } else if (topic === 'tp_cut_loss') {
                        this.helpTitle = 'Stop Loss / Cut Loss';
                        this.helpText = 'The emergency exit. Sells the entire position if the deal goes into negative PNL beyond this limit. Includes Trailing SL to protect floating profits.';
                        this.helpLink = 'faq.php#tp-cut-loss';
                    } else if (topic === 'tp_min_guard') {
                        this.helpTitle = 'Minimum Profit Guard';
                        this.helpText = 'A protective shield for Custom Sell conditions. Prevents the bot from selling based on an indicator if the trade has not achieved this minimum profit, unless a timeout is reached.';
                        this.helpLink = 'faq.php#tp-min-guard';
                    } else if (topic === 'tp_partial_sell') {
                        this.helpTitle = 'Partial Sell (Scaling Out)';
                        this.helpText = 'Sell fractional amounts of your position at progressive profit milestones instead of dumping everything at once.';
                        this.helpLink = 'faq.php#tp-partial-sell';
                    } else if (topic === 'tp_custom_sell') {
                        this.helpTitle = 'Custom Sell Conditions';
                        this.helpText = 'Force the bot to sell using external technical indicators (like RSI or News AI) instead of waiting for a fixed percentage. Operates on Logical OR.';
                        this.helpLink = 'faq.php#tp-custom-sell';
                    }
                    this.helpModal = true;
                },

                // 1. PULL GLOBAL DATA INTO REACTIVE ALPINE STATE
                availableExchanges: window.availableExchanges || [],
                
                // If the initial window has a form, keep it
                form: window.initialBotData || {
                    is_enabled: false,
                    general: { 
                        name: '', exchange: '', pair_strategy: 'single', 
                        custom_pairs: 'BTC/USDT', blacklist: '', is_custom_capital: false, capital: 'AUTO', max_active_deals: 1 
                    },
                    base_order: { 
                        order_type: 'market', cooldown_seconds: 7200,
                        trailing_enabled: false, trailing_deviation: 0.5,
                        conditions: [ { type: 'start_asap', value: '' } ]
                    },
                    dca: { 
                        max_steps: 4, price_drop_trigger: 5.0, volume_scale: 1.5, step_scale: 1.2, placed_on_exchange: 'no',
                        custom_conditions_enabled: false,
                        conditions: [ { type: 'tv_webhook', value: 'DCA_BUY' } ],
                        trailing_enabled: false, trailing_deviation: 0.5
                    },
                    risk_management: {
                        tp_type: 'average_price', target_profit: 2.0,
                        trailing_tp_enabled: false, trailing_tp_deviation: 0.2,
                        cut_loss_enabled: false, cut_loss_percent: 5.0, trailing_sl_enabled: false,
                        min_guard_enabled: false, min_guard_percent: 1.0, min_guard_timeout: 48,
                        partial_sell_enabled: false, partial_targets: '',
                        sell_conditions: [] 
                    },
                    runtime_state: null
                },

                isLockedActive() {
                    return this.form.runtime_state && this.form.runtime_state.status !== 'IDLE';
                },
                isLockedDca() {
                    return this.isLockedActive() && (this.form.runtime_state.dca_current_step > 0);
                },

                initTomSelect(strategy) {
                    const selectEl = this.$refs.customPairsSelect;
                    if (!selectEl) return;
                    
                    if (this.tsInstance) {
                        this.tsInstance.destroy();
                    }
                    
                    // If switching from custom list (many) to single, truncate the array data to just 1
                    if (strategy === 'single') {
                        let currentItems = this.form.general.custom_pairs ? this.form.general.custom_pairs.split(',').map(s => s.trim()) : [];
                        if (currentItems.length > 1) {
                            this.form.general.custom_pairs = currentItems[0];
                        }
                    }

                    this.tsInstance = new TomSelect(selectEl, {
                        plugins: ['remove_button'],
                        create: true,
                        maxItems: strategy === 'single' ? 1 : null,
                        placeholder: 'Select or type pairs (e.g. BTC/USDT)...',
                        options: (window.availablePairs || []).map(p => ({value: p, text: p})),
                        items: this.form.general.custom_pairs ? this.form.general.custom_pairs.split(',').map(s => s.trim()).filter(i => i) : [],
                        onChange: (values) => {
                            this.form.general.custom_pairs = Array.isArray(values) ? values.join(', ') : values;
                        }
                    });

                    if (this.isLockedActive()) {
                        this.tsInstance.disable();
                    }
                },

                async init() {
                    // Phase 1: wait for the v1 API bootstrap before building the UI.
                    await pageDataReady;
                    this.form = window.initialBotData || this.form;
                    this.availableExchanges = window.availableExchanges || [];
                    this.featureStatuses = window.featureStatuses || [];
                    this.$nextTick(() => {
                        this.initTomSelect(this.form.general.pair_strategy);

                        // Bug Fix: Watch strategy changes and rebuild TomSelect
                        this.$watch('form.general.pair_strategy', value => {
                            this.initTomSelect(value);
                        });

                        // Auto-open backtest modal if requested via URL
                        const urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.get('backtest') === '1') {
                            setTimeout(() => {
                                this.openBacktestModal();
                            }, 500);
                        }
                    });
                },

                addCondition(section) {
                    if(section === 'risk_management') {
                        this.form.risk_management.sell_conditions.push({ type: 'tv_webhook', value: '', timeframe: '1h', period: 14, stddev: 2.0 });
                    } else {
                        this.form[section].conditions.push({ type: 'tv_webhook', value: '', timeframe: '1h', period: 14, stddev: 2.0 });
                    }
                },

                removeCondition(section, index) {
                    if(section === 'risk_management') {
                        this.form.risk_management.sell_conditions.splice(index, 1);
                    } else {
                        this.form[section].conditions.splice(index, 1);
                    }
                },

                // FUNCTION: Generate a fixed list of options for the condition.value dropdown (3Commas reference)
                getConditionValueOptions(type, context) {
                    // context: 'buy' or 'sell'
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
                    // rsi_14 is the same as rsi
                    opts['rsi_14'] = opts['rsi'];
                    
                    let typeOpts = opts[type];
                    if (!typeOpts) return [{value: '', label: '— Select Signal —'}];
                    return typeOpts[context] || [{value: '', label: '— Select Signal —'}];
                },

                // NEW FUNCTION: Translate codes into English
                formatConditionText(cond) {
                    const labels = {
                        'start_asap': 'Start ASAP',
                        'tv_webhook': 'Webhook Signal',
                        'external_signal': 'External API',
                        'news_sentiment': 'News AI Sentiment',
                        'ai_market': 'AI Market (OHLCV)',
                        'rsi_14': 'RSI',
                        'rsi': 'RSI',
                        'bollinger': 'Bollinger Bands',
                        'qfl': 'QFL Strategy'
                    };
                    let labelName = labels[cond.type] || cond.type;
                    
                    if (['rsi', 'bollinger', 'qfl', 'rsi_14'].includes(cond.type)) {
                        let tf = cond.timeframe || '1h';
                        let extra = '';
                        if (cond.type === 'rsi' || cond.type === 'rsi_14') extra = `(${cond.period || 14})`;
                        if (cond.type === 'bollinger') extra = `(${cond.period || 20}, ${cond.stddev || 2.0})`;
                        labelName = `${labelName}${extra} [${tf}]`;
                    }
                    if (cond.type === 'ai_market') {
                        labelName = `${labelName} [${cond.timeframe || '1h'}]`;
                    }
                    
                    // If there is a value (e.g. < 30), combine it.
                    if (cond.value && cond.value.trim() !== '') {
                        // For the start_asap type, there is no need to display a value
                        if (cond.type === 'start_asap') return `🚀 Execute Immediately`;
                        return `${labelName} is [ ${cond.value} ]`;
                    }
                    
                    return cond.type === 'start_asap' ? `🚀 Execute Immediately` : labelName;
                },

                calculateMinCapital() {
                    let maxSteps = parseInt(this.form.dca.max_steps) || 0;
                    let volumeScale = parseFloat(this.form.dca.volume_scale) || 1.0;
                    let minBaseOrder = 10.5; // Exchange safety buffer
                    let totalCapitalRequired = 0;

                    for (let i = 0; i <= maxSteps; i++) {
                        totalCapitalRequired += minBaseOrder * Math.pow(volumeScale, i);
                    }

                    return totalCapitalRequired.toFixed(2);
                },

                calculateStrategySteps() {
                    let maxSteps = parseInt(this.form.dca.max_steps) || 0;
                    let volumeScale = parseFloat(this.form.dca.volume_scale) || 1.0;
                    let stepScale = parseFloat(this.form.dca.step_scale) || 1.0;
                    let priceDropTrigger = parseFloat(this.form.dca.price_drop_trigger) || 1.0;
                    let targetProfit = parseFloat(this.form.risk_management.target_profit) || 2.0;
                    let minProfit = parseFloat(this.form.risk_management.min_guard_percent) || 0.0;
                    let tpType = this.form.risk_management.tp_type;
                    
                    let allocatedCapital = parseFloat(this.form.general.capital);
                    if (isNaN(allocatedCapital)) {
                        allocatedCapital = 1000.00; // Placeholder example if AUTO
                    }
                    
                    let multiplierSum = 0;
                    for (let i = 0; i <= maxSteps; i++) {
                        multiplierSum += Math.pow(volumeScale, i);
                    }
                    
                    let boSize = allocatedCapital / multiplierSum;
                    
                    let steps = [];
                    let cumulativeDrop = 0;
                    let lastGap = priceDropTrigger;
                    
                    let dummyStartPrice = 100.00; // Simulation start price
                    let currentPrice = dummyStartPrice;
                    let totalCoins = 0;
                    let totalSpent = 0;
                    
                    for (let i = 0; i <= maxSteps; i++) {
                        let stepModal = boSize * Math.pow(volumeScale, i);
                        
                        if (i > 0) {
                            if (i > 1) {
                                lastGap = lastGap * stepScale;
                            }
                            cumulativeDrop += lastGap;
                            currentPrice = dummyStartPrice * (1 - (cumulativeDrop / 100));
                        }
                        
                        // Execute Buy
                        let coinsBought = stepModal / currentPrice;
                        totalCoins += coinsBought;
                        totalSpent += stepModal;
                        
                        let averagePrice = totalSpent / totalCoins;
                        
                        // Calculate Required Rise to Target Profit
                        let targetPrice = averagePrice * (1 + (targetProfit / 100));
                        if (tpType === 'base_order') {
                            // Convert base order % target to price (3Commas logic)
                            let dynamicTp = targetProfit * (boSize / totalSpent);
                            targetPrice = averagePrice * (1 + (dynamicTp / 100));
                        }
                        let requiredRiseTp = ((targetPrice - currentPrice) / currentPrice) * 100;
                        
                        // Calculate Required Rise to Min Profit Guard
                        let minTargetPrice = averagePrice * (1 + (minProfit / 100));
                        let requiredRiseMin = ((minTargetPrice - currentPrice) / currentPrice) * 100;
                        
                        steps.push({
                            label: i === 0 ? 'Base Order' : `DCA #${i}`,
                            amount: stepModal.toFixed(2),
                            drop: i === 0 ? '0.00' : lastGap.toFixed(2),
                            cumulative: i === 0 ? '0.00' : cumulativeDrop.toFixed(2),
                            price: currentPrice.toFixed(4),
                            avgPrice: averagePrice.toFixed(4),
                            riseToTp: requiredRiseTp.toFixed(2),
                            riseToMin: requiredRiseMin.toFixed(2)
                        });
                    }
                    return steps;
                },

                // DATABASE SAVE FUNCTION (AJAX/AXIOS)
                saveBot() {
                    const btn = document.getElementById('saveBtn');
                    const text = document.getElementById('btnText');
                    const status = document.getElementById('saveStatus');
                    
                    let payload = JSON.parse(JSON.stringify(this.form));
                    payload.general.min_required = this.calculateMinCapital();
                    payload.csrf_token = window.csrfToken;

                    if (!payload.general.is_custom_capital) {
                        payload.general.capital = 'AUTO';
                    }

                    // 1. Basic Validation
                    if (!payload.general.name || payload.general.capital === '') {
                        alert("Please fill in the Bot Name and the allocated Capital.");
                        return;
                    }

                    // 2. Exchange Selection Validation
                    if (!payload.general.exchange || payload.general.exchange === '') {
                        alert("Execution Error: Please select an Exchange first. If the list is empty, you need to set a Live API Key in the Settings menu.");
                        return;
                    }

                    // 3. Minimum Capital Validation (Martingale Formula)
                    if (payload.general.capital !== 'AUTO') {
                        const minNeeded = parseFloat(this.calculateMinCapital());
                        const currentAllocated = parseFloat(payload.general.capital);

                        if (currentAllocated < minNeeded) {
                            alert(`Risk Management Error: The allocated capital ($${currentAllocated}) is insufficient. The system requires a minimum of $${minNeeded} to support the base order of $10.50 and your DCA Martingale plan.`);
                            return;
                        }
                    }

                    // Loading UI
                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                    text.innerText = "⏳ Saving...";
                    status.innerText = "";
                    status.classList.remove('text-green-500', 'text-red-500');

                    // Phase 1: save through the v1 REST API.
                    const isUpdate = !!window.currentBotId;
                    const url = window.API_BASE + '/bots' + (isUpdate ? '/' + encodeURIComponent(window.currentBotId) : '');
                    const method = isUpdate ? 'PUT' : 'POST';

                    fetch(url, {
                        method: method,
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
                        body: JSON.stringify(payload)
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.ok) {
                                status.innerText = "Saved successfully!";
                                status.classList.add('text-green-500');

                                // If this is a new bot (Insert), redirect so it does not insert repeatedly
                                if (!isUpdate && data.data && data.data.bot_id) {
                                    window.currentBotId = data.data.bot_id;
                                    setTimeout(() => {
                                        window.location.href = 'settings.php?id=' + data.data.bot_id;
                                    }, 800);
                                }
                            } else {
                                status.innerText = "⚠️ " + (data.error || "Unknown error");
                                status.classList.add('text-red-500');
                                alert("Error: " + (data.error || "Unknown error"));
                            }
                        })
                        .catch(error => {
                            console.error(error);
                            status.innerText = "⚠️ An error occurred while saving.";
                            status.classList.add('text-red-500');
                            alert("Server Error. Please check the console.");
                        })
                        .finally(() => {
                            // Restore the button UI
                            btn.classList.remove('opacity-50', 'cursor-not-allowed');
                            text.innerText = "💾 Save Bot Configuration";
                        });
                },

                // ─────────────────────────────────────────────────────────────
                // BACKTESTING LOGIC
                // ─────────────────────────────────────────────────────────────
                getCompositePairs() {
                    if (!this.form.general.custom_pairs) return [];
                    return this.form.general.custom_pairs.split(',').map(s => s.trim()).filter(s => s !== '');
                },

                openBacktestModal() {
                    // Set default pair based on strategy
                    const pairs = this.getCompositePairs();
                    this.backtestPair = pairs.length > 0 ? pairs[0] : '';

                    // Remove slash for Binance format (BTC/USDT -> BTCUSDT)
                    if (this.backtestPair) {
                        this.backtestPair = this.backtestPair.replace('/', '');
                    }

                    this.showBacktestModal = true;
                    this.checkBacktestStatus();
                },

                async checkBacktestStatus() {
                    if (!this.backtestPair) return;
                    
                    try {
                        const formData = new FormData();
                        formData.append('action', 'status');
                        formData.append('symbol', this.backtestPair.replace('/', ''));
                        formData.append('csrf_token', window.csrfToken);

                        const response = await fetch('api/backtest_run.php', { method: 'POST', body: formData });
                        this.backtestDataStatus = await response.json();
                    } catch (e) {
                        console.error('Failed to check backtest status');
                    }
                },

                async importBacktestData() {
                    if (!this.backtestPair) return;
                    this.backtestIsImporting = true;
                    try {
                        const formData = new FormData();
                        formData.append('action', 'ingest');
                        formData.append('symbol', this.backtestPair.replace('/', ''));
                        formData.append('from_date', this.backtestFromDate);
                        formData.append('to_date', this.backtestToDate);
                        formData.append('csrf_token', window.csrfToken);

                        const response = await fetch('api/backtest_run.php', { method: 'POST', body: formData });
                        await response.json();
                        await this.checkBacktestStatus();
                    } catch (e) {
                        alert('Import failed. Please check your connection.');
                    } finally {
                        this.backtestIsImporting = false;
                    }
                },

                async runBacktest() {
                    if (!this.backtestPair) return;
                    this.backtestIsRunning = true;
                    this.backtestResults = null;

                    try {
                        // Gather ALL active form settings and transform to match Backtest API
                        const payload = {
                            action: 'run',
                            symbol: this.backtestPair.replace('/', ''),
                            from_date: this.backtestFromDate,
                            to_date: this.backtestToDate,
                            timeframe: '1h', // Default timeframe for now
                            all_conditions: this.form.base_order.conditions,
                            allocated_capital: this.form.general.capital === 'AUTO' ? 100 : parseFloat(this.form.general.capital),
                            target_profit: parseFloat(this.form.risk_management.target_profit),
                            tp_type: this.form.risk_management.tp_type,
                            cut_loss: this.form.risk_management.cut_loss_enabled ? parseFloat(this.form.risk_management.cut_loss_percent) : 0,
                            max_dca_steps: parseInt(this.form.dca.max_steps),
                            price_drop_trigger: parseFloat(this.form.dca.price_drop_trigger),
                            step_scale: parseFloat(this.form.dca.step_scale),
                            volume_scale: parseFloat(this.form.dca.volume_scale),
                            trailing_buy_deviation: this.form.base_order.trailing_enabled ? parseFloat(this.form.base_order.trailing_deviation) : 0,
                            trailing_sl_deviation: this.form.risk_management.trailing_tp_enabled ? parseFloat(this.form.risk_management.trailing_tp_deviation) : 0,
                            fee_rate: 0.001,
                            csrf_token: window.csrfToken
                        };

                        const response = await fetch('api/backtest_run.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        
                        const data = await response.json();
                        
                        if (data.error) {
                            alert(data.message || data.error);
                            return;
                        }

                        this.backtestResults = data;
                        
                        // Render Chart 
                        this.$nextTick(() => {
                            this.renderBacktestChart();
                        });

                    } catch (e) {
                        console.error(e);
                        alert('Simulation failed.');
                    } finally {
                        this.backtestIsRunning = false;
                    }
                },

                renderBacktestChart() {
                    if (this.backtestChart) this.backtestChart.destroy();
                    
                    const canvas = document.getElementById('backtestPnlChart');
                    if (!canvas) return;
                    
                    const ctx = canvas.getContext('2d');
                    
                    // Dataset 1: Price History
                    const priceData = this.backtestResults.price_history;
                    
                    // Extract Events for Markers
                    const buyMarkers = [];
                    const sellMarkers = [];
                    const dcaMarkers = [];
                    
                    this.backtestResults.trades.forEach(trade => {
                        trade.events.forEach(evt => {
                            const marker = { x: evt.time, y: evt.price, label: evt.label, reason: evt.reason };
                            if (evt.type === 'BUY') {
                                if (evt.label === 'Base Order') buyMarkers.push(marker);
                                else dcaMarkers.push(marker);
                            } else {
                                sellMarkers.push(marker);
                            }
                        });
                    });

                    this.backtestChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            datasets: [
                                {
                                    label: 'Price',
                                    data: priceData,
                                    borderColor: 'rgba(100, 116, 139, 0.5)',
                                    borderWidth: 1.5,
                                    pointRadius: 0,
                                    tension: 0.1,
                                    fill: false,
                                    parsing: { xAxisKey: 't', yAxisKey: 'y' }
                                },
                                {
                                    label: 'Base Order',
                                    data: buyMarkers,
                                    backgroundColor: '#10b981',
                                    borderColor: '#fff',
                                    borderWidth: 2,
                                    pointStyle: 'circle',
                                    pointRadius: 6,
                                    showLine: false,
                                    parsing: { xAxisKey: 'x', yAxisKey: 'y' }
                                },
                                {
                                    label: 'DCA',
                                    data: dcaMarkers,
                                    backgroundColor: '#f59e0b',
                                    borderColor: '#fff',
                                    borderWidth: 2,
                                    pointStyle: 'triangle',
                                    pointRadius: 6,
                                    showLine: false,
                                    parsing: { xAxisKey: 'x', yAxisKey: 'y' }
                                },
                                {
                                    label: 'Exit',
                                    data: sellMarkers,
                                    backgroundColor: '#ef4444',
                                    borderColor: '#fff',
                                    borderWidth: 2,
                                    pointStyle: 'rectRot',
                                    pointRadius: 7,
                                    showLine: false,
                                    parsing: { xAxisKey: 'x', yAxisKey: 'y' }
                                },
                                {
                                    label: 'Cumulative PNL (USDT)',
                                    data: (() => {
                                        let cumulative = 0;
                                        return this.backtestResults.trades.map(t => {
                                            cumulative += t.pnl_usdt;
                                            return { x: t.exit_time, y: cumulative };
                                        });
                                    })(),
                                    borderColor: 'rgba(99, 102, 241, 0.8)',
                                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                                    borderWidth: 2,
                                    pointRadius: 3,
                                    yAxisID: 'y1',
                                    fill: true,
                                    tension: 0.3
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            scales: {
                                x: {
                                    type: 'time',
                                    time: { unit: 'day', displayFormats: { day: 'MMM d' } },
                                    grid: { display: false }
                                },
                                y: {
                                    position: 'right',
                                    grid: { color: 'rgba(0,0,0,0.03)' },
                                    ticks: { font: { size: 10 } },
                                    title: { display: true, text: 'Price', font: { size: 10 } }
                                },
                                y1: {
                                    position: 'left',
                                    grid: { display: false },
                                    ticks: { font: { size: 10 } },
                                    title: { display: true, text: 'PNL (USDT)', font: { size: 10 } }
                                }
                            },
                            plugins: {
                                legend: { display: true, position: 'top', labels: { usePointStyle: true, boxWidth: 6, font: { size: 10 } } },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const item = context.raw;
                                            if (context.dataset.label === 'Price') return `Price: $${item.y}`;
                                            return `${item.label}: $${item.y} (${item.reason || ''})`;
                                        }
                                    }
                                }
                            }
                        },
                        plugins: [
                            {
                                id: 'tradeLabels',
                                afterDatasetsDraw(chart) {
                                    const {ctx, scales: {x, y}} = chart;
                                    chart.data.datasets.forEach((dataset) => {
                                        if (dataset.label === 'Price' || dataset.label === 'Cumulative PNL (USDT)') return;
                                        dataset.data.forEach((datapoint) => {
                                            const xPos = x.getPixelForValue(datapoint.x);
                                            const yPos = y.getPixelForValue(datapoint.y);
                                            ctx.save();
                                            ctx.font = 'bold 9px sans-serif';
                                            ctx.fillStyle = dataset.backgroundColor;
                                            ctx.textAlign = 'center';
                                            const text = datapoint.reason ? datapoint.reason.split(' & ')[0].substring(0, 15) : '';
                                            if (text) {
                                                ctx.fillText(text, xPos, yPos - 12);
                                            }
                                            ctx.restore();
                                        });
                                    });
                                }
                            }
                        ]
                    });
                },
                // NOTE: init() is defined above (async, API-first). Do not re-add a second init() here.
            }))
        })
    </script>
</body>
</html>