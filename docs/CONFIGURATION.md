# Configuration

Fixzy Kriptobot reads configuration from a `.env` file in the project root.
Copy `.env.example` to `.env` and fill in your values.

## Environment Variables

### Core

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `APP_ENV` | No | `development` | Application environment. |
| `AES_MASTER_KEY` | **Yes** | — | 32-byte key used to encrypt API secrets at rest. Format: `base64:<base64-of-32-bytes>` or raw 32 chars. Generate: `php -r "echo base64_encode(random_bytes(32));"` |
| `BINANCE_TESTNET` | No | `1` | `1` = Binance Testnet (safe default). `0` = live trading, real money. Only set to `0` when you accept the risk. |

### TradingView Webhook

| Key | Required | Description |
|-----|----------|-------------|
| `TRADINGVIEW_WEBHOOK_SECRET` | If using TradingView alerts | Shared secret. Generate: `openssl rand -hex 32`. Your TradingView alert JSON payload must include `"secret":"<same value>"`. If unset, the webhook endpoint returns 500 (fails closed). |

### Telegram (optional)

| Key | Required | Description |
|-----|----------|-------------|
| `TELEGRAM_BOT_TOKEN` | For notifications | Bot token from @BotFather. |
| `TELEGRAM_CHAT_ID` | For notifications | Your chat/group ID. |
| `TELEGRAM_WEBHOOK_SECRET` | If using webhook | Verified against `X-Telegram-Bot-Api-Secret-Token` header. Set this before exposing the webhook publicly. |

### AI / LLM (optional)

Any provider that speaks the **OpenAI chat-completions format** works: DeepSeek,
OpenRouter, OpenAI, Groq, Mistral, Ollama, vLLM, LM Studio, and more. The agent
sends `Authorization: Bearer <AI_API_KEY>` to `<AI_BASE_URL>chat/completions`.

| Key | Required | Description |
|-----|----------|-------------|
| `AI_API_KEY` | For AI agent | Provider API key. Without it, AI features are disabled. Get one from your provider's dashboard (DeepSeek: platform.deepseek.com, OpenRouter: openrouter.ai/keys, OpenAI: platform.openai.com/api-keys, Groq: console.groq.com/keys). |
| `AI_BASE_URL` | Yes (for AI) | OpenAI-compatible base URL. Examples: `https://openrouter.ai/api/v1`, `https://api.openai.com/v1`, `https://api.deepseek.com` (DeepSeek), `http://localhost:11434/v1` (Ollama), `http://localhost:1234/v1` (LM Studio). |
| `AI_MODEL` | Yes (for AI) | Model id for your provider (e.g. `deepseek-chat`, `llama3.1:70b`, `gpt-4o-mini`). Native tool calling needs a tool-capable model; otherwise the agent falls back to prompt-based (ReAct) mode. |
| `CRYPTOPANIC_API_KEY` | For news sentiment | CryptoPanic API key for the news fetcher. |

## Bot Settings (per-bot JSON)

Each bot stores its strategy as JSON in the `bots.configuration` column.
Key sections:

- `general` — `exchange`, `pair_strategy` (single / top-volume scanner),
  `custom_pairs`, `capital` (fixed amount or `AUTO`)
- `base_order` — entry conditions (indicator rules, TradingView signals,
  AI market conditions), trailing buy deviation
- `dca` — `max_steps`, `price_drop_trigger`, `step_scale`, `volume_scale`
- `risk_management` — `target_profit`, `tp_type`, `cut_loss_enabled`,
  `cut_loss_percent`, trailing TP/SL deviations
- `sell_conditions` — composable AND/OR groups for exits

Condition types: `rsi`/`rsi_14`, `bollinger`, `qfl`, `tradingview`,
`news_sentiment` (AI on headlines), and `ai_market` (AI on real-time OHLCV
candles). `ai_market` conditions carry a `timeframe` (1m/5m/15m/1h/4h/1d)
and a value of `BUY` or `SELL`; an EMPTY AI verdict never passes, so the
bot waits. See the FAQ ("AI Market Analysis (OHLCV)") for examples.

Use `php bin/kriptobot bot:create` (interactive) or the web UI to build
these — hand-editing JSON is possible but not required.

## Agent Settings (per-user)

Stored in `agent_settings`:

- `agent_enabled` — master switch for the AI agent
- `autonomy_mode` — `approval_required` (default) or `full_autonomy`
- `allowed_actions` — JSON array: `create_bot`, `update_config`,
  `delete_bot`, etc.
- `max_capital_per_bot`, `max_total_capital` — hard caps on AI-created bots
- `telegram_notifications` — send proposals to Telegram for approval

## Daemon Tuning

In `bin/bot_daemon.php`:

- `$loopInterval = 10;` — seconds between ticks when a deal is active
- `$idleInterval = 60;` — seconds between ticks when idle (smart polling)

## Feature Gating (Integration Status)

Every integration that needs an external API or token is **gated**: it stays
DISABLED until its credentials are (1) provided and (2) verified with a live
call. Status and reasons are visible in the "Integration & Feature Status"
banner on the bot editor and System Configuration pages, and in the
`feature_status` table.

| Feature | Required | Credentials | Verification |
|---------|----------|-------------|--------------|
| Exchange API | **YES (compulsory)** | Testnet keys (Settings → Environment) or live keys (Settings → API Keys) | `fetch_time()` against the exchange |
| AI Analysis | optional | `AI_API_KEY` + `AI_BASE_URL` + `AI_MODEL` in `.env` | `GET {base}/models`, fallback 1-token chat completion |
| CryptoPanic News | optional | `CRYPTOPANIC_API_KEY` in `.env` | `GET /api/v1/posts/` |
| Telegram Notifications | optional | `TELEGRAM_BOT_TOKEN` in `.env` + linked chat id | `getMe` |

Behaviour when a disabled feature is needed by a condition (e.g. `ai_market`
or `news_sentiment`):

- The condition **cannot pass** — the bot waits. It never trades on a feature
  it cannot verify.
- The daemon logs a `FEATURE_DISABLED` audit entry, prints the reason to the
  console, and sends a Telegram alert (max once per hour per feature).
- After credentials are added, the feature flips to enabled on the next
  daemon start or when "Re-verify All" is pressed in the UI.

API endpoints:

- `GET  /api/v1/index.php/settings/features` — current statuses
- `POST /api/v1/index.php/settings/features/verify` — run all verifications now

## File Locations

| Path | Purpose |
|------|---------|
| `database/kriptobot.sqlite` | The entire database |
| `database/cache/available_pairs.json` | Market scanner pair cache |
| `storage/logs/daemon.log` | Daemon output |
| `bin/bot_daemon.lock` | Single-instance lock (auto-managed) |
