# Fixzy Kriptobot - Single Source of Truth: Workflow Specification

This document is the official **Source of Record** for the entire Fixzy Kriptobot workflow. It is designed following a *Spec-driven Development* approach for both user and developer reference.

---

## Phase 1: Capital Management & Monitoring (IDLE Phase)

When a new bot is created, it always starts with `IDLE` status and begins its market scanning routine.

### 1. Capital Allocation System
The bot never locks capital blindly. The actual allocation is only determined when a Buy Signal is detected.
*   **AUTO (True Free Allocation):** 
    *   The system checks the **True Free Balance** (Wallet Balance - reserves for active DCA bots).
    *   It takes 90% of that free balance and splits it equally among the bots with `IDLE` status.
    *   This capital is treated as the **Total Capital** (including the budget for all DCA steps).
*   **Custom (Hardcoded):** The user sets a fixed USDT amount for the bot.
*   **Geometric Base Order:** Whether AUTO or Custom, the bot splits the capital using a geometric series formula so that: `Base Order + All DCA = Total Capital`. This guarantees the bot never runs out of capital (*No DCA Starvation*).

### 2. Market Scanning Strategy (Radar)
The bot supports multiple coin selection methods:
*   **Single Pair:** Focus exclusively on one coin.
*   **Custom List:** Scan several selected coins (e.g., BTC, ETH, SOL) in round-robin order.
*   **Top Volume / Volatility:** Read the list of trending coins directly from the exchange API using `MarketScannerService`.

### 3. *Composite Bot* & Clone Architecture
Every bot operates as a *Parent Bot*. 
*   When `Max Active Deals > 1` (Composite Bot), the Parent Bot locks its capital allocation (`locked_auto_capital`) as soon as the first trade starts.
*   The Parent Bot spawns a **Clone Bot (child)** for each trade of a different coin.
*   The Parent Bot stays in `IDLE` status to keep monitoring signals for other coins, but it **will not change its capital allocation** (no AUTO recalculation) until all of its children have finished trading.
*   Once all children are finished and deleted (0 child deals), the Parent Bot is considered truly free and will recalculate a new capital allocation on the next signal.

---

## Phase 2: Base Order Execution

### 1. Trade Start Conditions (Logical AND) & Multi-Timeframe
For the bot to trigger a Base Order purchase, it must satisfy **all** of the selected technical signals simultaneously. Fixzy Kriptobot provides full flexibility:
*   **Flexible Timeframes:** You can set the RSI signal on the `15m` timeframe while Bollinger Bands use `1h`. The bot automatically fetches both datasets.
*   **Custom Parameters:** You can change the RSI 'Length' (e.g., RSI 7 vs 14) or the Bollinger 'Period' individually for each bot.
*   **Logical AND:** The absence of a single signal cancels the purchase.

### 2. Start Order Type: Try Limit 1st (Fallback to Market)
Fixzy Kriptobot introduces hybrid intelligence to save on fees without missing opportunities:
*   **Market Order:** Executes immediately at the best available price.
*   **Try Limit 1st:** The bot tries to place a *Limit Order* at the current price. If the order is still unfilled by the next 10-second cycle (*next tick*), the bot automatically cancels the limit and buys immediately with a *Market Buy*. This guarantees position entry within a maximum of 10 seconds.

### 3. Buy Cooldown
After a Base Order is executed, the coin is placed on a "cooling watch list" for the configured duration in seconds to prevent repeated consecutive purchases of the same coin due to volatility.

---

## Phase 3: Market Defense (Smart Safety Orders / DCA)

When the market price falls below the Average Entry Price, the bot starts the Martingale strategy:

### 1. Trigger Points (Drop & Step Scale)
*   **Price Drop Trigger:** Minimum percentage drop (e.g., -5%).
*   **Step Scale:** Multiplier for the distance to the next DCA (e.g., the second DCA is not at -10% but possibly at -12% if Step Scale = 1.2). This widens the *safety net* spacing when the market collapses.

### 2. Capital Multiplication (Volume Scale)
The size of each safety order is multiplied (e.g., x1.5) from the previous order. This aims to pull the *Average Entry Price* much lower, aggressively toward the market price.

