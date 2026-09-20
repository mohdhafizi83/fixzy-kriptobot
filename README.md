# Fixzy Kriptobot

> **The self-hosted crypto trading bot that never trades on faith.**

Fixzy Kriptobot is a self-hosted algorithmic trading system with a
rule-based engine, DCA management, trailing take-profit/stop-loss, AI
market analysis, backtesting, and live dashboards. Built on PHP 8.5 +
SQLite and connected to Binance via CCXT. **Ships testnet-first: live
trading requires an explicit opt-in.**

Everything runs on your own hardware with a single SQLite file — no
subscriptions, no cloud lock-in, your keys never leave your machine.

> ⚠️ **Disclaimer**: This software is provided for educational purposes.
> Cryptocurrency trading carries substantial risk of loss. Nothing here is
> financial advice. Use at your own risk. The authors accept no liability
> for trading losses.

## Features

### Trading Engine
- **Rule-based entry engine** — RSI, Bollinger Bands, Quickfingers Luc
  (QFL), price-drop, TradingView webhook signals, and composable AND/OR
  condition groups
- **DCA (Dollar-Cost Averaging)** — configurable steps, price-drop
  triggers, step & volume scaling, per-condition gating
- **Trailing take-profit & stop-loss** with watermark tracking
- **Partial sell** at target levels
- **Smart recovery mode** — dedicated recovery logic that recovers
  deficits while protecting idle capital
- **Centralized data hub** — one price feed shared across all bots, so
  hundreds of bots never hit exchange rate limits

### AI (OpenAI-compatible)
- **AI Market condition** — the AI analyzes real-time OHLCV candles
  (1m–1d, selectable per condition) and returns BUY / SELL / EMPTY.
  EMPTY means "no conviction" — the bot waits instead of guessing.
  Works for entry, DCA, sell, and global filters.
- **AI news sentiment** — headline analysis (CryptoPanic source) as an
  additional signal layer
- **AI strategy agent** — reviews performance, proposes configuration
  changes, and requires human approval (full autonomy is opt-in)
- **Provider-agnostic** — any OpenAI-compatible endpoint works:
  DeepSeek, OpenRouter, Ollama, llama.cpp server, and more

### Safety & Governance
- **Testnet-first** — Demo Mode (Binance Testnet) is the default; live
  trading requires explicit opt-in
- **Feature gating** — every integration (exchange, AI, Telegram,
  CryptoPanic) stays disabled until its credentials are provided **and**
  verified with a live call. Disabled features always show the reason.
  The exchange API is compulsory; everything else is optional.
- **Audit log** — every decision, trade, error, and AI verdict is
  recorded with full context
- **Telegram alerts** — trade notifications, AI proposals, and feature
  status warnings

### Tooling
- **Backtesting** — ingest Binance historical OHLCV and replay
  strategies through the *exact same engines* used live
- **Market scanner** — discovers candidate USDT pairs by volume and
  volatility
- **Three interfaces** — CLI, live TUI dashboard, and web UI, all backed
  by the same REST API
- **REST API v1** — token-authenticated JSON API (Bearer) plus
  session+CSRF for the web UI

## Architecture

```
                    ┌─────────────────────────────┐
                    │         Frontends           │
                    │  CLI · TUI · Web UI · API  │
                    └──────────────┬──────────────┘
                                   │ JSON / PHP
                    ┌──────────────▼──────────────┐
                    │      Core (src/)            │
                    │  Trading engine · Rules     │
                    │  DCA · Trailing · Recovery  │
                    │  AI · Backtest · Intel      │
                    └──────────────┬──────────────┘
                                   │ Doctrine DBAL
                    ┌──────────────▼──────────────┐
                    │   SQLite (single file)      │
                    │   database/kriptobot.sqlite │
                    └──────────────┬──────────────┘
                                   │ CCXT
                    ┌──────────────▼──────────────┐
                    │   Binance (Testnet default) │
                    └─────────────────────────────┘
```

- `bin/bot_daemon.php` — the trading engine loop (ticks every 10s
  active / 60s idle), run as a systemd service or cron job
