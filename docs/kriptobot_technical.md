# Fixzy Kriptobot - Single Source of Truth: Technical Specification

This document is the technical **Source of Record** outlining how `bot_daemon.php` processes data, the database state structure, and the mathematical formulas that guide the system.

---

## 1. State Management (Database Schema)

Every bot (whether a *Parent* or a *Clone*) uses the `runtime_state` column (JSON) in the `bots` table to track its current status (*persistence*).

**Core JSON Schema (`runtime_state`):**
*   `status`: Bot status (String) -> `IDLE` (Resting), `WAITING_LIMIT` (Waiting for Limit Order / Try Limit 1st), `ACTIVE` (Holds a position).
*   `current_holdings`: Amount of coins currently held (Float).
*   `average_entry_price`: Current DCA average price (Float).
*   `base_order_price`: Original Base Order price (Float) - Important for 3Commas-style TP calculation.
*   `base_order_volume_usdt`: Original USDT volume for the Base Order (Float).
*   `dca_current_step`: Number of completed DCA steps (Integer).
*   `trade_start_timestamp`: Trade start time (Unix Timestamp).
*   `partial_sell_executed`: Array storing the indices of targets already sold (Array of Integer).

### 2. Start Order Type: Try Limit 1st (Fallback to Market)
Fixzy Kriptobot introduces a hybrid cleverness to save fees without missing opportunities:
*   **Market Order:** Executes immediately at the best price.
*   **Try Limit 1st:** The bot attempts to place a *Limit Order* at the current price. If within the next 10-second cycle (*next tick*) the order is still unfilled, the bot automatically cancels the limit and buys using a *Market Buy* instead. This guarantees position entry within a maximum of 10 seconds.

### 3. Trailing Buy (Reversal Deferral)
*   `trailing_symbol`: The coin symbol currently being tracked for Trailing Buy.
*   `trailing_low_watermark`: The lowest floor price during Trailing Buy/DCA.
*   `trailing_tp_watermark`: The highest peak price during Trailing Take Profit.
*   `trailing_sl_watermark`: The highest peak price for Trailing Stop Loss.

---

## 2. Engine Evaluation Order (Daemon Evaluation Order)

The `bot_daemon.php` script is driven by a *Cronjob* and protected by a *Lock File* (`bot_daemon.lock`) to prevent overlapping processes. On each cycle, evaluation happens hierarchically:

### Pre-Evaluation Block (Centralized Data Hub)
To overcome API Rate Limit (429) issues and high latency when running hundreds of bots/coin pairs, the daemon performs *Batch Fetching*:
* Pulls **all market prices (Tickers)** in bulk at the start of the tick and stores them in **APCu (Shared Memory)**.
* Bots read price data directly from RAM. Access time <1ms, saving tens of seconds.

### Block A: Active Deal Management (Already Bought)
If `current_holdings > 0`:
1.  **Update Current PNL:** `pnlPercentage = ((currentPrice - average_entry_price) / average_entry_price) * 100`
2.  **Partial Sell Evaluation (Scale Out):** 
    *   Reads the array of decimal targets.
    *   If `pnlPercentage >= target[i]` and not yet in `partial_sell_executed`, execute a staged *Market Sell*.
3.  **Main Take Profit Evaluation:**
    *   Computes `dynamicTargetProfit` (See Section 3).
    *   If the target is exceeded, check **Trailing Take Profit**. If none, sell immediately and switch status to `IDLE`.
4.  **Custom Sell Evaluation (Logical OR):**
    *   As long as a single indicator (RSI, Webhook, AI) gives a 'SELL' signal.
    *   Filtered by `MinimumProfitGuardRule.php`.
5.  **Cut Loss & Trailing SL Evaluation:**
    *   Monitors the drop in `pnlPercentage` or the dynamic calculation from `trailing_sl_watermark`.
6.  **Safety Orders (DCA) Evaluation:**
    *   Check the Limit Tracker (if placed on the exchange) OR live monitoring.
    *   Send data to `DcaConditionEngine.php` to check *Price Drop*.
    *   If Smart Averaging is active, check Indicator signals (candle history / OHLCV is also fetched with the Lazy-Load APCu technique).