### 3. Smart Averaging & Trailing DCA
Fixzy Kriptobot does not DCA blindly:
*   **Custom DCA Conditions:** The bot requires the -5% drop as the *minimum depth*, but it only executes if the chosen *Indicator* (such as RSI or QFL) also agrees.
*   **Trailing Safety Orders:** Just like *Trailing Buy*, if the price crashes beyond -5%, the bot tracks the falling price (via `TrailingEngine`) until it finds the absolute low before making the DCA purchase on the rebound.
*   *(Note: If this smart feature is enabled, the "Place on Exchange" limit DCA orders are cancelled, forcing the bot to hide behind the exchange and track the market 'live'.)*

---

## Phase 4: Risk Management (Take Profit & Cut Loss)

The harvest phase where all trades are settled.

### 1. Target Profit Logic (TP Types)
*   **% from Average Price:** If set to 10%, the bot seeks 10% profit on the entire position size (Total Volume).
*   **% from Base Order Price (3Commas-style logic):** The bot dynamically scales the target percentage. If DCA has accumulated a very large capital, the target % is dropped sharply so that *Take Profit* can be executed quickly and yield the same USDT value as a 10% profit from the *Base Order*.

### 2. Scale Out (Partial Sell)
To optimize uncertain returns, profits can be harvested in stages (for example, sell 33% of the coins at TP 1%, then sell another 33% at TP 2%).

### 3. Trailing Take Profit
Prevents the bot from selling too early during a sudden upward "PUMP" phase. The bot starts recording the highest point (*High Watermark*) after passing the Target Profit, and only sells when the price plunges (*deviation drop*).

### 4. Custom Sell (Logical OR) & Minimum Profit Guard
The bot can be forced to exit by external signals such as a TradingView Webhook or AI immediately, regardless of the PNL loss amount, as long as any one of the signals is present (**Logical OR**).
*   **Minimum Profit Guard:** Cancels the indicator-driven sell if the position is currently at a loss, as an anti-cut-loss protection (unless it is *overridden* by the configured *Timeout* period).

### 5. Cut Loss & Trailing SL
As the last-resort mechanism if the market cannot be rescued by DCA:
*   **Hard Cut Loss:** Forced sell when the overall PNL reaches a fixed loss level.
*   **Trailing Stop Loss:** The protection floor is raised if the price has ever risen above the capital, minimizing losses or guaranteeing a small profit (*break-even*).

---

## Global Guardrails & Institutional Filters

An additional protection layer applied to all bots to prevent human error or excessive risk exposure.

### 1. Master Toggle (Global Activation)
*   If the status is changed to `DISABLED`, the system ignores all the additional rules below and follows only each bot's individual settings.

### 2. Global Blacklist (Absolute Ban)
*   Any coin listed here is completely blocked from being bought by any bot, even if that bot's indicators give a `BUY` signal. This is important for avoiding problematic coin projects (scam/delisted).

### 3. Global Base Buy & DCA Filters (Smart Gatekeeper)
*   Acts as an **Additional Mandatory Condition**. 
*   Example: If you set `RSI < 30` as a Global Filter, any bot wanting to buy a coin (Base Order or DCA) must pass both its own bot condition AND this Global RSI condition simultaneously.

### 4. Global Sell Rules (Emergency Exit)
*   Acts as an **OR Condition (Optional)**. 
*   If any global condition is met (e.g., News AI suddenly detects negative market sentiment), all active bots are triggered to sell their respective positions immediately without waiting for the individual bot's target profit to be reached.

---

## Phase 5: Universal Smart Recovery (Universal Rescue Engine)

An automatic emergency routine designed to recover absolute losses from *Cut Loss*.

### 1. Trigger & Isolation (Quarantine Protocol)
*   When a *Cut Loss* occurs, the system checks the user's `smart_recovery_mode` setting.
*   If active, the system calculates the safe capital balance and the total net loss (USD).
*   The system automatically performs a **Quarantine**: it shuts down (*Graceful Shutdown*) all other bots that are IDLE to avoid wasting capital on new coins. Bots that are ACTIVE are allowed to finish their sells before being auto-shut down.

### 2. Building the Recovery Bot
*   A dedicated bot is created named `RECOVERY MODE`.
*   **Conservative Strategy:** The bot is forced to use `RSI < 30` AND `Bollinger Lower` as entry conditions.
*   **Aggressive DCA:** 5 safety steps with a 1.5x multiplier every 2% price drop.
*   **Small Target Profit:** TP is set to 1.0% with no trailing to ensure quick profit harvesting.
*   **Cut Loss Forbidden:** This bot is not allowed to Cut Loss. It keeps DCA-ing until profit is reached.