- `src/` — PSR-4 `Fixzy\Kriptobot\` namespace: all business logic
- `public/` — web UI + JSON API endpoints
- `bin/kriptobot` — CLI entry point (`status`, `bots`, `bot:create`,
  `bot:activate`, `dashboard`, `market:scan`, `daemon:run`, ...)

## Requirements

- PHP 8.5 with `pdo_sqlite`, `sqlite3`, `bcmath`, `curl`, `mbstring`
  (and `apcu` for ticker caching)
- Composer
- Linux/macOS (systemd optional)

> **Note on PHP + SQLite**: if your system PHP lacks `pdo_sqlite`, use
> the bundled wrapper `./bin/php_sqlite.sh` in place of `php` (it loads
> the extension from `bin/extensions/`). Tests run the same way:
> `./bin/php_sqlite.sh vendor/bin/phpunit`.

## Installation

### 1. Clone and install dependencies

```bash
git clone https://github.com/mohdhafizi83/fixzy-kriptobot.git
cd kriptobot
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env`. The only **required** value is the encryption key:

```bash
AES_MASTER_KEY=base64:$(php -r "echo base64_encode(random_bytes(32));")
```

All other keys are **optional** — features that need them stay disabled
(with a visible reason) until you add and verify them:

| Key | Purpose |
|-----|---------|
| `AI_API_KEY` / `AI_BASE_URL` / `AI_MODEL` | AI market analysis, sentiment, agent (any OpenAI-compatible provider) |
| `CRYPTOPANIC_API_KEY` | News feed for AI sentiment |
| `TELEGRAM_BOT_TOKEN` | Trade alerts and AI proposal notifications |

Each key in `.env.example` ships with instructions on where to obtain it.

### 3. Initialize the database

```bash
php bin/migrate_schema.php
```

### 4. Add exchange credentials

Get free testnet keys at
<https://testnet.binance.vision/guide>, then:

```bash
php bin/kriptobot keys
```

### 5. Create and run your first bot

```bash
php bin/kriptobot bot:create      # interactive wizard
php bin/kriptobot bot:activate 1
php bin/kriptobot dashboard      # live TUI (Ctrl+C to quit)
```

Or run the daemon in the foreground:

```bash
php bin/kriptobot daemon:run
```

### 6. Web UI (also works on standard shared web hosting)

Local preview:

```bash
php -S 0.0.0.0:8080 -t public/
# open http://localhost:8080 — log in with your admin credentials
```

**Shared / cPanel hosting is fully supported.** The app is plain PHP + a
single SQLite file — no root access, no Node build step, no special server
modules beyond `pdo_sqlite`, `curl`, `mbstring` (and optionally `apcu`).

Deploy steps for standard web hosting:

1. Upload the project (via git or FTP). **Point the document root at the
   `public/` directory** — never at the project root. If your host won't
   let you change the document root, the bundled root `.htaccess` rewrites
   all traffic into `public/` and denies direct access to `src/`,
   `vendor/`, `database/`, `storage/`, and `bin/`.
2. Set `APP_ENV=production` in `.env` — this disables `display_errors`
   so stack traces never reach the browser.
3. `chmod 600 .env` and make sure `database/` + `storage/` are writable
   by the web server user (the daemon and the web UI share them).
4. Keep `.env`, the SQLite file, and logs **outside** the document root
   if your host allows it (set `DB_PATH` accordingly).
5. HTTPS: enable the host's SSL (Let's Encrypt is standard). Cookies and
   HSTS switch on automatically when HTTPS is detected.

### 7. (Optional) systemd service

`bin/kriptobot-daemon.service` is provided as a template
(`systemctl enable --now` after adjusting paths).

## Feature Gating

Fixzy Kriptobot never trades on a feature it cannot verify:

- **Exchange API (compulsory)** — verified with a live `fetch_time()`
  call. Without it, nothing trades.
- **AI, CryptoPanic, Telegram (optional)** — each stays disabled until
  configured *and* verified with a live call.
- When a condition needs a disabled feature, the condition cannot pass —
  the bot waits, and a `FEATURE_DISABLED` notice goes to the console,
  audit log, and Telegram (rate-limited to one per hour).
- The **🔌 Integration & Feature Status** banner (web UI) and the daemon
  startup log show every feature's status and the exact reason.

## Testnet-First Safety

- `BINANCE_TESTNET=1` (default) forces all trading to Binance Testnet.
- Set `BINANCE_TESTNET=0` **only** when you have configured live keys
  and accept real-money risk.
- The AI agent defaults to `approval_required` — every configuration
  change needs your explicit approval.

## Security Hardening

The web layer is hardened by default — no extra configuration required:

| Control | Details |
|---|---|
| **Real login** | Password form with bcrypt verification; no auto-login anywhere. First install seeds the password from `DEV_ADMIN_PASSWORD`, then it lives only as a bcrypt hash in the DB. Reset via `php bin/kriptobot password:reset`. |
| **Session hardening** | HttpOnly + SameSite=Lax cookies, `Secure` flag auto-on under HTTPS, 8-hour idle timeout, session ID regenerated on login (fixation-proof). |
| **CSRF** | Every session-authenticated state-changing API call requires a valid `X-CSRF-Token` (constant-time compared). Bearer-token clients are exempt by design. |
| **Rate limiting** | Login: 10 attempts / 15 min per IP (429). API: 300 req/min per IP. Webhooks: 60/min per IP. APCu-backed with a file fallback so it works on shared hosting without APCu. |
| **Webhook auth** | TradingView secret compared with `hash_equals` (timing-safe). Telegram webhook **refuses all traffic** unless `TELEGRAM_WEBHOOK_SECRET` is configured, plus `update_id` replay protection. |
| **Security headers** | CSP (locked to the CDNs the UI uses), `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, HSTS on HTTPS. |
| **No info leaks** | `APP_ENV=production` turns off `display_errors`; API errors return generic 500s with details only in server logs. |
| **File protection** | Bundled `.htaccess` (root + `public/`) blocks dotfiles, `.env`, SQLite/log files, and directory listings; root `.htaccess` funnels all traffic into `public/` if the document root is misconfigured. |
| **SQL injection** | All queries use prepared statements (Doctrine DBAL bound parameters). |
| **Credentials at rest** | Exchange API secrets encrypted with AES-256 (`AES_MASTER_KEY`); the master key lives only in `.env`, never in the DB. |

