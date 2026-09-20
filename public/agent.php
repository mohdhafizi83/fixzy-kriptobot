<?php
// File: public/agent.php — AI Agent Chat Interface
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
$userId = (int)$_SESSION['user_id'];

use Fixzy\Kriptobot\Agent\AgentSession;
use Fixzy\Kriptobot\Agent\Approval\ApprovalManager;

$userId = 1;
$aiCfg = \Fixzy\Kriptobot\Config\Config::aiConfig();
$aiConfigured = !empty($aiCfg["api_key"]);

$agentSession = new AgentSession();
$approvalManager = new ApprovalManager();

$sessions = $agentSession->getSessionsForUser($userId);
$activeSession = $agentSession->getActiveSession($userId);
$pendingDecisions = $approvalManager->getPendingDecisions($userId);
$settings = $approvalManager->getAgentSettings($userId);

if (!$settings['agent_enabled'] && $aiConfigured) {
    // Seed default agent settings via the backend service (no SQL in the page).
    $agentSettingsService = new \Fixzy\Kriptobot\Service\AgentSettingsService();
    $agentSettingsService->seedIfMissing($userId, [
        'agent_enabled'          => true,
        'autonomy_mode'          => 'approval_required',
        'max_capital_per_bot'    => 1000.0,
        'max_total_capital'      => 10000.0,
        'allowed_actions'        => ['analyze_market', 'view_data', 'run_backtest', 'create_bot', 'update_config', 'activate_bot', 'deactivate_bot'],
        'telegram_notifications' => true,
        'language_preference'    => 'auto',
    ]);
    $settings = $approvalManager->getAgentSettings($userId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#312e81">
    <title>Fixzy Kriptobot - AI Agent</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .typing-indicator span { animation: blink 1.4s infinite both; }
        .typing-indicator span:nth-child(2) { animation-delay: .2s; }
        .typing-indicator span:nth-child(3) { animation-delay: .4s; }
        @keyframes blink { 0%, 80%, 100% { opacity: 0; } 40% { opacity: 1; } }
        .tool-call { border-left: 3px solid #6366f1; }
        .tool-call.success { border-left-color: #10b981; }
        .tool-call.error { border-left-color: #ef4444; }
        .chat-messages { scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
        .sidebar-overlay { background: rgba(0,0,0,0.4); }
        @media (max-width: 768px) {
            .chat-input-focus { position: fixed; bottom: 0; left: 0; right: 0; z-index: 50; }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans h-[100dvh] flex flex-col overflow-hidden" x-data="agentChat()">

    <!-- Navbar -->
    <nav class="bg-indigo-900 text-white shadow-md shrink-0" x-data="{ navOpen: false }">
        <div class="flex justify-between items-center px-3 py-2 md:px-4 md:py-3">
            <div class="flex items-center space-x-2">
                <button @click="navOpen = !navOpen" class="md:hidden p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <button @click="sidebarOpen = !sidebarOpen" class="hidden md:block p-1" title="Sessions">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                </button>
                <div class="text-base md:text-xl font-bold tracking-wider truncate">🤖 KRIPTOBOT</div>
            </div>
            <div class="hidden md:flex items-center space-x-4 text-sm font-semibold">
                <a href="index.php" class="hover:text-indigo-300">Dashboard</a>
                <a href="agent.php" class="text-indigo-300 border-b-2 border-indigo-300 pb-1">AI Agent</a>
                <a href="settings.php" class="hover:text-indigo-300">New Bot</a>
                <a href="configure.php" class="hover:text-indigo-300">Settings</a>
                <a href="faq.php" class="hover:text-indigo-300">FAQ</a>
                <span class="text-indigo-400">|</span>
                <button @click="showDecisions = !showDecisions" class="relative" x-show="pendingDecisions.length > 0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <span class="absolute -top-2 -right-2 bg-red-500 text-white text-[10px] w-4 h-4 rounded-full flex items-center justify-center" x-text="pendingDecisions.length"></span>
                </button>
                <span class="text-gray-300 font-normal"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
                <a href="logout.php" class="text-red-400 hover:text-red-300 ml-2">Logout</a>
            </div>
        </div>
        <div x-show="navOpen" @click.away="navOpen = false" class="md:hidden bg-indigo-950 px-4 py-2 space-y-1 pb-4" x-transition x-cloak>
            <a href="index.php" class="block py-2 hover:text-indigo-300">📊 Dashboard</a>
            <a href="agent.php" class="block py-2 text-indigo-300 font-semibold">🤖 AI Agent</a>
            <a href="settings.php" class="block py-2 hover:text-indigo-300">🆕 New Bot</a>
            <a href="configure.php" class="block py-2 hover:text-indigo-300">⚙️ Settings</a>
            <a href="faq.php" class="block py-2 hover:text-indigo-300">📚 FAQ</a>
            <button @click="sidebarOpen = !sidebarOpen; navOpen = false" class="block w-full text-left py-2 hover:text-indigo-300">💬 Chat Sessions</button>
            <button @click="showDecisions = !showDecisions; navOpen = false" class="block w-full text-left py-2 hover:text-indigo-300" x-show="pendingDecisions.length > 0">⏳ Pending ({{ pendingDecisions.length }})</button>
            <hr class="border-indigo-800 my-1">
            <span class="block py-1 text-gray-400 text-xs"><?= htmlspecialchars($_SESSION['user_email']) ?></span>
            <a href="logout.php" class="block py-2 text-red-400 font-semibold">🚪 Logout</a>
        </div>
    </nav>

    <div class="flex flex-1 overflow-hidden relative">
        <!-- Mobile sidebar overlay -->
        <div x-show="sidebarOpen" @click.self="sidebarOpen = false" class="fixed inset-0 z-40 sidebar-overlay md:hidden" x-transition.opacity x-cloak></div>

        <!-- Sidebar -->
        <div class="w-64 bg-white border-r border-gray-200 flex flex-col shrink-0 overflow-y-auto
            fixed md:relative inset-y-0 left-0 z-50 md:z-auto
            transform transition-transform duration-200 ease-in-out
            md:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            style="top:49px; height:calc(100dvh - 49px)">

            <div class="p-3 md:p-4">
                <button @click="newChat(); sidebarOpen = false"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-4 rounded-lg transition text-sm touch-manipulation">
                    + New Chat
                </button>
            </div>

            <div class="text-xs font-semibold text-gray-500 uppercase mb-2 px-3 md:px-4">Sessions</div>
            <div class="space-y-0.5 flex-1 overflow-y-auto px-2 md:px-3">
                <template x-for="session in sessions" :key="session.id">
                    <div class="flex items-center group">
                        <button @click="loadSession(session.id); sidebarOpen = false"
                            class="flex-1 text-left px-3 py-2.5 rounded text-sm hover:bg-indigo-50 transition touch-manipulation"
                            :class="{'bg-indigo-100 font-medium': activeSessionId === session.id}">
                            <div x-text="session.title || 'Chat ' + session.id" class="truncate text-xs md:text-sm"></div>
                            <div x-text="session.created_at" class="text-[10px] md:text-xs text-gray-400"></div>
                        </button>
                        <button @click.stop="deleteSession(session.id)"
                            class="p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded transition touch-manipulation shrink-0 md:opacity-0 md:group-hover:opacity-100"
                            title="Delete">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </template>
                <div x-show="sessions.length === 0" class="text-xs text-gray-400 px-2 py-4 text-center">
                    No conversations yet.<br>Start a new chat!
                </div>
            </div>

            <!-- Agent status -->
            <div class="mt-auto border-t border-gray-200 p-3 md:p-4 pt-3 md:pt-4">
                <div class="text-[10px] md:text-xs font-semibold text-gray-500 uppercase mb-2">Status</div>
                <div class="flex items-center space-x-2 text-xs md:text-sm">
                    <span class="w-2 h-2 rounded-full shrink-0"
                        :class="agentEnabled ? 'bg-green-500' : 'bg-gray-400'"></span>
                    <span x-text="agentEnabled ? 'Agent Active' : 'Agent Disabled'"></span>
                </div>
                <div class="text-[10px] md:text-xs text-gray-400 mt-1" x-text="autonomyLabel"></div>
                <?php if (!$aiConfigured): ?>
                <div class="mt-2 p-2 bg-red-50 border border-red-200 text-red-700 text-[10px] md:text-xs rounded">
                    AI API key not configured. Set AI_API_KEY, AI_BASE_URL and AI_MODEL in .env.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0 relative" :class="{'hidden md:flex': showDecisions}">

            <!-- Mobile: Pending decisions overlay -->
            <div x-show="showDecisions" class="md:hidden flex flex-col bg-amber-50 absolute inset-0 z-30" x-transition x-cloak>
                <div class="flex items-center justify-between p-3 border-b border-amber-200 bg-white">
                    <h3 class="font-bold text-amber-800 text-sm">⏳ Pending ({{ pendingDecisions.length }})</h3>
                    <button @click="showDecisions = false" class="text-gray-400 hover:text-gray-600 p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    <template x-for="dec in pendingDecisions" :key="dec.id">
                        <div class="bg-white border border-amber-200 rounded-lg p-3 text-sm">
                            <div class="font-bold text-amber-900" x-text="'#' + dec.id + ' - ' + dec.decision_type"></div>
                            <div class="text-gray-500 text-xs mt-1" x-text="dec.created_at"></div>
                            <div class="mt-3 flex space-x-2">
                                <button @click="approveProposal(dec.id, true); showDecisions = false"
                                    class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-bold py-2 rounded touch-manipulation">Approve</button>
                                <button @click="rejectProposal(dec.id); showDecisions = false"
                                    class="flex-1 bg-red-500 hover:bg-red-600 text-white text-sm font-bold py-2 rounded touch-manipulation">Reject</button>
                            </div>
                        </div>
                    </template>
                    <div x-show="pendingDecisions.length === 0" class="text-center text-gray-400 py-8 text-sm">No pending approvals</div>
                </div>
            </div>

            <!-- Messages Area -->
            <div class="flex-1 overflow-y-auto px-3 py-4 md:px-6 md:py-6 chat-messages" x-ref="messageContainer">

                <!-- Welcome -->
                <div x-show="messages.length === 0 && !loading" class="flex items-center justify-center h-full">
                    <div class="text-center max-w-lg px-4">
                        <div class="text-5xl md:text-6xl mb-3 md:mb-4">🤖</div>
                        <h2 class="text-xl md:text-2xl font-bold text-gray-800 mb-2">Fixzy Kriptobot AI Agent</h2>
                        <p class="text-gray-500 mb-4 md:mb-6 text-sm md:text-base">
                            Your strategy engineer and bot architect. Describe your objective and I'll build the perfect trading bot.
                        </p>
                        <div class="grid grid-cols-1 gap-2 text-xs md:text-sm">
                            <button @click="sendPreset('Create a low-risk trading bot with optimum profit potential for BTC/USDT.')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:border-indigo-300 hover:bg-indigo-50 transition text-gray-600 touch-manipulation">
                                💼 Create a low-risk bot for BTC/USDT
                            </button>
                            <button @click="sendPreset('Analyze the current market for ETH/USDT and suggest the best strategy.')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:border-indigo-300 hover:bg-indigo-50 transition text-gray-600 touch-manipulation">
                                📊 Analyze ETH/USDT and suggest a strategy
                            </button>
                            <button @click="sendPreset('Create a moderate risk trading bot for SOL/USDT with good profit potential.')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:border-indigo-300 hover:bg-indigo-50 transition text-gray-600 touch-manipulation">
                                🎯 Create moderate risk bot for SOL/USDT
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <template x-for="(msg, i) in messages" :key="i">
                    <div class="mb-3 md:mb-4">
                        <!-- User -->
                        <div x-show="msg.role === 'user'" class="flex justify-end">
                            <div class="bg-indigo-600 text-white px-3 py-2 md:px-4 md:py-2 rounded-2xl rounded-br-md max-w-[85%] md:max-w-2xl text-sm"
                                x-text="msg.content"></div>
                        </div>

                        <!-- Assistant -->
                        <div x-show="msg.role === 'assistant' && msg.content"
                            class="flex justify-start">
                            <div class="bg-white border border-gray-200 px-3 py-2 md:px-4 md:py-2 rounded-2xl rounded-bl-md max-w-[85%] md:max-w-3xl text-sm shadow-sm"
                                x-html="safeFormatContent(msg.content)"></div>
                        </div>

                        <!-- Tool calls -->
                        <div x-show="msg.tool_trace && msg.tool_trace.length > 0" class="ml-3 md:ml-4 mt-1.5 md:mt-2 flex flex-wrap gap-1">
                            <template x-for="tool in msg.tool_trace" :key="tool.tool">
                                <div class="tool-call text-[10px] md:text-xs px-2 py-1 rounded bg-gray-50 inline-flex items-center space-x-1"
                                    :class="{'success': tool.success, 'error': !tool.success}">
                                    <span x-show="tool.success">✅</span>
                                    <span x-show="!tool.success">❌</span>
                                    <span class="font-mono font-semibold truncate max-w-[120px] md:max-w-none" x-text="tool.tool"></span>
                                    <span class="text-gray-400 hidden md:inline" x-text="'(' + tool.elapsed_ms + 'ms)'"></span>
                                </div>
                            </template>
                        </div>

                        <!-- Proposal card -->
                        <div x-show="msg.proposal" class="mt-2 md:mt-3 ml-3 md:ml-4">
                            <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 md:p-4 max-w-full md:max-w-3xl">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="font-bold text-emerald-800 text-xs md:text-sm">🤖 AI Proposal</span>
                                    <span class="text-[10px] md:text-xs text-emerald-600" x-text="msg.proposal.status || 'pending_approval'"></span>
                                </div>
                                <div class="text-[10px] md:text-xs text-gray-600 space-y-1">
                                    <div x-show="msg.proposal.config?.risk_management">
                                        <b>TP:</b> <span x-text="msg.proposal.config?.risk_management?.target_profit + '%'"></span>
                                        &nbsp;|&nbsp;
                                        <b>CL:</b> <span x-text="msg.proposal.config?.risk_management?.cut_loss_percent + '%'"></span>
                                        &nbsp;|&nbsp;
                                        <b>DCA:</b> <span x-text="msg.proposal.config?.dca?.max_steps + ' steps'"></span>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button @click="approveProposal(msg.proposal.decision_id, true)"
                                        class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2 px-4 rounded transition touch-manipulation flex-1 md:flex-none">
                                        ✅ Approve & Apply
                                    </button>
                                    <button @click="approveProposal(msg.proposal.decision_id, false)"
                                        class="bg-emerald-100 hover:bg-emerald-200 text-emerald-700 text-xs font-bold py-2 px-3 rounded transition touch-manipulation flex-1 md:flex-none">
                                        Approve Only
                                    </button>
                                    <button @click="rejectProposal(msg.proposal.decision_id)"
                                        class="bg-red-100 hover:bg-red-200 text-red-700 text-xs font-bold py-2 px-3 rounded transition touch-manipulation flex-1 md:flex-none">
                                        ❌ Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Typing -->
                <div x-show="loading" class="flex justify-start mb-3 md:mb-4">
                    <div class="bg-white border border-gray-200 px-4 py-3 rounded-2xl rounded-bl-md shadow-sm">
                        <div class="typing-indicator flex space-x-1">
                            <span class="w-2 h-2 bg-indigo-400 rounded-full"></span>
                            <span class="w-2 h-2 bg-indigo-400 rounded-full"></span>
                            <span class="w-2 h-2 bg-indigo-400 rounded-full"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input area -->
            <div class="border-t border-gray-200 bg-white shrink-0">
                <form @submit.prevent="sendMessage()" class="flex items-end space-x-2 p-2 md:p-4">
                    <textarea x-model="input" x-ref="chatInput" rows="1"
                        @keydown.enter.prevent="if(!$event.shiftKey) sendMessage()"
                        @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,120)+'px'"
                        placeholder="Describe your objective..."
                        class="flex-1 border border-gray-300 rounded-xl px-3 py-2.5 md:px-4 md:py-3 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none text-sm resize-none"
                        :disabled="loading"></textarea>
                    <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-400 text-white font-bold py-2.5 px-4 md:py-3 md:px-6 rounded-xl transition text-sm shrink-0 touch-manipulation"
                        :disabled="loading || !input.trim()">
                        <svg class="w-5 h-5 md:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        <span class="hidden md:inline">Send</span>
                    </button>
                </form>
                <div class="text-[10px] md:text-xs text-gray-400 px-3 pb-2 md:px-4 md:pb-1 text-center">
                    AI provider is OpenAI-compatible (DeepSeek, OpenRouter, Ollama, etc.). Hold Enter for new line.
                </div>
            </div>
        </div>

        <!-- Desktop: Pending panel -->
        <div x-show="pendingDecisions.length > 0" class="hidden md:block w-72 bg-amber-50 border-l border-amber-200 overflow-y-auto shrink-0">
            <div class="p-4">
                <h3 class="font-bold text-amber-800 text-sm mb-3">⏳ Pending Approvals</h3>
                <template x-for="dec in pendingDecisions" :key="dec.id">
                    <div class="bg-white border border-amber-200 rounded p-3 mb-2 text-xs">
                        <div class="font-bold text-amber-900" x-text="'#' + dec.id + ' - ' + dec.decision_type"></div>
                        <div class="text-gray-500 mt-1" x-text="dec.created_at"></div>
                        <div class="mt-2 flex space-x-1">
                            <button @click="approveProposal(dec.id, true)"
                                class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white text-xs py-1.5 px-2 rounded touch-manipulation">Approve</button>
                            <button @click="rejectProposal(dec.id)"
                                class="flex-1 bg-red-500 hover:bg-red-600 text-white text-xs py-1.5 px-2 rounded touch-manipulation">Reject</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('agentChat', () => ({
                messages: [],
                input: '',
                loading: false,
                sidebarOpen: false,
                showDecisions: false,
                sessions: <?= json_encode($sessions) ?>,
                activeSessionId: <?= json_encode($activeSession ? $activeSession['id'] : null) ?>,
                pendingDecisions: <?= json_encode($pendingDecisions) ?>,
                agentEnabled: <?= json_encode($settings['agent_enabled'] ?? false) ?>,
                autonomyLabel: <?= json_encode(($settings['autonomy_mode'] ?? 'approval_required') === 'full_autonomy' ? 'Full Autonomy (no approval needed)' : 'Approval Required') ?>,
                csrfToken: <?= json_encode($_SESSION['csrf_token'] ?? '') ?>,

                init() {
                    if (this.activeSessionId) {
                        this.loadSession(this.activeSessionId);
                    }
                },

                async sendMessage() {
                    const text = this.input.trim();
                    if (!text || this.loading) return;
                    this.input = '';
                    this.loading = true;
                    this.$refs.chatInput.style.height = 'auto';

                    this.messages.push({ role: 'user', content: text });

                    try {
                        const resp = await axios.post('api/agent_chat.php', {
                            action: 'chat',
                            message: text,
                            session_id: this.activeSessionId,
                            csrf_token: this.csrfToken,
                        });

                        const data = resp.data;

                        if (data.session_id && !this.activeSessionId) {
                            this.activeSessionId = data.session_id;
                            this.sessions.unshift({
                                id: data.session_id,
                                title: text.substring(0, 40),
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                            });
                        }

                        const msg = {
                            role: 'assistant',
                            content: data.message?.content || '',
                            tool_trace: data.message?.tool_trace || [],
                            proposal: data.proposal || null,
                        };

                        this.messages.push(msg);

                        if (data.proposal?.decision_id) {
                            this.pendingDecisions.unshift({
                                id: data.proposal.decision_id,
                                decision_type: data.proposal.decision_type || 'create_bot',
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                                status: data.proposal.status || 'pending_approval',
                            });
                        }

                    } catch (e) {
                        this.messages.push({
                            role: 'assistant',
                            content: '❌ Error: ' + (e.response?.data?.error || e.message),
                            tool_trace: [],
                            proposal: null,
                        });
                    }

                    this.loading = false;
                    this.$nextTick(() => this.scrollToBottom());
                },

                async loadSession(id) {
                    this.activeSessionId = id;
                    this.messages = [];
                    this.loading = true;

                    try {
                        const resp = await axios.post('api/agent_chat.php', {
                            action: 'get_messages',
                            session_id: id,
                            csrf_token: this.csrfToken,
                        });

                        if (resp.data.success) {
                            this.messages = resp.data.messages.map(m => ({
                                role: m.role,
                                content: m.content,
                                tool_trace: m.tool_results ? [{ name: JSON.parse(m.tool_results)?.tool_name, success: true, elapsed_ms: 0 }] : [],
                                proposal: null,
                            }));
                        }
                    } catch (e) {
                        console.error('Load session failed:', e);
                    }

                    this.loading = false;
                    this.$nextTick(() => this.scrollToBottom());
                },

                async newChat() {
                    this.messages = [];
                    this.activeSessionId = null;
                    this.input = '';
                },

                async deleteSession(id) {
                    if (!confirm('Delete this conversation?')) return;
                    try {
                        const resp = await axios.post('api/agent_chat.php', {
                            action: 'delete_session',
                            session_id: id,
                            csrf_token: this.csrfToken,
                        });
                        if (resp.data.success) {
                            this.sessions = this.sessions.filter(s => s.id !== id);
                            if (this.activeSessionId === id) {
                                this.messages = [];
                                this.activeSessionId = null;
                            }
                        }
                    } catch (e) {
                        alert('Delete failed: ' + (e.response?.data?.error || e.message));
                    }
                },

                async approveProposal(decisionId, autoApply) {
                    try {
                        const resp = await axios.post('api/agent_approve.php', {
                            action: autoApply ? 'approve_apply' : 'approve',
                            decision_id: decisionId,
                            csrf_token: this.csrfToken,
                        });

                        if (resp.data.success) {
                            this.messages.push({
                                role: 'assistant',
                                content: '✅ Proposal #' + decisionId + ' ' + (autoApply ? 'approved and applied!' : 'approved. You can activate it from the Dashboard.'),
                                tool_trace: [],
                                proposal: null,
                            });

                            this.pendingDecisions = this.pendingDecisions.filter(d => d.id !== decisionId);
                        }
                    } catch (e) {
                        alert('Approval failed: ' + (e.response?.data?.error || e.message));
                    }
                },

                async rejectProposal(decisionId) {
                    try {
                        const resp = await axios.post('api/agent_approve.php', {
                            action: 'reject',
                            decision_id: decisionId,
                            feedback: 'Rejected by user',
                            csrf_token: this.csrfToken,
                        });

                        if (resp.data.success) {
                            this.messages.push({
                                role: 'assistant',
                                content: '❌ Proposal #' + decisionId + ' rejected.',
                                tool_trace: [],
                                proposal: null,
                            });
                            this.pendingDecisions = this.pendingDecisions.filter(d => d.id !== decisionId);
                        }
                    } catch (e) {
                        alert('Reject failed: ' + (e.response?.data?.error || e.message));
                    }
                },

                sendPreset(text) {
                    this.input = text;
                    this.sendMessage();
                },

                safeFormatContent(text) {
                    if (!text) return '';
                    let div = document.createElement('div');
                    div.textContent = text;
                    let escaped = div.innerHTML;
                    let html = escaped
                        .replace(/&amp;lt;pre&amp;gt;|```json\s*([\s\S]*?)```/g, '<pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto my-1">$1</pre>')
                        .replace(/```(\w*)\s*([\s\S]*?)```/g, '<pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto my-1">$2</pre>')
                        .replace(/`([^`]+)`/g, '<code class="bg-gray-100 px-1 rounded text-xs">$1</code>')
                        .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
                        .replace(/\n/g, '<br>');
                    return html;
                },

                formatContent(text) {
                    if (!text) return '';
                    let html = text
                        .replace(/```json\s*([\s\S]*?)```/g, '<pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto my-1">$1</pre>')
                        .replace(/```(\w*)\s*([\s\S]*?)```/g, '<pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto my-1">$2</pre>')
                        .replace(/`([^`]+)`/g, '<code class="bg-gray-100 px-1 rounded text-xs">$1</code>')
                        .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
                        .replace(/\n/g, '<br>');
                    return html;
                },

                scrollToBottom() {
                    const el = this.$refs.messageContainer;
                    if (el) el.scrollTop = el.scrollHeight;
                },

                onScroll() {},
            }));
        });
    </script>
</body>
</html>