### 3. Staged Deficit Harvesting (Merging & Clearing)
*   Every profit earned by the Recovery Bot is deducted from `deficit_to_recover`.
*   **Multiple Cut Loss:** If a second Cut Loss occurs while the Recovery Bot is still active, the new debt is merged and the remaining capital of that bot is injected into the Recovery Bot's capital.
*   **Resolution:** As soon as the debt reaches $0, the Recovery Bot deletes itself and wakes up all the user's bots that were previously suspended.

---

## Phase 6: Backtesting (Strategy Simulation)

Backtesting lets you test a trading strategy against historical data before committing real capital. Fixzy Kriptobot uses the **exact same live trading engine** in simulation mode — ensuring every bug caught in a backtest also protects your live trading.

### 1. Backtest Engine Architecture
BacktestEngine (`src/Backtest/BacktestEngine.php`) fully reuses the live trading components:
* **`BaseOrderConditionEngine`** — Evaluates Base Order entry conditions (RSI, Bollinger, QFL, Start ASAP).
* **`DcaConditionEngine`** — Evaluates Safety Order conditions including *Smart Averaging* and *Trailing DCA*.
* **`ExecutionGuardService`** — Enforces cooldown, max active deals, and blacklist during simulation.
* **`AuditLogger`** — Records every action (BUY, SELL, DCA, RULE_EVAL, REJECTION) to `audit_logs` and `trade_logs`.

### 2. Simulation Cycle (State Machine)
The simulation runs candle-by-candle using 1-minute OHLCV data:

| State | Description |
|-------|------------|
| `IDLE` | The engine evaluates Base Order conditions on every candle. If passed → enter `TRAILING_BUY` or go straight to `ACTIVE`. |
| `TRAILING_BUY` | The price is trailed downward via `TrailingEngine`. The buy is only executed when the price rebounds by the *trailing deviation* from the lowest level. |
| `ACTIVE` | A deal is running. The engine monitors PNL, DCA, Cut Loss, and Take Profit. |
| `TRAILING_TP` | After Take Profit is reached, `TrailingEngine` tracks the price upward. The sell is executed when the price falls by the *trailing deviation* from the peak. |

### 3. DCA Flow in Backtest
When the price falls past the *Price Drop Trigger*:
1. `DcaConditionEngine` checks the cumulative price drop (Martingale Step Scale).
2. If **Smart Averaging** is enabled, additional indicator conditions (RSI, Bollinger, QFL) are evaluated.
3. If **Trailing DCA** is enabled, the bot tracks the falling price and only buys when the price rebounds.
4. The DCA size is calculated with the formula `BaseOrder × VolumeScale^(Step+1)` — same as the live bot.

### 4. Data Ingestion
* 1-minute OHLCV data is downloaded from **Binance Vision Public Data** via `BinanceDataIngester`.
* Data is stored in the `historical_ohlcv` table with a unique index on `(symbol, open_time)`.
* The Backtest UI in `settings.php` lets you check data availability, pull new data, and run backtests.

### 5. Backtest Limits & Limitations
| Limitation | Impact |
|----------|-------|
| **No slippage** | Buy/sell prices are assumed to be exactly at the candle *close* price. |
| **No webhook/AI signals** | `tv_webhook`, `external_signal`, and `news_sentiment` signals are filtered out because there is no historical data. Only technical signals are evaluated. |
| **Default fee 0.1%** | Can be changed in the backtest settings. |
| **Current audit timestamp** | The recorded time is the current server time, not the simulation time (simulation data is stored in the `context_data` JSON). |

### 6. Audit Logging in Backtest
Every action is recorded for full traceability:
* `BACKTEST_BUY` / `BACKTEST_DCA_BUY` — Base Order and DCA purchases.
* `BACKTEST_TAKE_PROFIT` / `BACKTEST_CUT_LOSS` — Sells.
* `RULE_EVAL` — Result of every indicator evaluation (pass/fail).
* `DCA_EVAL` — DCA evaluation details (price drop, additional conditions).
* `REJECTION` — When the Execution Guard blocks a purchase (cooldown, max deals, blacklist).

---

*(The system returns to NORMAL status after the Recovery process is fully complete)*

---

## Phase 7: AI Agentic System (Strategy Engineer & Bot Architect)

The AI Agent acts as an automatic **Strategy Engineer** and **Bot Architect**. The user only needs to provide trading objectives in natural language, and the AI will analyze, backtest, and propose the optimal bot configuration.

### 1. Architecture Overview

