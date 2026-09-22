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

## Installation (fresh install — 5 minutes)

The fastest path for non-technical users: clone, install, and let the
**setup wizard** do the rest. No config files to hand-edit.

### 1. Clone and install dependencies

```bash
git clone https://github.com/mohdhafizi83/fixzy-kriptobot.git
cd kriptobot
composer install
```

### 2. Start the web UI

```bash
php -S 0.0.0.0:8080 -t public/     # local preview
# or deploy to Apache/cPanel — see "Shared hosting" below
```

Open `http://localhost:8080` — because this is a fresh install, you are
**automatically redirected to the setup wizard** (`setup.php`).

### 3. Complete the setup wizard

The wizard takes about 2 minutes:

1. **Admin account (required)** — pick any email and password (min 8
   characters). This is local to your server only; no email verification,
   no external account. It is the single login for the web UI.
2. **Binance Testnet keys (strongly recommended)** — free keys from
   <https://testnet.binance.vision/> (sign in with GitHub, click
   *Generate New API Key*). The bot cannot trade without them, but you
   can skip and add them later under **Settings → Environment**.

What the wizard does for you automatically:

- Generates the AES-256 encryption key (`AES_MASTER_KEY`) — never
  hand-edit config files
- Creates the SQLite database and full schema
- Encrypts your API secret at rest (never stored in plain text)
- **Locks itself** — once setup is done, `setup.php` permanently
  redirects to the login page and cannot be re-run

### 4. Log in and create your first bot

Sign in with the email + password from step 3, then create a bot from
the web UI — or via CLI:

```bash
php bin/kriptobot bot:create      # interactive wizard
php bin/kriptobot bot:activate 1
php bin/kriptobot dashboard      # live TUI (Ctrl+C to quit)
```

### 5. Run the daemon

```bash
php bin/kriptobot daemon:run      # foreground test
```

For production, use the provided systemd template
(`bin/kriptobot-daemon.service` — adjust paths, then
`systemctl enable --now kriptobot-daemon`).

> **Prefer the terminal for setup?** `php bin/kriptobot setup` runs the
> same first-run wizard in the CLI.

### Shared / cPanel hosting

The app is plain PHP + a single SQLite file — no root access, no Node
build step, no special server modules beyond `pdo_sqlite`, `curl`,
`mbstring` (and optionally `apcu`).

1. Upload the project (via git or FTP). **Point the document root at
   the `public/` directory** — never at the project root. If your host
   won't let you change the document root, the bundled root `.htaccess`
   rewrites all traffic into `public/` and denies direct access to
   `src/`, `vendor/`, `database/`, `storage/`, and `bin/`.
2. Set `APP_ENV=production` in `.env` — this disables `display_errors`
   so stack traces never reach the browser.
3. `chmod 600 .env` and make sure `database/` + `storage/` are writable
   by the web server user (the daemon and the web UI share them).
4. Keep `.env`, the SQLite file, and logs **outside** the document root
   if your host allows it (set `DB_PATH` accordingly).
5. HTTPS: enable the host's SSL (Let's Encrypt is standard). Cookies and
   HSTS switch on automatically when HTTPS is detected.
6. Open the URL once in a browser and complete the setup wizard (step 3
   above).

### Advanced: file-based config (optional)

You do **not** need `.env` to run Kriptobot — the wizard stores
everything in the database. Power users who prefer file-based config can
still use it: `cp .env.example .env` and edit. Values set through the
Web UI / CLI always take priority over `.env`.

| Key | Purpose |
|-----|---------|
| `AES_MASTER_KEY` | Encryption key — auto-generated by the setup wizard if missing |
| `AI_API_KEY` / `AI_BASE_URL` / `AI_MODEL` | AI market analysis, sentiment, agent (any OpenAI-compatible provider) |
| `CRYPTOPANIC_API_KEY` | News feed for AI sentiment |
| `TELEGRAM_BOT_TOKEN` | Trade alerts and AI proposal notifications |