### Block B: New Deal Management (IDLE Phase)
If `status === 'IDLE'`:
1.  Check target coins using `MarketScannerService.php`.
2.  Evaluate `BaseOrderConditionEngine.php` (Logical AND).
3.  If conditions pass, start a `Trailing Buy` OR cut capital immediately and execute the Base Order. 
    *   If using **Try Limit 1st**, the bot books a Limit Order and switches to `WAITING_LIMIT`.
    *   If in the next *tick* cycle (10s) the order is still unfilled, the system cancels the limit and executes a *Market Buy* (Fallback to Market).
4.  Spawn a `Clone Bot` in the `bots` table if `Max Active Deals > 1` to continue the task.
---

## 3. Advanced Math Architecture (3Commas Logic)

### Dynamic Take Profit Formula (Base Order Type)
Fixzy Kriptobot adapts the industry standard (3Commas) where "Take Profit % from Base Order" does not count the *entry* price change, but manipulates the overall percentage target so the USDT profit always stays equal to the Base Order's.

```php
$totalUsdtVolume = $state['current_holdings'] * $state['average_entry_price'];
$baseOrderUsdt = $state['base_order_volume_usdt'];

$dynamicTargetProfit = $targetProfitSetting;

if ($tpType === 'base_order') {
    // If capital was enlarged by DCA, the PNL% profit target is lowered
    $dynamicTargetProfit = $targetProfitSetting * ($baseOrderUsdt / $totalUsdtVolume);
}

// Execute if:
if ($pnlPercentage >= $dynamicTargetProfit) {
    // Take Profit
}
```

### Auto Capital Formula (True Free Allocation)
To ensure capital is spread logically without collisions and without risking theft of existing DCA bots' funds, the system uses the true free balance calculation (*True Free Balance*):

1. **Reserved DCA Funds:** The budget already allocated to active DCA bots but not yet spent.
   `$reservedDcaFunds = SUM(total_deal_budget - current_spent) for all ACTIVE bots.`

2. **True Free Balance:** Exchange balance minus DCA reserves.
   `$trueFreeUsdt = $liveExchangeBalance - $reservedDcaFunds;`

3. **Dynamic Split (90% Rule):**
   `$fairCapital = ($trueFreeUsdt * 0.90) / $idleBotsCount;`

*   **Important:** An IDLE bot is only counted if it has no active deal whatsoever (including child bots of a Composite Bot).
*   **Locked Capital:** A Composite Bot locks this `$fairCapital` value into `locked_auto_capital` on the first transaction for use by all subsequent deals in the same cycle.

---

## 4. Base Order Math (No DCA Starvation)

KriptoBot does not use the entire allocated capital as the Base Order. The system splits the capital mathematically using a **Geometric Series** so that the allocation for the Base Order and all DCA steps is sufficient from the start.

**Base Order Calculation Formula ($B$):**
Given $C$ (Total Deal Budget), $V$ (Volume Scale), and $N$ (Max Safety Steps):

$$B = \frac{C}{1 + V^1 + V^2 + ... + V^N}$$

**Operational Example:**
If Deal Capital = $100, Max DCA = 3, Volume Scale = 1.5:
1. Multiplier Sum = $1 + 1.5^1 + 1.5^2 + 1.5^3 = 8.125$
2. Base Order ($B$) = $100 / 8.125 = \$12.30$
3. DCA Allocation:
   * DCA 1 (1.5x) = $18.45
   * DCA 2 (2.25x) = $27.67
   * DCA 3 (3.375x) = $41.51
   * **Total:** $12.30 + $18.45 + $27.67 + $41.51 = **$99.93 (~$100)**

This ensures the bot will not hit an *Insufficient Funds* error in the middle of a price crash.

---

## 6. Backtesting Engine (BacktestEngine)

Fixzy Kriptobot provides a backtesting engine that uses **the exact same trading engine code** as the live daemon. This means a backtest is not merely a simulation — it directly validates the accuracy of the signal code.

### 1. Architecture & Dependency Injection
`BacktestEngine` receives all four dependencies through the constructor:

```php
public function __construct(
    BinanceDataIngester $ingester,
    AuditLogger $auditLogger,
    ExecutionGuardService $executionGuard,
    Connection $db
)
```