```
User Input (Natural Language)
        │
        ▼
AgentOrchestrator (Agent Loop)
        │
   ┌────┼────┐
   ▼    ▼    ▼
  LLM  Tools Registry
       (14 tools)
   │        │
   └────────┘
        │
        ▼
Proposal Config → Approval → Bot Created
```

### 2. Main Workflow

When the user gives a command like *"Create a low-risk trading bot for BTC/USDT"*, the agent goes through the following steps:

1. **Objective Analysis** — `RiskProfiler` maps the user's keywords to a risk profile (Conservative / Moderate / Aggressive / Scalping).

2. **Market Analysis** — The agent calls the analysis tools:
   - `analyze_market` — Current price, volume, market scan
   - `analyze_trend` — Trend direction across multiple timeframes (1h, 4h, 1d)
   - `analyze_volatility` — Volatility level and parameter suggestions
   - `analyze_technical` — RSI, Bollinger Bands, QFL indicators
   - `analyze_sentiment` — Market sentiment via news and AI

3. **Simulation & Backtesting** — The agent runs a backtest with the proposed configuration using the same `BacktestEngine` as the live bot.

4. **Optimization** — `optimize_strategy` performs a grid search over parameters (target profit, cut loss, DCA steps, volume scale, trailing) and returns the 5 best configurations based on Win Rate, Sharpe Ratio, and Total PNL.

5. **Configuration Generation** — `ConfigGenerator` builds the full bot configuration in JSON format.

6. **Proposal Presentation** — The agent presents the proposal along with:
   - Full bot configuration (JSON)
   - Justification for each parameter choice
   - Backtest results
   - Old vs new configuration comparison (if updating)

7. **User Approval** — The user can:
   - **Approve & Activate** — The bot is created and activated immediately
   - **Approve Only** — The bot is saved as DRAFT
   - **Reject** — The proposal is discarded with feedback
   - **Revise** — The user edits the configuration before approving

### 3. Tool Calling Architecture

The agent uses **Tool Calling** (OpenAI-compatible) to interact with the system:

| Tool | Category | Description |
|------|----------|------------|
| `analyze_market` | Market Analysis | Price, volume, top coins |
| `analyze_technical` | Technical Analysis | RSI, Bollinger, QFL |
| `analyze_sentiment` | Sentiment | News + AI |
| `analyze_volatility` | Volatility | ATR, standard deviation |
| `analyze_trend` | Trend | Multi-TF EMA, trend direction |
| `get_historical_data` | Data | Check historical OHLCV |
| `get_trade_history` | Data | Bot's trade history |
| `list_bots` | Data | List of user's bots |
| `get_bot_status` | Data | Detailed bot status |
| `run_backtest` | Backtesting | Strategy simulation |
| `optimize_strategy` | Backtesting | Parameter grid search |
| `create_bot` | Bot Management | Create a new bot (DRAFT) |
| `update_bot_config` | Bot Management | Update configuration |
| `activate_bot` | Bot Management | Activate a bot |
| `delete_bot` | Bot Management | Deactivate a bot |
| `monitor_bot` | Bot Management | Monitor live status |

### 4. Autonomy Modes

| Mode | Description |
|-----|------------|
| **Approval Required** | AI proposes, and the user approves before any changes are applied. A Telegram notification is sent for each proposal. |
| **Full Autonomy** | AI automatically executes changes within the configured capital limits. Suitable for users who fully trust the AI. |

### 5. Telegram Integration

When Approval Required is enabled:
- Each new proposal sends a Telegram notification with a configuration summary.
- The user can reply directly in Telegram:
  - `/approve <id>` — Approve and apply the proposal
  - `/reject <id> [reason]` — Reject the proposal
  - `/review <id>` — Review the proposal details

### 6. Auto-Optimization (Cron)

The system can be scheduled to monitor bot performance periodically (every 6/12/24 hours):
1. Check all bots with `is_agent_managed = 1`
2. Analyze recent trading performance (win rate, PNL, drawdown)
3. If performance degrades → generate an improvement proposal
4. Save as `agent_decisions` (pending_approval)
5. Send Telegram notification

### 7. Settings & Controls

All AI Agent settings can be managed under **Global Settings > AI Agentic System**:
- Enable/Disable AI Agent
- Autonomy Mode (Approval Required / Full Autonomy)
- Capital Limits (max per bot, max total)
- Allowed Actions (granular permissions for each action type)
- Telegram Notifications (enable/disable)
- Language Preference (Auto-Detect / Bahasa Melayu / English)
