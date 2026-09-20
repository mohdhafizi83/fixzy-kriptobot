# Architecture

## Overview

Fixzy Kriptobot is a single-backend trading system. All interfaces (CLI, TUI,
web UI) drive the same core engine and the same SQLite database.

```
┌────────────────────────────────────────────────┐
│                  Interfaces                    │
│  bin/kriptobot (CLI)  ·  bin/kriptobot-tui     │
│  public/*.php (Web UI) · public/api/*.php      │
└───────────────────────┬────────────────────────┘
                        │
┌───────────────────────▼────────────────────────┐
│                Core (src/)                     │
│                                              │
│  Trading/     Engine: rules, DCA, trailing,   │
│               partial sell, recovery, scanner  │
│  Agent/       AI strategy assistant (LLM)     │
│  Backtest/    Historical data + replay        │
│  Intelligence/ News fetchers + cross-verify   │
│  Database/    Repositories, audit logging     │
│  Security/    Encryption, auth                │
│  Config/      .env loader                     │
│  Notifications/ Telegram alerts              │
└───────────────────────┬────────────────────────┘
                        │ Doctrine DBAL 4 (pdo_sqlite)
┌───────────────────────▼────────────────────────┐
│           database/kriptobot.sqlite            │
└───────────────────────┬────────────────────────┘
                        │ CCXT
              ┌─────────▼─────────┐
              │  Binance Testnet  │  (default)
              │  Binance Live     │  (BINANCE_TESTNET=0)
              └───────────────────┘
```

## The Daemon (bin/bot_daemon.php)

The heart of the system. A long-running loop:

1. **Acquire lock** — `flock(LOCK_EX | LOCK_NB)` on `bin/bot_daemon.lock`
   guarantees a single instance.
2. **Tick** — for each enabled bot:
   - Fetch price/tickers (APCu-cached)
   - If the bot has an **active deal**: evaluate sell conditions
     (take-profit, trailing TP/SL, partial sell, cut-loss, DCA triggers)
   - If **idle**: evaluate entry conditions (rule engine, market scanner)
     and place base orders when signals fire
   - Persist runtime state after every tick
3. **Sleep** — 10s when active, 60s when idle (smart polling).
4. **Error handling** — every tick is wrapped in try/catch; failures are
   echoed, audit-logged, and optionally sent to Telegram. The loop never
   dies on a single tick error.
5. **Shutdown** — `try/finally` guarantees the lock is released.

Run it via systemd (`bin/kriptobot-daemon.service`) or cron.

## State Machine

Each bot has a runtime state (JSON in `bots.runtime_state`):

- `IDLE` — no position; scanning for entry signals
- `ACTIVE` — holding a position; managing exits and DCA
- Recovery bots (`is_recovery_bot`) — special IDLE bots that rebuild a
  deficit while normal idle bots are quarantined

Transitions are driven by the rule engine and persisted atomically.

## Rule Engine (src/Trading/Rules/)

Entry/exit conditions are composable rule objects implementing
`RuleInterface`:

- `RsiRule`, `BollingerRule`, `QflRule` — technical indicators
- `PriceDropRule`, `ExternalSignalRule`, `TradingViewRule` — signals
- `SentimentRule` — news-driven
- `LogicalAnd`, `LogicalOr` — combinators
- `MinimumProfitGuardRule`, `CooldownRule`, `ExecutionGuardService` —
  safety guards (min profit before sell, cooldown between trades,
  max active deals)

Every rule returns `evaluate(): bool` plus `getContext()` for audit
trails (which indicator value passed/failed).

## AI Agent (src/Agent/)

An AI-powered assistant (any OpenAI-compatible LLM) that can:
- Analyze bot performance and market conditions
- Propose configuration changes (as `agent_decisions` rows)
- Create/update bots — **always behind approval** unless the user
  explicitly enables `full_autonomy` + `update_config` permission

Approval flow: proposal → `pending_approval` → user approves via web UI
or Telegram → applied. Every decision is audit-logged.

## Security Model

- **Encryption at rest**: API secrets encrypted with AES-256-CBC +
  HMAC-SHA256 (encrypt-then-MAC, `v2:` format). Legacy values remain
  readable; new writes are always authenticated.
- **Testnet-first**: `BINANCE_TESTNET=1` default; live requires explicit
  opt-in in `.env`.
- **CSRF**: session-based CSRF tokens on all mutating web endpoints.
- **Webhooks**: shared-secret verification (`hash_equals`) + Telegram
  `update_id` replay protection.
- **Single-user**: `Database::USER_ID = 1`. Not multi-tenant — see
  SECURITY.md before exposing the web UI.

## Data Model (key tables)

| Table | Purpose |
|-------|---------|
| `users` | Single user, API keys (encrypted), safety flags |
| `bots` | Bot config (JSON), runtime state, agent flags |
| `trade_logs` | Every executed order |
| `audit_logs` | Every system event, error, decision |
| `price_ticks` | Price history for charts |
| `historical_ohlcv` | Backtest candle data |
| `agent_sessions` / `agent_messages` | AI chat sessions |
| `agent_decisions` | AI proposals + approval status |
| `agent_settings` | Autonomy mode, allowed actions, capital limits |

## Frontends

- **CLI** (`bin/kriptobot`) — status, bot CRUD, daemon control
- **TUI** (`bin/kriptobot-tui`) — live dashboard, 5s refresh
- **Web UI** (`public/`) — full management: bots, agent chat, settings,
  backtest, charts
- **JSON API** (`public/api/`) — ticks, agent chat/approval, backtest.
  The roadmap is to expand this into a full versioned `/api/v1` so
  third-party frontends (mobile) can consume the same backend.