Each component operates in simulation mode:
* **`BaseOrderConditionEngine`** — Evaluates `start_conditions` for each candle. External signals (`tv_webhook`, `external_signal`, `news_sentiment`) are filtered out because there is no historical data for them.
* **`DcaConditionEngine`** — Evaluates DCA conditions including *custom conditions* and *trailing DCA*.
* **`ExecutionGuardService::canBuyForBacktest()`** — Enforces cooldown and max active deals using the simulation timestamp (not a direct `trade_logs` query).
* **`AuditLogger`** — Records every action to `audit_logs` and `trade_logs` for full traceability.

### 2. Base Order Formula (Shared)
`BacktestEngine` calls the same static method as the live bot to compute the Base Order size:

```php
OrderManager::calculateBaseOrderSize($totalDealBudget, $maxDcaSteps, $volumeScale);
```

Formula:
$$B = \frac{C}{1 + V^1 + V^2 + ... + V^N}$$

This ensures the size calculation in backtests is **identical** to live trading.

### 3. Simulation State Machine
The main simulation loop (`simulate()`) processes each candle through the following state machine:

```
IDLE ──(signal triggers + guard passes)──> TRAILING_BUY (if trailing active)
  │                                           │
  │                                    (bounce triggers + guard passes)
  │                                           │
  └──(signal triggers + guard passes)──> ACTIVE <──┘
  (buy directly if no trailing)         │
                                      ┌─────┼─────┐
                                      ▼     ▼     ▼
                                   CUT_LOSS  TP   DCA
                                      │     │     │
                                      └─────┴─────┘
                                          ▼
                                        IDLE
```

### 4. Simulation Execution Guard
`canBuyForBacktest()` accepts simulation parameters directly:
* `$simulatedNow` — Current candle timestamp (seconds).
* `$lastTradeTimestamp` — Last trade timestamp in the simulation (updated on SELL).
* `$currentActiveDeals` — Current number of active deals (incremented on BUY, decremented on SELL).

This avoids irrelevant `trade_logs` and `bots` queries in the simulation context.

### 5. Backtest Data Pipeline
```
UI (settings.php) ──> backtest_run.php ──> BinanceDataIngester
                                               │
                                    ┌──────────┴──────────┐
                                    ▼                      ▼
                            fetchCandles()           getDataStatus()
                            (MySQL query)            (availability check)
                                    │
                                    ▼
                              BacktestEngine::simulate()
                                    │
                          ┌─────────┼─────────┐
                          ▼         ▼         ▼
                   BaseOrderEngine  DcaEngine  TrailingEngine
                          │         │         │
                          └─────────┼─────────┘
                                    ▼
                            AuditLogger (DB + file fallback)
```

### 6. Backtest Database Tables
| Table | Purpose |
|--------|----------|
| `historical_ohlcv` | 1-minute OHLCV data from Binance Vision. Unique index `(symbol, open_time)`. |
| `backtest_ingestion_log` | Data download process log (per-date status). |
| `audit_logs` | Backtest audit log (RULE_EVAL, DCA_EVAL, REJECTION). |
| `trade_logs` | Backtest transaction log (BACKTEST_BUY, BACKTEST_DCA_BUY, BACKTEST_TAKE_PROFIT, BACKTEST_CUT_LOSS). |

---

## 5. Safety Controllers (Rules Engine)

*   **`TrailingEngine.php`:** Stateless standalone module. Has two pure functions (`evaluateTrailingBuy` and `evaluateTrailingSell`) that only record and compare the *deviation percentage* from the `$watermark` parameter.
*   **`MinimumProfitGuardRule.php`:** Combines a price check and a time check (`time() < $startTimestamp + $timeoutSeconds`). It returns `false` (blocks the sale) if the compromise condition has not yet been reached.
*   **`ExecutionGuardService.php`:** Acts as an outer layer, blocking buy attempts if the `Buy Cooldown` for a specific coin has not finished its cooling period. Supports simulation mode via `canBuyForBacktest()` for backtesting.

---

## 5. Universal Smart Recovery Architecture

