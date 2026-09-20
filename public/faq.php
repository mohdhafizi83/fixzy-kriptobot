<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Fixzy\Kriptobot\Security\SecurityHeaders;
use Fixzy\Kriptobot\Security\SessionGuard;

SecurityHeaders::send();
SessionGuard::requireWeb();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixzy Kriptobot - Frequently Asked Questions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
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
                <a href="index.php" class="hover:text-indigo-300 transition">Dashboard</a>
                <a href="agent.php" class="hover:text-indigo-300 transition">AI Agent</a>
                <a href="configure.php" class="hover:text-indigo-300 transition">Global Settings</a>
                <a href="faq.php" class="text-indigo-300 border-b-2 border-indigo-300 pb-1">FAQ's</a>
                <span class="text-indigo-400">|</span>
                <span class="text-gray-300">User: <?= htmlspecialchars($_SESSION['user_email']) ?></span>
                <a href="logout.php" class="text-red-400 hover:text-red-300 ml-2 transition">Logout</a>
            </div>
        </div>
        <div x-show="navOpen" @click.away="navOpen = false" class="md:hidden bg-indigo-950 px-4 py-2 space-y-1 pb-4" x-transition x-cloak>
            <a href="index.php" class="block py-2 hover:text-indigo-300">📊 Dashboard</a>
            <a href="agent.php" class="block py-2 hover:text-indigo-300">🤖 AI Agent</a>
            <a href="settings.php" class="block py-2 hover:text-indigo-300">🆕 New Bot</a>
            <a href="configure.php" class="block py-2 hover:text-indigo-300">⚙️ Settings</a>
            <a href="faq.php" class="block py-2 text-indigo-300 font-semibold">📚 FAQ</a>
            <hr class="border-indigo-800 my-1">
            <span class="block py-1 text-gray-400 text-xs"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
            <a href="logout.php" class="block py-2 text-red-400 font-semibold">🚪 Logout</a>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto p-3 md:p-6 mt-2 md:mt-4">
        <div class="flex items-center space-x-3 mb-2">
            <span class="text-4xl">📚</span>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Help Center & FAQ's</h1>
        </div>
        <p class="text-gray-500 mb-8 ml-12">Learn how to configure and maximize your Fixzy Kriptobot's potential.</p>

        <div class="space-y-12" x-data="{ active: null }">
            
            <!-- SECTION 1: GENERAL -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">⚙️</span> Bot Setting: General & Infrastructure
                    </h2>
                </div>

                <!-- Exchange FAQ -->
                <div id="exchange" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 1}">
                    <button @click="active = active === 1 ? null : 1" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-indigo-500 mr-3">🏦</span> Q: How does the Exchange selection work?</span>
                        <span x-show="active !== 1" class="text-gray-400">➕</span>
                        <span x-show="active === 1" class="text-indigo-500">➖</span>
                    </button>
                    <div x-show="active === 1" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The Exchange dropdown allows you to choose which cryptocurrency exchange this specific bot will trade on.</p>
                        <ul class="space-y-3">
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2 mt-0.5">✅</span>
                                <div>
                                    <strong class="text-gray-800">Live Mode:</strong> It strictly lists exchanges where you have successfully bound a valid API Key in the Global Settings. If the dropdown is empty, you must configure your API keys first.
                                </div>
                            </li>
                            <li class="flex items-start">
                                <span class="text-amber-500 mr-2 mt-0.5">⚠️</span>
                                <div>
                                    <strong class="text-gray-800">Demo Mode:</strong> If you activate "Demo Mode" in the Global Settings, the bot will <em>bypass</em> your selection here and force all trades into the Binance Testnet environment for safe paper-trading.
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Allocated Capital FAQ -->
                <div id="capital" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 2}">
                    <button @click="active = active === 2 ? null : 2" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-500 mr-3">💵</span> Q: What is Allocated Capital (USDT) and how does AUTO work?</span>
                        <span x-show="active !== 2" class="text-gray-400">➕</span>
                        <span x-show="active === 2" class="text-green-500">➖</span>
                    </button>
                    <div x-show="active === 2" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Allocated Capital is the maximum amount of USDT this bot is allowed to spend across its entire Base Order and DCA cycle.</p>
                        
                        <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4 mb-4">
                            <h3 class="font-bold text-indigo-900 text-base mb-2 flex items-center">✨ 1. AUTO (Dynamic Fair Allocation) - Institutional Grade</h3>
                            <p class="text-indigo-800">When left as "AUTO", the bot calculates its fair share only when a trading signal is met. Unlike simple bots, Fixzy Kriptobot uses a <strong>True Free Balance</strong> logic:<br><br>
                            <code class="bg-indigo-100 px-2 py-1 rounded text-indigo-900 font-mono font-bold">Total Capital = (90% of [Live Wallet - Reserved DCA Funds]) ÷ (Number of Truly IDLE Bots)</code><br><br>
                            <strong>Why this matters:</strong> This ensures that bots already in a trade have their DCA funds "locked" and protected. New bots will never "steal" the budget needed for existing safety orders, preventing <em>Insufficient Funds</em> errors during a market crash.</p>
                        </div>
                        
                        <div class="bg-emerald-50 border border-emerald-100 rounded-lg p-4 mb-4">
                            <h3 class="font-bold text-emerald-900 text-base mb-2 flex items-center">🛡️ No DCA Starvation Logic</h3>
                            <p class="text-emerald-800 italic">"I want the total capital to be used for the entire base order and DCA process."</p>
                            <p class="text-emerald-800 mt-2">The bot uses a <strong>Geometric Series formula</strong> to calculate your Base Order. It ensures that: <br>
                            <span class="font-bold">Base Order + All Safety Orders = Your Total Allocated Capital.</span><br>
                            This guarantees the bot always has enough fuel to complete its entire DCA strategy without running out of money mid-way.</p>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <h3 class="font-bold text-gray-800 text-base mb-2 flex items-center">🔒 2. Custom Allocated Capital</h3>
                            <p>If you check the Custom box, you can enforce a strict, hardcoded USDT limit for this bot (e.g., $150). The bot will use the same mathematical precision to split this amount between Base Order and Safety Orders.</p>
                        </div>
                    </div>
                </div>

                <!-- Centralized Data Hub FAQ -->
                <div id="data-hub" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 99}">
                    <button @click="active = active === 99 ? null : 99" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-blue-500 mr-3">⚡</span> Q: What is the APCu Centralized Data Hub?</span>
                        <span x-show="active !== 99" class="text-gray-400">➕</span>
                        <span x-show="active === 99" class="text-blue-500">➖</span>
                    </button>
                    <div x-show="active === 99" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The Centralized Data Hub is a performance booster that allows Fixzy Kriptobot to manage hundreds of active bots without hitting Exchange API Rate Limits (Error 429).</p>
                        <ul class="space-y-3">
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2 mt-0.5">🚀</span>
                                <div>
                                    <strong class="text-gray-800">How it works:</strong> Instead of each bot requesting prices from Binance individually, the daemon fetches <em>all</em> market prices in one single Batch Request. This data is instantly saved into your server's RAM via <strong>APCu</strong>.
                                </div>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2 mt-0.5">⏱️</span>
                                <div>
                                    <strong class="text-gray-800">Speed:</strong> Bots now read prices directly from RAM (taking less than 1 millisecond). This makes the bot loops incredibly fast, allowing them to react to price drops instantly.
                                </div>
                            </li>
                            <li class="flex items-start">
                                <span class="text-amber-500 mr-2 mt-0.5">⚠️</span>
                                <div>
                                    <strong class="text-gray-800">Server Restart:</strong> If you restart your Apache/PHP server, the RAM cache is cleared. Don't worry, the bot has a <em>Self-Healing</em> mechanism and will automatically fetch fresh data from the exchange on its next tick.
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Telegram Notifications FAQ -->
                <div id="telegram-notif" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 100}">
                    <button @click="active = active === 100 ? null : 100" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-blue-400 mr-3">📢</span> Q: When are notifications sent and how do I get my Chat ID?</span>
                        <span x-show="active !== 100" class="text-gray-400">➕</span>
                        <span x-show="active === 100" class="text-blue-500">➖</span>
                    </button>
                    <div x-show="active === 100" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <h3 class="font-bold text-gray-800 mb-2">When are notifications sent?</h3>
                        <p class="mb-4">The bot will send you real-time alerts for the following events:</p>
                        <ul class="list-disc ml-5 mb-4 space-y-1">
                            <li><strong>Base Order:</strong> When a new trade is opened.</li>
                            <li><strong>Order Filled:</strong> When a Limit Order is successfully executed.</li>
                            <li><strong>DCA Action:</strong> When the bot adds more capital to lower your average price.</li>
                            <li><strong>Take Profit:</strong> When a trade closes with a profit.</li>
                            <li><strong>Recovery Mode:</strong> Updates on debt recovery progress.</li>
                            <li><strong>API Errors:</strong> If the bot fails to connect or execute a trade.</li>
                        </ul>

                        <h3 class="font-bold text-gray-800 mb-2 mt-6">How to get your Telegram Chat ID?</h3>
                        <p class="mb-4">To receive notifications, follow these simple steps:</p>
                        <ol class="list-decimal ml-5 space-y-2">
                            <li>Search for <strong>@userinfobot</strong> on Telegram.</li>
                            <li>Start a chat with it and click <strong>/start</strong>.</li>
                            <li>The bot will reply with your <strong>Id</strong> (a long number like <code class="bg-gray-100 px-1 rounded">123456789</code>).</li>
                            <li>Go to <strong>Global Settings > Profile Settings</strong> in Fixzy Kriptobot and paste this number into the "Telegram Chat ID" field.</li>
                            <li><strong>Crucial:</strong> Make sure you have also started a chat with your actual Trading Bot (the one whose token is in the system) so it has permission to message you!</li>
                        </ol>
                    </div>
                </div>

                <!-- Max Active Deals FAQ -->
                <div id="max-deals" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 4}">
                    <button @click="active = active === 4 ? null : 4" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-purple-500 mr-3">🚀</span> Q: What are "Max Active Deals" (Composite Bot)?</span>
                        <span x-show="active !== 4" class="text-gray-400">➕</span>
                        <span x-show="active === 4" class="text-purple-500">➖</span>
                    </button>
                    <div x-show="active === 4" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This setting determines how many simultaneous trades (Active Deals) this single bot is allowed to run at the same time.</p>
                        
                        <ul class="space-y-4">
                            <li>
                                <strong class="text-gray-800">Capital Locking:</strong> When a Composite Bot starts its first trade, it calculates its <strong>Total Capital</strong> (if in AUTO) and "locks" it. This capital is then divided equally among the <em>Max Active Deals</em>.
                            </li>
                            <li>
                                <strong class="text-gray-800">Sequential Deals:</strong> The "Parent Bot" stays active and continues hunting for coins as long as it hasn't reached the Max Deals limit. It only becomes <strong>IDLE</strong> (and eligible for capital recalculation) when <em>all</em> its child deals have finished and closed.
                            </li>
                            <li>
                                <strong class="text-gray-800">Risk Management:</strong> By dividing your total budget across multiple deals, you reduce the risk of a single coin's crash wiping out your entire wallet.
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Start Order Type FAQ -->
                <div id="order-type" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 5}">
                    <button @click="active = active === 5 ? null : 5" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-blue-500 mr-3">⚡</span> Q: What is "Try Limit 1st" (Fallback to Market)?</span>
                        <span x-show="active !== 5" class="text-gray-400">➕</span>
                        <span x-show="active === 5" class="text-blue-500">➖</span>
                    </button>
                    <div x-show="active === 5" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This setting determines how the bot executes your very first entry (Base Order) into the market once a Buy Condition is met. Fixzy Kriptobot Enterprise uses a hybrid intelligent logic:</p>
                        
                        <ul class="space-y-4">
                            <li>
                                <strong class="text-gray-800">Market Order:</strong> Executes instantly at the current best available price. Guaranteed entry.
                            </li>
                            <li>
                                <strong class="text-gray-800">Try Limit 1st (Hybrid):</strong> The bot attempts to save you fees by placing a <strong>Limit Order</strong> first. However, if the order is not filled within the next 10 seconds (one tick), the bot will automatically cancel the limit and fallback to a <strong>Market Buy</strong>. 
                            </li>
                            <li>
                                <strong class="text-gray-800">Benefit:</strong> You get the best of both worlds—a chance for lower fees without the risk of being left behind during a fast market pump.
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- SECTION 2: RADAR -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">📡</span> Bot Setting: Radar & Market Scanner
                    </h2>
                </div>

                <!-- Coin Pair Strategy FAQ -->
                <div id="pair-strategy" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 3}">
                    <button @click="active = active === 3 ? null : 3" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-orange-500 mr-3">🎯</span> Q: How does the Coin Pair Strategy work?</span>
                        <span x-show="active !== 3" class="text-gray-400">➕</span>
                        <span x-show="active === 3" class="text-orange-500">➖</span>
                    </button>
                    <div x-show="active === 3" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The Coin Pair Strategy allows your bot to dynamically hunt for opportunities across multiple coins, rather than being stuck on just one.</p>
                        
                        <ul class="space-y-4">
                            <li>
                                <strong class="text-gray-800">Single Pair:</strong> The bot will strictly monitor and trade the single coin you specify.
                            </li>
                            <li>
                                <strong class="text-gray-800">Custom Multiple Pairs:</strong> You can provide a comma-separated list of coins (e.g., <code>BTC/USDT, ETH/USDT, SOL/USDT</code>). The bot will scan all of them in rotation. Whichever coin meets the "Start Condition" first will be bought.
                            </li>
                            <li>
                                <strong class="text-gray-800">Top 10 Volume / Top 50 Global:</strong> The bot automatically scans the exchange for the highest traded coins. This ensures you only trade highly liquid coins with strong market presence.
                            </li>
                            <li>
                                <strong class="text-gray-800">Top 10 Volatility:</strong> The bot hunts for coins with the highest 24-hour price swings. Warning: High risk, high reward.
                            </li>
                        </ul>
                        
                        <div class="mt-5 p-4 bg-orange-50 border border-orange-100 rounded-lg text-orange-900 text-sm flex items-start">
                            <span class="text-xl mr-3">💡</span>
                            <div>
                                <strong>Dynamic Radar System:</strong> Once a coin is bought, the bot locks its focus (becomes ACTIVE) onto that specific coin to manage its DCA and Take Profit. The radar is temporarily turned off. Once the deal closes successfully, the radar turns back on and it resumes scanning the market!
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECTION 3: BASE ORDER -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">🚀</span> Bot Setting: Base Order Execution
                    </h2>
                </div>

                <!-- Buy Cooldown FAQ -->
                <div id="buy-cooldown" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 6}">
                    <button @click="active = active === 6 ? null : 6" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-red-500 mr-3">⏱️</span> Q: Why do I need a Buy Cooldown?</span>
                        <span x-show="active !== 6" class="text-gray-400">➕</span>
                        <span x-show="active === 6" class="text-red-500">➖</span>
                    </button>
                    <div x-show="active === 6" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The Buy Cooldown acts as a safety shield preventing "Whipsaw" or spam buying behavior.</p>
                        <p class="mb-2">Imagine your bot just hit Take Profit on BTC/USDT. However, the RSI signal is technically still in the "Oversold" zone. Without a cooldown, the bot would instantly buy BTC/USDT again the very next second, right before the price potentially crashes further.</p>
                        <p>By setting a cooldown (e.g., <strong>7200 seconds / 2 hours</strong>), you force the bot to ignore that specific coin for 2 hours after a trade closes, allowing the market to stabilize.</p>
                    </div>
                </div>

                <!-- Trailing Buy FAQ -->
                <div id="trailing-buy" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 7}">
                    <button @click="active = active === 7 ? null : 7" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-teal-500 mr-3">📉</span> Q: How does Trailing Buy catch a market crash?</span>
                        <span x-show="active !== 7" class="text-gray-400">➕</span>
                        <span x-show="active === 7" class="text-teal-500">➖</span>
                    </button>
                    <div x-show="active === 7" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Trailing Buy is a powerful strategy designed to "catch a falling knife" and get you the absolute best entry price during a market crash.</p>
                        
                        <ul class="space-y-4">
                            <li>
                                <strong class="text-gray-800">The Wait Game:</strong> When your Start Condition (e.g., RSI) tells the bot to buy, instead of buying immediately, the bot activates a <em>Trailing Tracker</em> and waits.
                            </li>
                            <li>
                                <strong class="text-gray-800">Watermark Tracking:</strong> If the price continues to crash, the bot updates its "Low Watermark" to follow the price all the way down.
                            </li>
                            <li>
                                <strong class="text-gray-800">The Deviation Trigger:</strong> The bot only executes the actual Buy Order when the price bounces back up by your specified <strong>Trailing Deviation</strong> percentage from the absolute bottom.
                            </li>
                        </ul>
                        
                        <div class="mt-4 p-4 bg-teal-50 border border-teal-100 rounded-lg text-teal-900 text-sm flex items-start">
                            <span class="text-xl mr-3">💡</span>
                            <div>
                                <strong>Example:</strong> BTC crashes from $60k to $50k and triggers your RSI. The bot waits. BTC drops further to $45k. The bot waits. Finally, BTC bounces up by 0.5% (your deviation) from $45k to $45,225. The bot executes the buy at $45,225 instead of $50,000!
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Trade Start Conditions FAQ -->
                <div id="start-conditions" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 8}">
                    <button @click="active = active === 8 ? null : 8" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-pink-500 mr-3">🎯</span> Q: How do Trade Start Conditions work?</span>
                        <span x-show="active !== 8" class="text-gray-400">➕</span>
                        <span x-show="active === 8" class="text-pink-500">➖</span>
                    </button>
                    <div x-show="active === 8" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Start Conditions dictate exactly <strong>when</strong> the bot should enter a trade. You can add multiple conditions (Logical AND), which means <strong>ALL</strong> conditions must be met simultaneously for the bot to buy.</p>
                        
                        <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4 mb-4">
                            <p class="text-indigo-900">
                                <span class="font-bold">Note:</span> Fixzy Kriptobot uses professional industry-leading signal inputs. For a full breakdown of every RSI, Bollinger, and QFL option, please refer to the <a href="#signal-logic" @click.prevent="active = 30; $nextTick(() => { document.getElementById('signal-logic').scrollIntoView({behavior: 'smooth'}) })" class="text-indigo-600 font-bold underline hover:text-indigo-800">Signal Input Options FAQ</a>.
                            </p>
                        </div>

                        <p class="mb-2">Common strategies include combining RSI (Oversold) with Bollinger Bands (Lower Band) and News Sentiment (Bullish) to ensure a high-probability entry.</p>
                    </div>
                </div>
            </section>

            <!-- SECTION 4: DCA -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">🛡️</span> Bot Setting: DCA (Safety Orders)
                    </h2>
                </div>

                <div id="dca-max-steps" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 9}">
                    <button @click="active = active === 9 ? null : 9" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: What is Max Safety Trades Count?</span>
                        <span x-show="active !== 9" class="text-gray-400">➕</span>
                        <span x-show="active === 9" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 9" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This is the <strong>maximum number of safety orders (DCA steps)</strong> the bot is allowed to execute for one active deal.</p>
                        <p>If you set this to 4, the bot will buy up to 4 more times as the price drops. After the 4th time, it will stop buying and wait for the price to recover (Take Profit) or hit your Cut Loss limit.</p>
                    </div>
                </div>

                <div id="dca-price-drop" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 10}">
                    <button @click="active = active === 10 ? null : 10" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: What is Price Drop Trigger (%)?</span>
                        <span x-show="active !== 10" class="text-gray-400">➕</span>
                        <span x-show="active === 10" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 10" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This dictates <strong>when</strong> the bot should execute the safety order.</p>
                        <p>It is the percentage the price must drop from your <em>Average Entry Price</em> before the bot executes the first safety order. If set to 2.0%, the bot buys again when your deal is -2.0% in the red.</p>
                    </div>
                </div>

                <div id="dca-volume-scale" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 11}">
                    <button @click="active = active === 11 ? null : 11" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: What is Volume Scale (Capital Multiplier)?</span>
                        <span x-show="active !== 11" class="text-gray-400">➕</span>
                        <span x-show="active === 11" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 11" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This is the multiplier for your trade size (Martingale strategy) designed to heavily pull your average price down.</p>
                        <p>If your Base Order is $10 and Volume Scale is 1.5:<br>
                        - DCA 1 will be $15 ($10 x 1.5)<br>
                        - DCA 2 will be $22.50 ($15 x 1.5)<br>
                        By buying larger amounts at cheaper prices, a very small bounce is all you need to exit with a profit.</p>
                    </div>
                </div>

                <div id="dca-step-scale" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 12}">
                    <button @click="active = active === 12 ? null : 12" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: What is Step Scale (Distance Multiplier)?</span>
                        <span x-show="active !== 12" class="text-gray-400">➕</span>
                        <span x-show="active === 12" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 12" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This is the multiplier for the price drop distance, widening your safety net during a flash crash.</p>
                        <p>If Drop Trigger is 2.0% and Step Scale is 1.2:<br>
                        - DCA 1 triggers at -2.0%<br>
                        - DCA 2 triggers at -2.4% deeper (Total -4.4%)<br>
                        - DCA 3 triggers at -2.88% deeper (Total -7.28%)<br>
                        This prevents the bot from spending all its safety orders too quickly in a small crash.</p>
                    </div>
                </div>

                <div id="dca-placed-exchange" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 13}">
                    <button @click="active = active === 13 ? null : 13" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: What is "Place Orders on Exchange?"</span>
                        <span x-show="active !== 13" class="text-gray-400">➕</span>
                        <span x-show="active === 13" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 13" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This dictates how the bot sends the order to the exchange.</p>
                        <p><strong>Yes:</strong> The bot calculates the next DCA price and pre-places a Limit Order in the exchange's order book <em>in advance</em>. You can see it resting in Binance.<br>
                        <strong>No:</strong> The bot hides its intention. It tracks the price live and triggers a Market Buy only when the price drops to the target. This is mandatory if you use Custom Conditions or Trailing.</p>
                    </div>
                </div>

                <div id="dca-custom-conditions" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 14}">
                    <button @click="active = active === 14 ? null : 14" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: How do Custom DCA Conditions work?</span>
                        <span x-show="active !== 14" class="text-gray-400">➕</span>
                        <span x-show="active === 14" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 14" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Also known as <strong>Smart Averaging</strong>.</p>
                        <p class="mb-4">Instead of buying blindly just because the price dropped by 5%, the bot uses 5% as a <em>minimum depth requirement</em>. Once it drops 5%, the bot waits for a custom signal (like RSI < 30 or QFL bounce) to confirm the price has bottomed out before executing the DCA.</p>
                        
                        <div class="bg-emerald-50 border border-emerald-100 rounded-lg p-4">
                            <p class="text-emerald-900">
                                <span class="font-bold">Signal Details:</span> To see all available indicators and values for DCA, check the <a href="#signal-logic" @click.prevent="active = 30; $nextTick(() => { document.getElementById('signal-logic').scrollIntoView({behavior: 'smooth'}) })" class="text-emerald-700 font-bold underline hover:text-emerald-900">Signal Input Options FAQ</a>.
                            </p>
                        </div>
                    </div>
                </div>

                <div id="dca-trailing" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 15}">
                    <button @click="active = active === 15 ? null : 15" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">🛡️</span> Q: How does Trailing for Safety Orders work?</span>
                        <span x-show="active !== 15" class="text-gray-400">➕</span>
                        <span x-show="active === 15" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 15" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">A powerful feature that tracks a crashing price downwards to catch the absolute bottom.</p>
                        <p>Once the price drops to your Drop Trigger level (e.g. -5%), the bot activates the Trailing Tracker. If the coin crashes further to -10%, the bot follows it. The bot will only execute the DCA order when the price bounces back up by your <em>Trailing Deviation (%)</em>.</p>
                    </div>
                </div>
            </section>

            <!-- SECTION 5: TAKE PROFIT -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">💰</span> Bot Setting: Take Profit & Sell
                    </h2>
                </div>

                <div id="tp-type" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 16}">
                    <button @click="active = active === 16 ? null : 16" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">💰</span> Q: What is Take Profit Type?</span>
                        <span x-show="active !== 16" class="text-gray-400">➕</span>
                        <span x-show="active === 16" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 16" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This determines the <strong>Target Profit Volume</strong> the bot aims to achieve.</p>
                        <ul class="space-y-3">
                            <li><strong>% from Average Price:</strong> The bot aims to earn the target percentage out of the <em>entire accumulated volume</em> (Base Order + all DCA orders).</li>
                            <li><strong>% from Base Order:</strong> The bot only aims to earn the target percentage out of the <em>initial Base Order volume</em>. As the bot executes more DCAs, the required price bounce to hit this profit becomes drastically smaller, allowing for extremely fast exits during a downtrend.</li>
                        </ul>
                    </div>
                </div>

                <div id="tp-target" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 17}">
                    <button @click="active = active === 17 ? null : 17" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">💰</span> Q: What is Target Profit (%)?</span>
                        <span x-show="active !== 17" class="text-gray-400">➕</span>
                        <span x-show="active === 17" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 17" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The profit milestone.</p>
                        <p>Once the deal's PNL reaches this percentage (based on the Take Profit Type), the bot will sell your entire holdings for that coin and close the deal securely.</p>
                    </div>
                </div>

                <div id="tp-trailing" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 18}">
                    <button @click="active = active === 18 ? null : 18" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-green-600 mr-3">📈</span> Q: How does Trailing Take Profit work?</span>
                        <span x-show="active !== 18" class="text-gray-400">➕</span>
                        <span x-show="active === 18" class="text-green-600">➖</span>
                    </button>
                    <div x-show="active === 18" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Maximizes profits during a massive pump.</p>
                        <p>If Target Profit is 2.0% and Trailing Deviation is 0.2%:<br>
                        Once the price hits +2.0%, the bot <strong>does not sell</strong>. It tracks the price upwards. If the price pumps to +10.0%, the bot follows it. The bot will only sell when the price drops by 0.2% from the highest peak (e.g., at +9.8%).</p>
                    </div>
                </div>

                <div id="tp-partial-sell" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 21}">
                    <button @click="active = active === 21 ? null : 21" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-indigo-600 mr-3">✂️</span> Q: How does Partial Sell (Scaling Out) work?</span>
                        <span x-show="active !== 21" class="text-gray-400">➕</span>
                        <span x-show="active === 21" class="text-indigo-600">➖</span>
                    </button>
                    <div x-show="active === 21" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Secures profits progressively instead of dumping everything at once.</p>
                        <p>If you set your targets to <code>1.0, 2.0, 3.0</code>:<br>
                        - At +1.0% profit, the bot sells 1/3 of your coins.<br>
                        - At +2.0% profit, the bot sells half of the remaining coins.<br>
                        - At +3.0% profit, the bot sells the rest and closes the deal.</p>
                    </div>
                </div>

                <div id="tp-custom-sell" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-gray-500 shadow-md': active === 22}">
                    <button @click="active = active === 22 ? null : 22" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-gray-600 mr-3">⚙️</span> Q: How do Custom Sell Conditions work?</span>
                        <span x-show="active !== 22" class="text-gray-400">➕</span>
                        <span x-show="active === 22" class="text-gray-600">➖</span>
                    </button>
                    <div x-show="active === 22" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Allows external indicators (like TradingView Webhooks, External APIs, or News AI) to force the bot to sell the position immediately.</p>
                        <p class="mb-4">Unlike Start Conditions (which use Logical AND), Sell Conditions use <strong>Logical OR</strong>. This means if you have multiple conditions, the bot will sell the moment <em>any single one</em> of those conditions is met.</p>
                        
                        <div class="bg-gray-100 border border-gray-200 rounded-lg p-4 mb-4">
                            <p class="text-gray-800">
                                <span class="font-bold">Indicator Values:</span> Sell signals (e.g. Price Above Upper Band, RSI Overbought) are explained in the <a href="#signal-logic" @click.prevent="active = 30; $nextTick(() => { document.getElementById('signal-logic').scrollIntoView({behavior: 'smooth'}) })" class="text-indigo-600 font-bold underline hover:text-indigo-800">Signal Input Options FAQ</a>.
                            </p>
                        </div>

                        <p><strong>Warning:</strong> Highly recommended to use this alongside Minimum Profit Guard to prevent unwanted losses.</p>
                    </div>
                </div>
            </section>

            <!-- SECTION 6: RISK -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">⚠️</span> Bot Setting: Risk Management
                    </h2>
                </div>

                <div id="tp-cut-loss" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-red-500 shadow-md': active === 19}">
                    <button @click="active = active === 19 ? null : 19" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-red-500 mr-3">🛑</span> Q: What is Stop Loss / Cut Loss?</span>
                        <span x-show="active !== 19" class="text-gray-400">➕</span>
                        <span x-show="active === 19" class="text-red-500">➖</span>
                    </button>
                    <div x-show="active === 19" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The ultimate safety switch to prevent liquidation or total loss.</p>
                        <ul class="space-y-3">
                            <li><strong>Hard Cut Loss:</strong> Sells the coin immediately if the PNL drops below your set percentage (e.g., -5.0%).</li>
                            <li><strong>Trailing Stop Loss:</strong> A dynamic stop loss that moves upwards as your profit grows. If the price rises to +4.0% but hasn't hit your target, the -5.0% stop loss line also moves up to protect your capital.</li>
                        </ul>
                    </div>
                </div>

                <div id="tp-min-guard" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 20}">
                    <button @click="active = active === 20 ? null : 20" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-indigo-600 mr-3">🛡️</span> Q: What is Minimum Profit Guard?</span>
                        <span x-show="active !== 20" class="text-gray-400">➕</span>
                        <span x-show="active === 20" class="text-indigo-600">➖</span>
                    </button>
                    <div x-show="active === 20" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">A protective shield for Custom Sell conditions.</p>
                        <p>If you set your bot to sell based on TradingView or RSI, those indicators might trigger a "Sell" signal while you are still in a loss! <br>
                        <strong>Minimum Profit Guard</strong> prevents the bot from obeying that sell signal unless the trade is currently in profit by at least the specified percentage. <br>
                        The <em>Timeout Bypass</em> allows the bot to eventually sell at a loss if the trade gets stuck for too many hours.</p>
                    </div>
                </div>
            </section>

            <!-- SECTION 7: INSTITUTIONAL -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">🏢</span> Institutional Guardrails & Recovery
                    </h2>
                </div>

                <div id="smart-recovery" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-orange-500 shadow-md': active === 23}">
                    <button @click="active = active === 23 ? null : 23" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-orange-600 mr-3">🚑</span> Q: What is Universal Smart Recovery Mode?</span>
                        <span x-show="active !== 23" class="text-gray-400">➕</span>
                        <span x-show="active === 23" class="text-orange-600">➖</span>
                    </button>
                    <div x-show="active === 23" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">This is the bot's "Emergency Rescue" system. Its sole purpose is to win back every cent lost during a Cut Loss event.</p>
                        <div class="space-y-4">
                            <div class="p-4 bg-orange-50 border border-orange-100 rounded-lg">
                                <h4 class="font-bold text-orange-900 mb-1">1. Automatic Quarantine</h4>
                                <p class="text-orange-800">When a Cut Loss happens, the system immediately "pauses" all your other idle bots. This preserves your remaining capital so it can be focused entirely on recovery efforts.</p>
                            </div>
                            <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                <h4 class="font-bold text-gray-800 mb-1">2. Conservative Re-Entry</h4>
                                <p>The Recovery Bot uses ultra-conservative settings (RSI < 30 + Bollinger Lower) to ensure it only enters the market at the absolute bottom. It aims for small, safe 1% profits repeatedly until the debt is cleared.</p>
                            </div>
                            <div class="p-4 bg-blue-50 border border-blue-100 rounded-lg">
                                <h4 class="font-bold text-blue-900 mb-1">3. Auto-Resume</h4>
                                <p class="text-blue-800">Once the Recovery Bot has successfully earned back the lost amount, it will automatically delete itself and re-activate all your other bots to their normal status.</p>
                            </div>
                            <div class="p-4 bg-indigo-50 border border-indigo-100 rounded-lg">
                                <h4 class="font-bold text-indigo-900 mb-2">Technical Rescue Parameters:</h4>
                                <ul class="text-xs text-indigo-800 space-y-1 list-disc list-inside">
                                    <li><strong>Minimum Capital:</strong> Always starts with at least $15 to ensure trade execution.</li>
                                    <li><strong>Aggressive DCA:</strong> 5 safety steps with 1.5x Martingale multiplier.</li>
                                    <li><strong>Tight Range:</strong> Safety orders triggered every 2% price drop.</li>
                                    <li><strong>Safe TP:</strong> Strictly targets 1.0% profit to exit trades quickly.</li>
                                    <li><strong>Smart Entry:</strong> Only buys during extreme oversold conditions (RSI < 30).</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="global-toggle" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-emerald-500 shadow-md': active === 24}">
                    <button @click="active = active === 24 ? null : 24" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-emerald-500 mr-3">🔌</span> Q: What does the Global Filters Master Toggle do?</span>
                        <span x-show="active !== 24" class="text-gray-400">➕</span>
                        <span x-show="active === 24" class="text-emerald-500">➖</span>
                    </button>
                    <div x-show="active === 24" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p>This is the main switch for your entire safety network. When <strong>ENABLED</strong>, the bot daemon will enforce all the rules listed in the Global Guardrails section across every single bot you own. When <strong>DISABLED</strong>, all bots will revert to their individual settings, ignoring any global restrictions.</p>
                    </div>
                </div>

                <div id="global-blacklist" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-gray-800 shadow-md': active === 25}">
                    <button @click="active = active === 25 ? null : 25" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-black mr-3">🚫</span> Q: How does the Global Blacklist work?</span>
                        <span x-show="active !== 25" class="text-gray-400">➕</span>
                        <span x-show="active === 25" class="text-black">➖</span>
                    </button>
                    <div x-show="active === 25" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p>The Global Blacklist is an absolute ban on specific coins. You can enter base symbols (e.g., <code>LUNA, FTT</code>) or full pairs. If a coin is in this list, no bot will ever be allowed to buy it, even if all other conditions are perfect. This protects your capital from projects that are failing or being delisted.</p>
                    </div>
                </div>

                <div id="global-buy-filters" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 26}">
                    <button @click="active = active === 26 ? null : 26" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-indigo-600 mr-3">🛡️</span> Q: What are Global Base Buy & DCA Rules?</span>
                        <span x-show="active !== 26" class="text-gray-400">➕</span>
                        <span x-show="active === 26" class="text-indigo-600">➖</span>
                    </button>
                    <div x-show="active === 26" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-2">These act as <strong>Mandatory Prerequisites</strong>. For a bot to execute a buy (Base Order or DCA), it must satisfy its own internal conditions <strong>AND</strong> satisfy these Global conditions simultaneously (Logical AND).</p>
                        <p><em>Example:</em> If you set a Global Rule of <code>RSI < 40</code>, even if a specific bot has <code>RSI < 30</code>, the trade will only happen if the overall market sentiment is healthy enough to pass the Global 40 RSI check first.</p>
                    </div>
                </div>

                <div id="global-sell-filters" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-red-500 shadow-md': active === 27}">
                    <button @click="active = active === 27 ? null : 27" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-red-600 mr-3">🚨</span> Q: What are Global Sell Rules?</span>
                        <span x-show="active !== 27" class="text-gray-400">➕</span>
                        <span x-show="active === 27" class="text-red-600">➖</span>
                    </button>
                    <div x-show="active === 27" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-2">Global Sell Rules are <strong>Emergency Exit Triggers</strong>. They function as a "Logical OR" with your bot's individual settings.</p>
                        <p>If any single Global Sell Rule is met (e.g., an AI sentiment shift to 'SELL'), the system will override your bot's profit targets and force an immediate market sell for all active positions to protect your capital from a sudden crash.</p>
                    </div>
                </div>
            </section>

            <!-- SECTION 8: SIGNAL INPUTS -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">📊</span> Bot Setting: Signal Input Options
                    </h2>
                </div>

                <div id="signal-logic" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 30}">
                    <button @click="active = active === 30 ? null : 30" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-indigo-600 mr-3">⚡</span> Q: What do the different Signal Values mean?</span>
                        <span x-show="active !== 30" class="text-gray-400">➕</span>
                        <span x-show="active === 30" class="text-indigo-600">➖</span>
                    </button>
                    <div x-show="active === 30" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-6 text-base text-gray-700">Fixzy Kriptobot supports a wide range of industry-standard trading signals used by professional hedge funds. These values are used across <strong>Trade Start</strong>, <strong>DCA</strong>, and <strong>Custom Sell</strong> conditions.</p>
                        
                        <div class="space-y-8">
                            <!-- RSI -->
                            <div>
                                <h4 class="font-bold text-gray-900 border-b pb-2 mb-3 flex items-center">
                                    <span class="bg-indigo-100 text-indigo-700 p-1 rounded mr-2">📈</span> RSI (Relative Strength Index)
                                </h4>
                                <ul class="list-disc ml-5 space-y-2">
                                    <li><span class="font-bold text-gray-800">Static Thresholds:</span> <code>&lt; 30</code> (Oversold), <code>&gt; 70</code> (Overbought), etc. Triggered whenever the value is within the zone.</li>
                                    <li><span class="font-bold text-gray-800">Crossing Up:</span> <code>crossing_up_30</code>. Triggered ONLY at the moment the RSI line crosses from below 30 to above 30. Perfect for catching reversals.</li>
                                    <li><span class="font-bold text-gray-800">Crossing Down:</span> <code>crossing_down_70</code>. Triggered ONLY at the moment the RSI line falls below 70.</li>
                                </ul>
                            </div>

                            <!-- Bollinger -->
                            <div>
                                <h4 class="font-bold text-gray-900 border-b pb-2 mb-3 flex items-center">
                                    <span class="bg-purple-100 text-purple-700 p-1 rounded mr-2">🧬</span> Bollinger Bands (BB)
                                </h4>
                                <ul class="list-disc ml-5 space-y-2">
                                    <li><span class="font-bold text-gray-800">Price Location:</span> <code>Below Lower Band</code>, <code>Above Upper Band</code>, or <code>Below Middle Band (SMA)</code>.</li>
                                    <li><span class="font-bold text-gray-800">Crossing:</span> <code>Crossing Up Middle Band</code> signals a bullish trend shift. <code>Crossing Down Upper Band</code> signals a potential correction.</li>
                                    <li><span class="font-bold text-gray-800">%B Indicators:</span> <code>%B &lt; 0</code> means price is outside the lower band (extreme oversold). <code>%B &gt; 1</code> means price is outside the upper band.</li>
                                </ul>
                            </div>

                            <!-- QFL -->
                            <div>
                                <h4 class="font-bold text-gray-900 border-b pb-2 mb-3 flex items-center">
                                    <span class="bg-orange-100 text-orange-700 p-1 rounded mr-2">📉</span> Quickfingers Luc (QFL)
                                </h4>
                                <p class="mb-2 italic text-gray-500">A strategy based on identifying "Bases" (Fractal Lows) and buying "Cracks" (Price Drops).</p>
                                <ul class="list-disc ml-5 space-y-2">
                                    <li><span class="font-bold text-gray-800">Modes:</span> <code>Original</code> (3% crack), <code>Day Trade</code> (5%), <code>Conservative</code> (7%).</li>
                                    <li><span class="font-bold text-gray-800">Depth:</span> <code>10% Below QFL Base</code> is used for catching massive flash crashes.</li>
                                    <li><span class="font-bold text-gray-800">Resistance:</span> <code>Above QFL Resistance</code> or <code>Approaching Resistance (90%)</code> are used for intelligent profit taking.</li>
                                </ul>
                            </div>

                            <!-- Webhooks & Sentiment -->
                            <div>
                                <h4 class="font-bold text-gray-900 border-b pb-2 mb-3 flex items-center">
                                    <span class="bg-blue-100 text-blue-700 p-1 rounded mr-2">🔗</span> Webhooks & AI Sentiment
                                </h4>
                                <p class="mb-2">Fixzy Kriptobot uses a <strong>Signal Hierarchy</strong> system:</p>
                                <ul class="list-disc ml-5 space-y-2">
                                    <li>If you set <code>BUY</code>, the bot will also accept <code>STRONG_BUY</code> or <code>LONG</code>.</li>
                                    <li>If you set <code>FEAR</code> (Sentiment), the bot also accepts <code>EXTREME_FEAR</code>.</li>
                                    <li>If you set <code>SELL</code>, the bot also accepts <code>STRONG_SELL</code> or <code>PANIC_SELL</code>.</li>
                                </ul>
                            </div>

                            <!-- AI Market Condition (OHLCV) -->
                            <div>
                                <h4 class="font-bold text-gray-900 border-b pb-2 mb-3 flex items-center">
                                    <span class="bg-pink-100 text-pink-700 p-1 rounded mr-2">🧠</span> AI Market Analysis (OHLCV)
                                </h4>
                                <p class="mb-2">Unlike <em>news sentiment</em> (which reads headlines), the <strong>AI Market condition</strong> makes the AI analyze real-time <strong>OHLCV candle data</strong> fetched from the exchange (Binance API) and return one of three verdicts:</p>
                                <ul class="list-disc ml-5 space-y-2 mb-3">
                                    <li><span class="font-bold text-green-700">BUY</span> — AI sees bullish market structure. Condition passes only if you selected "AI says BUY".</li>
                                    <li><span class="font-bold text-red-700">SELL</span> — AI sees bearish market structure. Condition passes only if you selected "AI says SELL".</li>
                                    <li><span class="font-bold text-gray-600">EMPTY</span> — AI has no conviction. The condition <strong>does not pass</strong>, so the bot simply <strong>waits</strong> (it never buys or sells on EMPTY).</li>
                                </ul>
                                <ul class="list-disc ml-5 space-y-2">
                                    <li><span class="font-bold">Timeframe per condition:</span> each AI Market condition has its own candle timeframe (1m, 5m, 15m, 1h, 4h, 1d). The AI analyzes the last ~50 candles of that timeframe.</li>
                                    <li><span class="font-bold">Works everywhere:</span> Base Order entry, DCA conditions, Custom Sell, and all Global Filters (base_buy / dca_buy / sell).</li>
                                    <li><span class="font-bold">Caching:</span> the signal is cached for 5 minutes per symbol+timeframe so the AI API is not spammed on every tick.</li>
                                    <li><span class="font-bold">Requirement:</span> the AI integration must be configured (AI_API_KEY, AI_BASE_URL, AI_MODEL) and verified. See <a href="#feature-gating" @click.prevent="active = 101; $nextTick(() => { document.getElementById('feature-gating').scrollIntoView({behavior: 'smooth'}) })" class="text-indigo-600 font-bold underline hover:text-indigo-800">Feature Gating FAQ</a>.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI Market Condition: how to use in conditions -->
                <div id="ai-market-how" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 102}">
                    <button @click="active = active === 102 ? null : 102" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-pink-500 mr-3">🧠</span> Q: How do I use AI as a buy/sell/DCA condition?</span>
                        <span x-show="active !== 102" class="text-gray-400">➕</span>
                        <span x-show="active === 102" class="text-pink-500">➖</span>
                    </button>
                    <div x-show="active === 102" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">In the bot editor (or Global Filters), add a condition and choose <strong>"🧠 AI Market Analysis (OHLCV)"</strong> as the type, then pick:</p>
                        <ul class="list-disc ml-5 space-y-2 mb-4">
                            <li><strong>Value:</strong> "AI says BUY" or "AI says SELL" (what you want the AI to confirm).</li>
                            <li><strong>Timeframe:</strong> which candles the AI should read (1m … 1d).</li>
                        </ul>
                        <p class="mb-2">Example strategies:</p>
                        <ul class="list-disc ml-5 space-y-2 mb-4">
                            <li><strong>Entry:</strong> RSI &lt; 30 <em>AND</em> AI says BUY → oversold <em>and</em> AI confirms bullish structure.</li>
                            <li><strong>DCA guard:</strong> price drop 5% <em>AND</em> AI says BUY → only average down when AI agrees the trend is turning.</li>
                            <li><strong>Emergency sell:</strong> AI says SELL → exit even before take-profit when AI sees the market breaking down.</li>
                        </ul>
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                            <p class="text-amber-900"><span class="font-bold">Important:</span> if the AI returns <strong>EMPTY</strong> (no conviction), the condition fails and the bot <strong>waits</strong> — it will not buy, DCA, or sell on an empty signal. This is by design: no conviction means no action.</p>
                        </div>
                    </div>
                </div>

                <!-- Feature Gating -->
                <div id="feature-gating" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 101}">
                    <button @click="active = active === 101 ? null : 101" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-red-500 mr-3">🔌</span> Q: Why are some features disabled? (Feature Gating Policy)</span>
                        <span x-show="active !== 101" class="text-gray-400">➕</span>
                        <span x-show="active === 101" class="text-red-500">➖</span>
                    </button>
                    <div x-show="active === 101" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Fixzy Kriptobot gates every integration that needs an external API or token. A feature stays <strong>DISABLED</strong> until its credentials are (1) provided and (2) <strong>verified with a live call</strong>. You can see every feature's status and the exact reason it is disabled in the <strong>🔌 Integration & Feature Status</strong> banner on the bot editor and System Configuration pages.</p>
                        <div class="space-y-3 mb-4">
                            <div class="flex items-start">
                                <span class="text-red-500 mr-2 mt-0.5">❌</span>
                                <div><strong class="text-gray-800">Exchange API (COMPULSORY):</strong> without a verified exchange connection the bot cannot trade at all. Add your Binance testnet keys (Settings → Environment) or live keys (Settings → API Keys), then press "Re-verify All".</div>
                            </div>
                            <div class="flex items-start">
                                <span class="text-amber-500 mr-2 mt-0.5">⚠️</span>
                                <div><strong class="text-gray-800">AI Analysis (optional):</strong> powers AI Market conditions and news sentiment. Needs <code>AI_API_KEY</code>, <code>AI_BASE_URL</code> and <code>AI_MODEL</code> in <code>.env</code> (any OpenAI-compatible provider). Disabled until a live verification call succeeds.</div>
                            </div>
                            <div class="flex items-start">
                                <span class="text-amber-500 mr-2 mt-0.5">⚠️</span>
                                <div><strong class="text-gray-800">CryptoPanic News (optional):</strong> news source for AI sentiment. Needs <code>CRYPTOPANIC_API_KEY</code> in <code>.env</code>.</div>
                            </div>
                            <div class="flex items-start">
                                <span class="text-amber-500 mr-2 mt-0.5">⚠️</span>
                                <div><strong class="text-gray-800">Telegram Notifications (optional):</strong> needs <code>TELEGRAM_BOT_TOKEN</code> in <code>.env</code> plus a linked chat id in your profile.</div>
                            </div>
                        </div>
                        <p class="mb-2">What happens when a disabled feature is needed by a condition?</p>
                        <ul class="list-disc ml-5 space-y-2">
                            <li>The condition <strong>cannot pass</strong> — the bot waits (it never trades on a feature it cannot verify).</li>
                            <li>The daemon logs a <code>FEATURE_DISABLED</code> notice with the reason, prints it to the console, and sends a Telegram alert (max once per hour to avoid spam).</li>
                            <li>Once you add the credentials and verification passes, the feature switches to <strong>enabled</strong> automatically on the next daemon start or "Re-verify All".</li>
                        </ul>
                    </div>
                </div>

            <!-- SECTION 9: BACKTESTING -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">🧪</span> Bot Setting: Backtesting & Strategy Validation
                    </h2>
                </div>

                <div id="backtest-overview" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 40}">
                    <button @click="active = active === 40 ? null : 40" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-purple-500 mr-3">🧪</span> Q: What is Backtesting and how does it work?</span>
                        <span x-show="active !== 40" class="text-gray-400">➕</span>
                        <span x-show="active === 40" class="text-purple-500">➖</span>
                    </button>
                    <div x-show="active === 40" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Backtesting allows you to simulate your trading strategy against historical market data before risking real money. This helps you validate and fine-tune your configuration.</p>
                        <ul class="space-y-4">
                            <li><strong class="text-gray-800">Real Engine, Real Results:</strong> Fixzy Kriptobot's backtest uses the <em>exact same trading engines</em> as the live daemon — <code>BaseOrderConditionEngine</code>, <code>DcaConditionEngine</code>, <code>ExecutionGuardService</code>, and <code>TrailingEngine</code>. Bugs found in backtest are bugs that would affect your live trades.</li>
                            <li><strong class="text-gray-800">Candle-by-Candle Simulation:</strong> The engine replays historical 1-minute OHLCV data candle-by-candle, simulating the full cycle: Base Order → DCA → Take Profit / Cut Loss.</li>
                            <li><strong class="text-gray-800">Full Audit Trail:</strong> Every action is logged — buy signals, rule evaluations, DCA decisions, execution guard rejections, and sell events — giving you complete visibility into why the bot made each decision.</li>
                        </ul>
                        <div class="mt-5 p-4 bg-purple-50 border border-purple-100 rounded-lg text-purple-900 text-sm flex items-start">
                            <span class="text-xl mr-3">💡</span>
                            <div><strong>Pro Tip:</strong> Test your strategy across different market conditions (bull runs, crashes, sideways) by selecting different date ranges. A strategy that works in a bull market may fail in a bear market.</div>
                        </div>
                    </div>
                </div>

                <div id="backtest-data" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 41}">
                    <button @click="active = active === 41 ? null : 41" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-blue-500 mr-3">📥</span> Q: How do I prepare data for backtesting?</span>
                        <span x-show="active !== 41" class="text-gray-400">➕</span>
                        <span x-show="active === 41" class="text-blue-500">➖</span>
                    </button>
                    <div x-show="active === 41" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Before running a backtest, you need to download historical price data. This is a two-step process:</p>
                        <ol class="list-decimal ml-5 space-y-4">
                            <li><strong class="text-gray-800">Check Data Availability:</strong> Navigate to your bot's <strong>Settings</strong> page and scroll to the <strong>Backtest</strong> section. Select your symbol and date range, then check if data is already available. The system shows a timeline bar indicating which dates have data.</li>
                            <li><strong class="text-gray-800">Ingest Missing Data:</strong> If data is missing, click <strong>"Import Data"</strong>. The system downloads 1-minute OHLCV data from Binance Vision public archives and stores it in the <code>historical_ohlcv</code> table. This process may take a few minutes for large date ranges.</li>
                        </ol>
                        <div class="mt-4 p-4 bg-blue-50 border border-blue-100 rounded-lg text-blue-900 text-sm">
                            <strong>CLI Alternative:</strong> You can also ingest data via command line: <code class="bg-blue-100 px-2 py-0.5 rounded">php bin/ingest_data.php --symbol=BTCUSDT --days=30</code>
                        </div>
                        <p class="mt-4 text-gray-500 text-xs">Data source: Binance Vision Public Data. Requires at least 50 candles for accurate indicator calculations (RSI, Bollinger, QFL).</p>
                    </div>
                </div>

                <div id="backtest-limitations" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 42}">
                    <button @click="active = active === 42 ? null : 42" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-amber-500 mr-3">⚠️</span> Q: Can I backtest with TradingView/Webhook signals?</span>
                        <span x-show="active !== 42" class="text-gray-400">➕</span>
                        <span x-show="active === 42" class="text-amber-500">➖</span>
                    </button>
                    <div x-show="active === 42" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">No — backtesting cannot simulate external signals that require live data feeds.</p>
                        <div class="space-y-4">
                            <div class="p-4 bg-amber-50 border border-amber-100 rounded-lg">
                                <h4 class="font-bold text-amber-900 mb-1">Unavailable Signals in Backtest:</h4>
                                <ul class="list-disc ml-5 text-amber-800 space-y-1">
                                    <li><strong>TradingView Webhook</strong> (<code>tv_webhook</code>) — Requires live TradingView alerts.</li>
                                    <li><strong>External Signal</strong> (<code>external_signal</code>) — Requires external API calls.</li>
                                    <li><strong>News Sentiment AI</strong> (<code>news_sentiment</code>) — Requires live sentiment analysis.</li>
                                </ul>
                            </div>
                            <div class="p-4 bg-green-50 border border-green-100 rounded-lg">
                                <h4 class="font-bold text-green-900 mb-1">Fully Supported Signals:</h4>
                                <ul class="list-disc ml-5 text-green-800 space-y-1">
                                    <li><strong>RSI</strong> — All thresholds and crossovers.</li>
                                    <li><strong>Bollinger Bands</strong> — All band positions and %B indicators.</li>
                                    <li><strong>QFL</strong> — Original, Day Trade, and Conservative modes.</li>
                                    <li><strong>Start ASAP</strong> — Immediate entry (for testing DCA strategies).</li>
                                </ul>
                            </div>
                        </div>
                        <p class="mt-4"><strong>How the engine handles mixed conditions:</strong> If your bot has both RSI and Webhook signals, the backtest engine automatically filters out external signals and evaluates only the technical indicators. This way, you can still test the technical part of your strategy.</p>
                    </div>
                </div>

                <div id="backtest-accuracy" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-indigo-500 shadow-md': active === 43}">
                    <button @click="active = active === 43 ? null : 43" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-teal-500 mr-3">🎯</span> Q: How accurate is the backtest compared to live trading?</span>
                        <span x-show="active !== 43" class="text-gray-400">➕</span>
                        <span x-show="active === 43" class="text-teal-500">➖</span>
                    </button>
                    <div x-show="active === 43" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Fixzy Kriptobot's backtest is highly accurate for technical strategies, but has known limitations you should be aware of:</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse mb-4">
                                <thead><tr class="border-b border-gray-200"><th class="py-2 pr-4 text-gray-800">Aspect</th><th class="py-2 pr-4 text-gray-800">Backtest</th><th class="py-2 text-gray-800">Live Trading</th></tr></thead>
                                <tbody class="text-sm">
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium">Order Price</td><td class="py-2 pr-4">Candle close price (no slippage)</td><td class="py-2">Actual market fill price (±slippage)</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium">Trading Fees</td><td class="py-2 pr-4">Configurable (default 0.1%)</td><td class="py-2">Actual exchange fees</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium">Signal Engine</td><td class="py-2 pr-4 text-green-700 font-medium">Identical code ✓</td><td class="py-2">Same code</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium">DCA Logic</td><td class="py-2 pr-4 text-green-700 font-medium">Identical code ✓</td><td class="py-2">Same code</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium">Trailing Engine</td><td class="py-2 pr-4 text-green-700 font-medium">Identical code ✓</td><td class="py-2">Same code</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium">Webhook/AI Signals</td><td class="py-2 pr-4 text-amber-700">Filtered out ✗</td><td class="py-2">Live signal processing</td></tr>
                                    <tr><td class="py-2 pr-4 font-medium">Execution Guard</td><td class="py-2 pr-4 text-green-700 font-medium">Simulated ✓</td><td class="py-2">DB-based checks</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="p-4 bg-teal-50 border border-teal-100 rounded-lg">
                            <p class="text-teal-900"><strong>Key takeaway:</strong> Because Fixzy Kriptobot reuses its <em>actual production engine code</em> for backtesting, any bug found in backtest behavior is a bug that would also occur in live trading. This makes backtesting a powerful validation tool, not just an approximation.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECTION 10: AI AGENT -->
            <section class="space-y-6">
                <div class="sticky top-16 z-40 bg-gray-100 py-4">
                    <h2 class="text-xl font-bold text-indigo-900 border-l-4 border-indigo-500 pl-4 flex items-center">
                        <span class="mr-2">🧠</span> AI Agentic System
                    </h2>
                </div>

                <div id="ai-agent-overview" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-violet-500 shadow-md': active === 50}">
                    <button @click="active = active === 50 ? null : 50" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-violet-500 mr-3">🤖</span> Q: What is the AI Agent and how do I use it?</span>
                        <span x-show="active !== 50" class="text-gray-400">➕</span>
                        <span x-show="active === 50" class="text-violet-500">➖</span>
                    </button>
                    <div x-show="active === 50" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The AI Agent is your personal <strong>Strategy Engineer</strong> and <strong>Bot Architect</strong>. Instead of manually configuring every parameter, you simply describe your trading objective in natural language — and the AI handles the rest: analyzing markets, running backtests, and building the optimal bot configuration for you.</p>
                        
                        <div class="bg-violet-50 border border-violet-100 rounded-lg p-4 mb-4">
                            <h4 class="font-bold text-violet-900 mb-2">🎯 Example Commands:</h4>
                            <ul class="space-y-2 text-violet-800">
                                <li><code>"Create a low risk trading bot for BTC/USDT"</code></li>
                                <li><code>"Create a trading bot with low risk and optimum profit potential"</code></li>
                                <li><code>"Analyze ETH/USDT and recommend the best DCA strategy"</code></li>
                                <li><code>"Analyze the SOL/USDT market and recommend the best strategy"</code></li>
                                <li><code>"Optimize my existing bot #3 for current market conditions"</code></li>
                            </ul>
                        </div>

                        <h4 class="font-bold text-gray-800 mb-2 mt-6">How it works:</h4>
                        <ol class="list-decimal ml-5 space-y-2">
                            <li><strong>Describe your objective</strong> in the chat at <a href="agent.php" class="text-violet-600 underline font-bold">AI Agent page</a>.</li>
                            <li>The AI <strong>analyzes the market</strong> (price, trend, volatility, technical indicators, sentiment).</li>
                            <li>The AI <strong>runs backtests</strong> to validate the strategy against historical data.</li>
                            <li>The AI presents you with a <strong>complete bot configuration proposal</strong> including justification.</li>
                            <li>You <strong>review, approve, modify, or reject</strong> the proposal — the bot is never activated without your consent (unless Full Autonomy is enabled).</li>
                        </ol>
                    </div>
                </div>

                <div id="ai-agent-autonomy" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-violet-500 shadow-md': active === 51}">
                    <button @click="active = active === 51 ? null : 51" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-violet-500 mr-3">🔐</span> Q: What is the difference between Approval Required and Full Autonomy?</span>
                        <span x-show="active !== 51" class="text-gray-400">➕</span>
                        <span x-show="active === 51" class="text-violet-500">➖</span>
                    </button>
                    <div x-show="active === 51" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <div class="space-y-4">
                            <div class="p-4 bg-amber-50 border border-amber-100 rounded-lg">
                                <h4 class="font-bold text-amber-900 mb-1">🛡️ Approval Required (Recommended)</h4>
                                <p class="text-amber-800">The AI proposes configurations, but <strong>nothing is applied without your explicit approval</strong>. You'll be notified via Telegram (if configured) and can review the proposal in the dashboard. You can approve, reject, or modify the configuration before it goes live. This is the safest mode.</p>
                            </div>
                            <div class="p-4 bg-green-50 border border-green-100 rounded-lg">
                                <h4 class="font-bold text-green-900 mb-1">🚀 Full Autonomy</h4>
                                <p class="text-green-800">The AI automatically applies all recommendations within your preset capital limits. No manual approval is needed. This mode is suitable for experienced users who have thoroughly tested the AI's decision-making quality and want maximum automation.</p>
                            </div>
                        </div>
                        <p class="mt-4 text-gray-500 text-xs">You can change the autonomy mode anytime at <strong>Global Settings → AI Agentic System</strong>.</p>
                    </div>
                </div>

                <div id="ai-agent-telegram" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-violet-500 shadow-md': active === 52}">
                    <button @click="active = active === 52 ? null : 52" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-violet-500 mr-3">📬</span> Q: How do Telegram approvals work with the AI Agent?</span>
                        <span x-show="active !== 52" class="text-gray-400">➕</span>
                        <span x-show="active === 52" class="text-violet-500">➖</span>
                    </button>
                    <div x-show="active === 52" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">When the AI Agent creates a proposal and Telegram notifications are enabled, you receive a message like:</p>
                        
                        <div class="bg-gray-100 border border-gray-200 rounded-lg p-4 mb-4 font-mono text-xs">
                            🤖 <b>Fixzy Kriptobot AI Agent — New Proposal</b><br><br>
                            <b>Pair:</b> BTC/USDT<br>
                            <b>Target Profit:</b> 2%<br>
                            <b>Cut Loss:</b> 15%<br><br>
                            <i>Reply to this message with:</i><br>
                            ✅ /approve 42 — Approve<br>
                            ❌ /reject 42 — Reject<br>
                            ✏️ /review 42 — Review in dashboard
                        </div>

                        <p class="mb-4"><strong>How to reply directly from Telegram:</strong></p>
                        <ul class="list-disc ml-5 space-y-2">
                            <li>Type <code>/approve 42</code> and send — the bot config will be approved and applied automatically.</li>
                            <li>Type <code>/reject 42 too risky</code> — the proposal is rejected with your feedback as a note.</li>
                            <li>Type <code>/review 42</code> — you'll get a detailed summary of the proposal.</li>
                        </ul>
                        
                        <div class="mt-4 p-4 bg-violet-50 border border-violet-100 rounded-lg text-violet-900 text-sm">
                            <strong>Note:</strong> Telegram approvals require: (1) Your Chat ID configured in Profile Settings, (2) Telegram bot token configured in .env, (3) The webhook URL set to <code>https://yourdomain.com/webhook_telegram.php</code> via BotFather.
                        </div>
                    </div>
                </div>

                <div id="ai-agent-tools" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-violet-500 shadow-md': active === 53}">
                    <button @click="active = active === 53 ? null : 53" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-violet-500 mr-3">🔧</span> Q: What can the AI Agent actually do? (Available Tools)</span>
                        <span x-show="active !== 53" class="text-gray-400">➕</span>
                        <span x-show="active === 53" class="text-violet-500">➖</span>
                    </button>
                    <div x-show="active === 53" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The AI Agent has access to <strong>16 specialized tools</strong> that it can call autonomously during analysis:</p>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead><tr class="border-b border-gray-200"><th class="py-2 pr-4 text-gray-800">Category</th><th class="py-2 pr-4 text-gray-800">Tool</th><th class="py-2 text-gray-800">What it does</th></tr></thead>
                                <tbody class="text-sm">
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium" rowspan="5">📊 Market Analysis</td><td class="py-2 pr-4"><code>analyze_market</code></td><td class="py-2">Current prices, volume, market scan for top coins</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>analyze_technical</code></td><td class="py-2">RSI, Bollinger Bands, QFL indicators</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>analyze_sentiment</code></td><td class="py-2">News sentiment via AI (OpenAI-compatible LLM)</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>analyze_volatility</code></td><td class="py-2">ATR, standard deviation, volatility levels</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>analyze_trend</code></td><td class="py-2">Multi-timeframe trend direction (1h/4h/1d)</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium" rowspan="4">📁 Data</td><td class="py-2 pr-4"><code>get_historical_data</code></td><td class="py-2">OHLCV data availability check</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>get_trade_history</code></td><td class="py-2">Past trade performance</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>list_bots</code></td><td class="py-2">Overview of all user bots</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>get_bot_status</code></td><td class="py-2">Detailed bot status + recent trades</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4 font-medium" rowspan="2">🧪 Backtesting</td><td class="py-2 pr-4"><code>run_backtest</code></td><td class="py-2">Simulate strategy on historical data</td></tr>
                                    <tr class="border-b border-gray-100"><td class="py-2 pr-4"><code>optimize_strategy</code></td><td class="py-2">Grid search parameter optimization</td></tr>
                                    <tr><td class="py-2 pr-4 font-medium" rowspan="5">🤖 Bot Management</td><td class="py-2 pr-4"><code>create_bot</code></td><td class="py-2">Create new bot (DRAFT)</td></tr>
                                    <tr><td class="py-2 pr-4"><code>update_bot_config</code></td><td class="py-2">Update existing bot config</td></tr>
                                    <tr><td class="py-2 pr-4"><code>activate_bot</code></td><td class="py-2">Activate bot for live trading</td></tr>
                                    <tr><td class="py-2 pr-4"><code>delete_bot</code></td><td class="py-2">Deactivate bot</td></tr>
                                    <tr><td class="py-2"><code>monitor_bot</code></td><td class="py-2">Live bot monitoring snapshot</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="ai-agent-auto-optimize" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-violet-500 shadow-md': active === 54}">
                    <button @click="active = active === 54 ? null : 54" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-violet-500 mr-3">🔄</span> Q: Can the AI automatically optimize my bots over time?</span>
                        <span x-show="active !== 54" class="text-gray-400">➕</span>
                        <span x-show="active === 54" class="text-violet-500">➖</span>
                    </button>
                    <div x-show="active === 54" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">Yes! The <strong>CronAgentOptimizer</strong> can periodically review your agent-managed bots and suggest improvements.</p>
                        <ul class="space-y-3">
                            <li><strong class="text-gray-800">When it runs:</strong> Every 6 hours (configurable via cron: <code>0 0,6,12,18 * * *</code>).</li>
                            <li><strong class="text-gray-800">What it checks:</strong> Win rate, total PNL, recent cut losses, and current drawdown for each agent-managed bot.</li>
                            <li><strong class="text-gray-800">What triggers optimization:</strong> Win rate below 50%, negative PNL, multiple cut losses in a week, or drawdown exceeding limits.</li>
                            <li><strong class="text-gray-800">What happens:</strong> A new proposal is generated with optimized parameters and sent for your approval (or auto-applied in Full Autonomy mode).</li>
                        </ul>
                        <div class="mt-4 p-4 bg-violet-50 border border-violet-100 rounded-lg text-violet-900 text-sm">
                            <strong>Setup:</strong> Add the cron entry to your server. Bots must have <code>is_agent_managed = 1</code> (set automatically when created via AI Agent). The AI Agent must be enabled in Global Settings with <code>update_config</code> permission allowed.
                        </div>
                    </div>
                </div>

                <div id="ai-agent-safety" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden transition-all duration-300" :class="{'ring-2 ring-violet-500 shadow-md': active === 55}">
                    <button @click="active = active === 55 ? null : 55" class="w-full text-left px-6 py-5 font-bold text-gray-800 bg-gray-50 hover:bg-gray-100 focus:outline-none flex justify-between items-center transition-colors">
                        <span class="flex items-center text-lg"><span class="text-violet-500 mr-3">🛡️</span> Q: Is the AI Agent safe? Can it lose my money?</span>
                        <span x-show="active !== 55" class="text-gray-400">➕</span>
                        <span x-show="active === 55" class="text-violet-500">➖</span>
                    </button>
                    <div x-show="active === 55" x-collapse x-cloak class="px-6 py-5 text-gray-600 text-sm leading-relaxed border-t border-gray-100 bg-white">
                        <p class="mb-4 text-base">The AI Agent is designed with multiple layers of safety:</p>
                        <ul class="space-y-4">
                            <li>
                                <strong class="text-gray-800">1. Approval Required by Default</strong>
                                <p class="text-gray-600 mt-1">The AI never activates a bot without your consent. Every proposal must be explicitly approved by you (unless you deliberately enable Full Autonomy mode).</p>
                            </li>
                            <li>
                                <strong class="text-gray-800">2. Capital Limits</strong>
                                <p class="text-gray-600 mt-1">You set maximum capital per bot and maximum total capital. The AI cannot exceed these limits — they are hard constraints.</p>
                            </li>
                            <li>
                                <strong class="text-gray-800">3. Granular Permissions</strong>
                                <p class="text-gray-600 mt-1">You control exactly what the AI can do. For example, you can allow analysis and backtesting but disable bot creation until you're confident. Each action type (create, update, activate, deactivate) can be independently toggled.</p>
                            </li>
                            <li>
                                <strong class="text-gray-800">4. Full Audit Trail</strong>
                                <p class="text-gray-600 mt-1">Every tool the AI calls, every decision it makes, and every configuration change is logged in the database. You can trace exactly what happened and when.</p>
                            </li>
                            <li>
                                <strong class="text-gray-800">5. Rate Limiting</strong>
                                <p class="text-gray-600 mt-1">The AI agent loop is capped at 10 tool-calling iterations per message, preventing runaway API costs or infinite decision loops.</p>
                            </li>
                            <li>
                                <strong class="text-gray-800">6. Backtest Validation</strong>
                                <p class="text-gray-600 mt-1">Before proposing any configuration, the AI runs backtests using the <em>same engine</em> as live trading. You can see the win rate, PNL, and drawdown before approving.</p>
                            </li>
                        </ul>
                        <div class="mt-4 p-4 bg-indigo-50 border border-indigo-100 rounded-lg text-indigo-900 text-sm">
                            <strong>Best Practice:</strong> Start with "Approval Required" mode and only allow analysis + backtesting permissions. Once you're comfortable with the AI's recommendations, gradually enable create_bot and activate_bot. Use Full Autonomy only after extensive testing.
                        </div>
                    </div>
                </div>

            </section>

        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const hash = window.location.hash;
            const mapping = {
                '#exchange': 1, '#capital': 2, '#pair-strategy': 3, '#max-deals': 4,
                '#order-type': 5, '#buy-cooldown': 6, '#trailing-buy': 7, '#start-conditions': 8,
                '#dca-max-steps': 9, '#dca-price-drop': 10, '#dca-volume-scale': 11, '#dca-step-scale': 12,
                '#dca-placed-exchange': 13, '#dca-custom-conditions': 14, '#dca-trailing': 15,
                '#tp-type': 16, '#tp-target': 17, '#tp-trailing': 18, '#tp-cut-loss': 19,
                '#tp-min-guard': 20, '#tp-partial-sell': 21, '#tp-custom-sell': 22,
                '#smart-recovery': 23, '#global-toggle': 24, '#global-blacklist': 25,
                '#global-buy-filters': 26, '#global-sell-filters': 27, '#signal-logic': 30,
                '#backtest-overview': 40, '#backtest-data': 41, '#backtest-limitations': 42, '#backtest-accuracy': 43,
                '#ai-agent-overview': 50, '#ai-agent-autonomy': 51, '#ai-agent-telegram': 52, '#ai-agent-tools': 53, '#ai-agent-auto-optimize': 54, '#ai-agent-safety': 55
            };
            if (mapping[hash]) {
                setTimeout(() => { document.querySelector('[x-data]').__x.$data.active = mapping[hash]; }, 100);
            }
        });
    </script>
</body>
</html>