Each key in `.env.example` ships with instructions on where to obtain it.
Initialize the database manually only if you skip the wizard:
`php bin/migrate_schema.php`.

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
| **Real login** | Password form with bcrypt verification; no auto-login anywhere. First install forces the setup wizard (`setup.php` or `php bin/kriptobot setup`) where you create the admin account; the password lives only as a bcrypt hash in the DB. Reset via `php bin/kriptobot password:reset`. |
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

## FAQ

**I forgot my admin password. How do I reset it?**
On the server, run `php bin/kriptobot password:reset` and follow the
prompt. The new password is stored as a bcrypt hash; there is no email
recovery by design (the account never touches the internet).

**The setup wizard is locked and I need to re-run setup.**
The wizard locks permanently once the admin password is set — this is a
security feature so strangers can't re-run it on a public server. To
change anything afterwards, use the Web UI (**Settings → Environment**
for testnet keys, **Settings → Integrations** for Telegram/AI/webhooks)
or the CLI (`php bin/kriptobot setup` shows status; `password:reset`
changes the password).

**I skipped the testnet keys during setup. Where do I add them?**
Log in → **Settings → Environment** → paste your API key and secret from
<https://testnet.binance.vision/>. The secret is encrypted with AES-256
before it touches the database.

**Do I need to create or edit `.env`?**
No. The setup wizard auto-generates the encryption key and stores all
credentials in the database. `.env` is optional, file-based config for
power users. If both exist, the database values win over `.env`.

**The bot says a feature is disabled / "FEATURE_DISABLED".**
Every integration (AI, Telegram, CryptoPanic) stays off until its
credentials are configured *and* pass a live verification call. The
**Integration & Feature Status** banner in the web UI shows the exact
reason. Add or fix the key under **Settings → Integrations**.

**"Master key must be exactly 32 bytes" or secrets suddenly won't decrypt.**
This means `AES_MASTER_KEY` changed after secrets were encrypted. Restore
the original key in `.env`, or re-enter the affected keys through the
Web UI so they are re-encrypted with the current key. Keep a safe copy
of `.env` — losing the master key means losing the stored secrets.

**Login says "Too many attempts" (429).**
Rate limiting is 10 attempts / 15 minutes per IP. Wait 15 minutes, or
restart the server to clear the counter (file-backed counters live in
`storage/`).

**The web UI shows a white page / PHP errors.**
Check `php -m | grep -E 'pdo_sqlite|curl|mbstring'` — a missing
extension is the usual cause. On minimal PHP builds use the bundled
wrapper: `./bin/php_sqlite.sh <command>`. In production set
`APP_ENV=production` so errors go to logs instead of the browser.

**The daemon isn't trading.**
Checklist: (1) exchange keys configured and verified — the daemon logs
the verification result at startup; (2) at least one bot is *activated*
(`php bin/kriptobot bots` shows status); (3) the daemon is actually
running (`systemctl status kriptobot-daemon` or `php bin/kriptobot
daemon:run` in the foreground); (4) conditions are being met — the
audit log records why each tick did or didn't fire.

**Can I run the daemon on web hosting with cron only (shared/cPanel)?**
Yes — cron's 1-minute granularity is not a limitation. Run the daemon in
**cron mode**: each cron invocation performs N ticks with a delay, then
exits. `bot_daemon.php --ticks=6 --interval=10` delivers 6 ticks × 10s
= the same cadence as the full always-on daemon, inside one cron minute.
A `flock()` guard prevents overlapping runs; `--once` is shorthand for a
single tick. Keep total runtime under the host's `max_execution_time`
(stricter hosts: `--ticks=2 --interval=30`). `php bin/kriptobot
daemon:cron` prints the exact crontab line for your paths. A
VPS/dedicated server with systemd is the *recommended* setup, but web
hosting with cron is fully supported — same engine, just scheduled
instead of always-on. Details in `docs/INSTALL.md` §7.

**Can I trade with real money?**
The default is testnet-only (`BINANCE_TESTNET=1`). Live trading requires
an explicit opt-in and live keys. This is experimental software — never
point it at funds you cannot afford to lose.

**Where do I report a security issue?**
See [SECURITY.md](SECURITY.md). Do not open a public issue for
vulnerabilities.

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