This logic is enabled only if `smart_recovery_mode = 1` in the user profile.
 
 ### 1. Recovery State Schema
 *   `is_recovery_bot` (Boolean): Marker flag for a special bot.
 *   `deficit_to_recover` (Float): The absolute USD amount that must be repaid (the Debt).
 *   `recovered_so_far` (Float): The USD amount already successfully harvested from TP.
 
 ### 2. PnL & Merging Logic
 When a Recovery Bot takes profit, the remaining debt calculation is performed:
 ```php
 $profitUsdt = $totalUsdtVolume * ($pnlPercentage / 100);
 $state['recovered_so_far'] += $profitUsdt;
 $remainingDeficit = $state['deficit_to_recover'] - $state['recovered_so_far'];
 ```
 
 If an additional Cut Loss occurs:
 1. The system finds the bot with `is_recovery_bot = true`.
 2. The existing bot's `deficit_to_recover` is increased by the new loss amount.
 3. The bot's `allocated_capital` is increased by the remaining capital of the newly sunk bot.
 
 ### 3. Quarantined Status Protocol
 The `recoveryUsers[]` memory map is built on every tick. If `userId` exists in the map:
 *   **Situation A (Active):** Continue TP/DCA. When finished, set `status = 0`.
 *   **Situation B (IDLE):** Immediately set `status = 0` (Blocked from buying new coins).
 
 ### 4. Automatic Configuration (Hardcoded Strategy)
 Recovery Bots do not use the user's manual settings; instead they use a fixed configuration optimized for safety:
 *   **Minimum Capital:** `MAX(cut_loss_balance, $15)`.
 *   **DCA Steps:** 5 steps with a `1.5x` volume multiplier (Martingale).
 *   **DCA Distance:** Fixed at `2.0%` price drop.
 *   **Smart Entry:** Forced `RSI < 30` AND `Bollinger Lower` for both Base Order and DCA.
 *   **Take Profit:** Fixed `1.0%` (No Trailing) to minimize the risk of price reversals.
 
 ---
 
 ---
 
 ## 6. Global Guardrails Technical Specification
 
 This system is integrated as an outer layer (*wrapper*) before individual bot logic is processed.
 
 ### 1. Blacklist Processing (String-to-Array)
 To reduce user input errors, the system automatically converts input text into an array:
 ```php
 $blacklist = array_map('trim', explode(',', strtoupper($blacklistStr)));
 ```
 Checks are performed against the `base_symbol` (e.g. BTC) and the `full_pair` (e.g. BTC/USDT).
 
 ### 2. Pre-Buy Logic (Global Gatekeeper)
 Before an individual bot's `BaseOrderConditionEngine` or `DcaConditionEngine` is called, the system runs a check:
 ```php
 if ($isGlobalFilterEnabled && !empty($globalFilters['base_buy'])) {
     $globalEngine = new BaseOrderConditionEngine(['conditions' => $globalFilters['base_buy']], $state, $candles);
     if (!$globalEngine->evaluate()) return false; // Block the buy
 }
 ```
 
 ### 3. Forced Sell Logic (Global OR Merge)
 For sell functions, the system merges the global conditions into the bot's condition array:
 ```php
 if ($isGlobalFilterEnabled && !empty($globalFilters['sell'])) {
     $customSellConditions = array_merge($customSellConditions, $globalFilters['sell']);
 }
 ```
 This allows a global emergency signal to bypass (*override*) the bot's take-profit target.

---

## 7. Multi-Timeframe TA Engine & Dynamic Parameters

The system now supports full flexibility for technical indicators (RSI, Bollinger, QFL), where each bot can have its own timeframe and parameters.

### 1. Unique Timeframe Aggregator
In `bot_daemon.php`, before the evaluation phase begins, the system scans all conditions (Base, DCA, Global) to collect the list of unique timeframes:
```php
$taIndicators = ['rsi', 'bollinger', 'qfl', 'rsi_14'];
foreach ($allConfigConds as $cond) {
    if (in_array($cond['type'], $taIndicators)) {
        $tf = $cond['timeframe'] ?? '1h';
        $requiredTimeframes[$tf] = true;
    }
}
```
OHLCV data is fetched in *batches* per unique timeframe to minimize API usage.

### 2. Candles Data Structure
The `$candles` variable is no longer a single flat array, but an associative array:
*   `$candles['1h']` -> [1-Hour OHLCV]
*   `$candles['15m']` -> [15-Minute OHLCV]

### 3. Dynamic Evaluation (BaseOrderConditionEngine)
The evaluation engine picks the correct data based on the condition configuration:
*   **RSI:** Uses `timeframe` and `period` (default 14).
*   **Bollinger:** Uses `timeframe`, `period` (default 20), and `stddev` (default 2.0).
*   **QFL:** Uses `timeframe` to locate the *Fractal Base*.