> ⚠️ **On shared hosting, remember:** your bot's admin login protects real
> trading credentials. Use a strong password (8+ chars minimum enforced by
> the CLI reset), keep `.env` unreadable to other accounts (`chmod 600`),
> and disable withdrawals on your exchange API keys.

## Testing

```bash
./bin/php_sqlite.sh vendor/bin/phpunit            # all suites
./bin/php_sqlite.sh vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse                        # static analysis
composer audit                                    # dependency advisories
```

## Documentation

- `docs/INSTALL.md` — step-by-step installation guide
- `docs/ARCHITECTURE.md` — core layers, daemon lifecycle, data model
- `docs/API.md` — JSON API reference
- `docs/CONFIGURATION.md` — every `.env` key, bot setting, and the
  feature-gating policy
- `docs/ROADMAP.md` — product roadmap, including the mobile app
- `docs/TESTING.md` — test suite and testnet sandbox guide
- `public/faq.php` — in-app FAQ (AI conditions, gating, strategies)
- `CONTRIBUTING.md` — development workflow
- `SECURITY.md` — vulnerability reporting

## Roadmap Highlights

- **Mobile app (iOS & Android)** — monitor and control your bots on the
  go: live positions, AI proposals with one-tap approve, push
  notifications, and secure Bearer-token auth against the same REST API.
  Full plan in [`docs/ROADMAP.md`](docs/ROADMAP.md).
- Multi-exchange support beyond Binance
- Strategy marketplace & shared presets

## License

MIT — see [LICENSE](LICENSE).