---

---

## 8. Telegram Notification System (Per-User Logic)

KriptoBot uses a smart notification system that sends timely information to the right user.

### 1. Trigger Events
Notifications are sent automatically when:
*   **Trade Opened (Base Order Open):** Buy price, coin amount, and overall budget information.
*   **Order Filled:** When a *Limit Buy* (Base or DCA) is successfully executed on the exchange.
*   **DCA Action (Safety Orders):** When the bot adds capital to lower the average price.
*   **Sale/Profit (Take Profit):** The profit percentage and deal closure.
*   **Recovery Mode:** Notifications for emergency mode activation, debt repayment progress, and recovery success.
*   **API Errors:** Buy failures or exchange connection errors.

### 2. Routing Logic
The system does not use a single global Chat ID. Instead:
1.  The bot fetches `telegram_chat_id` from the `users` table based on the bot owner's `user_id`.
2.  `NotificationService` dynamically updates the delivery target for each bot.
3.  If `telegram_chat_id` is empty, the notification is silently skipped to save resources.

---

*(This document was updated on 2026-05-04 following the integration of the Centralized Data Hub and Per-User Telegram Notifications)*

---

## 9. AI Agentic System (Agent Architecture)

The AI Agent system lets users create, configure, and optimize trading bots through natural language. The agent uses a **tool calling architecture** where the AI can invoke system functions as tools.

### 1. Core Components

| Component | File | Description |
|----------|------|------------|
| `AgentOrchestrator` | `src/Agent/AgentOrchestrator.php` | Main loop: process message → dispatch tool calls → return response |
| `AgentLlmClient` | `src/Agent/AgentLlmClient.php` | AI client (OpenAI-compatible) with native tool calling + ReAct fallback |
| `AgentSession` | `src/Agent/AgentSession.php` | Conversation session and context management |
| `AgentToolRegistry` | `src/Agent/AgentToolRegistry.php` | Tool registration, filtering, and execution |
| `AgentPromptBuilder` | `src/Agent/AgentPromptBuilder.php` | Bilingual (MS/EN) system prompt construction |
| `ApprovalManager` | `src/Agent/Approval/ApprovalManager.php` | Approval flow (approve/reject/modify/apply) + Telegram integration |
| `RiskProfiler` | `src/Agent/RiskProfiler.php` | NLP mapping: risk keywords → parameter profile |
| `ConfigGenerator` | `src/Agent/ConfigGenerator.php` | Full bot config generation from parameters |
| `ConfigComparator` | `src/Agent/ConfigComparator.php` | Configuration comparison (diff) for the UI |
| `StrategyRecommender` | `src/Agent/StrategyRecommender.php` | Strategy recommendations based on market trends |
| `CronAgentOptimizer` | `bin/cron_agent_optimizer.php` | Cron job for periodic auto-optimization |

### 2. Tool Calling Flow (Agent Loop)

```
┌─────────────────────────────────────────────────────────────┐
│ AgentOrchestrator::processMessage()                          │
│                                                              │
│  1. Load session + build messages [system prompt + history]  │
│  2. Get allowed tools based on user agent_settings           │
│  3. Send to LLM with tool definitions                        │
│     ┌──────────────────────────────────────────┐             │
│     │  LLM responds with:                       │             │
│     │  a) tool_calls → execute → append results │             │
│     │  b) text only → final response            │             │
│     └──────────────────────────────────────────┘             │
│  4. Loop (max 10 iterations)                                 │
│  5. Extract proposal if config JSON found                    │
│  6. Create agent_decisions record (pending_approval)         │
│  7. Send Telegram notification if configured                 │
└─────────────────────────────────────────────────────────────┘
```

### 3. Database Schema

| Table | Purpose |
|--------|----------|
| `agent_sessions` | Stores AI agent conversation sessions. Contains `context` (JSON) for the objective summary, risk profile, and selected pairs. |
| `agent_messages` | Every message in a session. Stores `role` (user/assistant/tool/system), `content`, `tool_calls` (JSON), and `token_usage`. |
| `agent_decisions` | Every AI proposal that requires approval. Stores `proposed_config` (JSON), `justification`, `backtest_results` (JSON), `risk_profile` (JSON), and `status` (pending_approval/approved/rejected/modified/applied). |
| `agent_settings` | Per-user AI Agent settings: `agent_enabled`, `autonomy_mode`, `max_capital_per_bot`, `allowed_actions` (JSON), `telegram_notifications`, `language_preference`. |

### 4. Tool Interface Contract

Every tool implements `ToolInterface`:

```php
interface ToolInterface {
    public function getName(): string;
    public function getDescription(string $lang = 'en'): string;
    public function getParameters(): array;      // JSON Schema for LLM function calling
    public function execute(array $params, int $userId): ToolResult;
}
```

`ToolResult` is standardized as a value object:
```php
class ToolResult {
    public bool $success;
    public mixed $data;
    public ?string $error;
    public function toJson(): string;
}
```

### 5. AI LLM Integration (OpenAI-compatible)

Two delivery modes to the LLM:

1. **Native Tool Calling** — Sends the `tools` parameter in the `chat/completions` request. Supported by OpenAI-compatible models with tool calling (DeepSeek v3/v4, GPT-4o, Llama 3.1, etc.).

2. **ReAct Pattern (Fallback)** — Prompt-based tool calling for models without native support. The LLM is instructed to reply in the format:
   ```
   ```tool_call
   {"tool": "analyze_market", "params": {"pair": "BTC/USDT"}}
   ```
   ```

### 6. Risk Profile Mapping

`RiskProfiler` maps natural-language keywords to parameter profiles:

| Keyword | Profile | TP | CL | Max DCA | Volume Scale |
|------------|--------|----|----|---------|-------------|
| "low risk" | Conservative | 1.5% | 10% | 2 | 1.5 |
| "balanced" | Moderate | 3.0% | 15% | 4 | 2.0 |
| "high risk" / "aggressive" | Aggressive | 5.0% | 25% | 6 | 2.5 |
| "scalp" / "fast" | Scalping | 0.8% | 5% | 1 | 1.0 |

### 7. Approval Workflow

```
pending_approval ──> approved ──> applied (bot activated)
      │
      ├──> rejected (with feedback)
      │
      └──> modified (user edits config)
                 │
                 └──> approved ──> applied
```

- **Full Autonomy**: `pending_approval` status → straight to `applied` without user intervention.
- **Approval Required**: Requires user action via the Dashboard UI or a Telegram reply.

### 8. Telegram Webhook Security

The Telegram webhook (`public/webhook_telegram.php`) is protected with:
1. **Webhook Secret** — The `X-Telegram-Bot-Api-Secret-Token` header is checked against `TELEGRAM_WEBHOOK_SECRET` in `.env`.
2. **Replay Protection** — `update_id` is checked via APCu to prevent duplicate processing.
3. **chat_id Validation** — Numeric format is verified (regex `^-?\d+$`).

### 9. Cron Auto-Optimization

Invoked via system cron:
```
0 0,6,12,18 * * * php /path/to/bin/cron_agent_optimizer.php
```

Process:
1. Check all users with `agent_settings.agent_enabled = 1` AND `allowed_actions` containing `update_config`.
2. For each bot with `is_agent_managed = 1`:
   - Compute trading performance (7 days)
   - If win rate < 50% OR total PNL negative OR cut loss ≥ 2 → generate an optimization proposal
   - Send the prompt to the AI with the performance data
   - Save the proposal as `agent_decisions` (pending_approval)
   - If Full Autonomy → auto-apply
   - Send a Telegram notification

### 10. Agent Security & Guardrails

1. **Session Isolation** — The agent can only access sessions owned by the user (checked via `user_id` on `agent_sessions`).
2. **CSRF Protection** — All API endpoints (`agent_chat.php`, `agent_approve.php`) require a `csrf_token`.
3. **Capital Limits** — The agent cannot exceed the configured `max_capital_per_bot` and `max_total_capital`.
4. **Granular Permissions** — Users can control each action type: analyze_market, view_data, run_backtest, create_bot, update_config, activate_bot, deactivate_bot.
5. **Audit Trail** — Every tool call and decision is recorded to `agent_messages`, `agent_decisions`, and `audit_logs`.
6. **Rate Limiting** — The agent loop runs a maximum of 10 iterations per message.
7. **Max Iterations** — To avoid infinite loops, each user message is limited to 10 rounds of tool calling.
