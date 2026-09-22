# Installation Guide

Fixzy Kriptobot is a single-user, testnet-only crypto trading bot with a CLI/TUI and a
web UI, backed by a REST API. This guide takes you from a clean machine to a
running daemon on Binance Testnet.

> **Warning**: Fixzy Kriptobot is experimental software for **paper trading on Binance
> Testnet only**. Do not point it at real funds.

---

## 1. Requirements

| Component | Version / Notes |
|---|---|
| PHP | 8.5 (8.2+ works; tested on 8.5) with `pdo_sqlite`, `sqlite3`, `bcmath`, `curl`, `mbstring` |
| SQLite | 3.x (bundled with PHP's `pdo_sqlite`) |
| Composer | 2.x |
| OS | Linux (systemd unit provided; any POSIX system works with your own supervisor) |
| Binance Testnet account | <https://testnet.binance.vision/> — free API keys |

Check your PHP extensions:

```bash
php -m | grep -E 'pdo_sqlite|sqlite3|bcmath|curl|mbstring'
```

If `pdo_sqlite` is missing on a minimal PHP build, install it
(`sudo apt install php8.5-sqlite3` on Ubuntu/Debian). Optionally, this repo
supports prebuilt fallback extensions in `bin/extensions/` (Linux x86_64);
the wrapper `bin/php_sqlite.sh` loads them when present and otherwise uses
the system PHP.

## 2. Clone & install

```bash
git clone https://github.com/mohdhafizi83/fixzy-kriptobot.git
cd kriptobot
composer install
```

## 3. Configure `.env` (optional)

You do **not** need to create or edit `.env` to get started — the setup wizard
(§4) auto-generates the encryption key and stores all credentials in the
database. `.env` is only for power users who prefer file-based config; values
set through the UI/CLI always take priority over `.env`.

If you do want a `.env` (optional overrides):

```bash
cp .env.example .env
```

Optional keys (Telegram, AI/LLM, TradingView webhook secret) can also be set
later from the Web UI under **Settings → Integrations** — no file editing
needed.

> Never commit your real `.env`. It is gitignored.

## 4. First-run setup (required)

Either way you pick, the wizard creates your admin account, auto-generates the
AES-256 encryption key into `.env`, and (optionally) stores your Binance
Testnet API keys encrypted in the database.

**Web (recommended for non-technical users):**

```bash
./bin/php_sqlite.sh -S 127.0.0.1:8081 -t public
# open http://127.0.0.1:8081/  → you are redirected to the setup wizard
```

**CLI (power users):**

```bash
php bin/kriptobot setup
```

The wizard self-locks: once your password is set, the setup page can never be
run again. Change your password later via Settings or
`php bin/kriptobot password:reset`.

Testnet keys are **strongly recommended but skippable** — get free keys at
<https://testnet.binance.vision/> and add them during setup or later from
**Settings → Environment**.

## 5. Initialize the database (if not done by the wizard)

```bash
php bin/migrate_schema.php
```

This creates `database/kriptobot.sqlite` with the full schema. Set your DB
path in `.env` (`DB_PATH=...`) if you keep it elsewhere.

## 6. Create your first bot

```bash
php bin/kriptobot bot:create     # interactive wizard
php bin/kriptobot bots          # list bots
php bin/kriptobot bot:activate 1  # toggle bot ON
```

Or use the web UI (see §8) — Dashboard → *Create New Bot*.

## 7. Run the daemon

One-shot run (foreground):

```bash
./bin/php_sqlite.sh bin/bot_daemon.php
```

As a systemd service (recommended):

Create `/etc/systemd/system/kriptobot-daemon.service`:

```ini
[Unit]
Description=Fixzy Kriptobot Trading Daemon
After=network.target

[Service]
Type=simple
User=YOUR_USER
WorkingDirectory=/path/to/kriptobot
ExecStartPre=/bin/rm -f /path/to/kriptobot/bin/bot_daemon.lock
ExecStart=/path/to/kriptobot/bin/php_sqlite.sh /path/to/kriptobot/bin/bot_daemon.php
Restart=always
RestartSec=5
StandardOutput=append:/path/to/kriptobot/storage/logs/daemon.log
StandardError=append:/path/to/kriptobot/storage/logs/daemon.log

[Install]
WantedBy=multi-user.target
```

Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now kriptobot-daemon
systemctl status kriptobot-daemon
```

The daemon ticks every 10s for active bots (60s idle), writes price ticks,
evaluates strategies, and executes paper trades on Binance Testnet.

### Shared / cPanel hosting: cron single-tick mode

Shared hosting has no systemd and usually only allows cron at 1-minute
granularity. Run the daemon in **single-tick mode** (`--once`): each cron
invocation performs exactly one tick and exits. A `flock()` guard makes
overlapping runs impossible — if a tick is still running when the next
cron fires, the new process exits immediately.

Add to crontab (`crontab -e`, or the cPanel Cron Jobs panel):

```cron
* * * * * /path/to/kriptobot/bin/php_sqlite.sh /path/to/kriptobot/bin/bot_daemon.php --once >> /path/to/kriptobot/storage/logs/cron.log 2>&1
```

Notes:
- Ticks run every 1 minute instead of every 10s. DCA, recovery, and
  trailing logic all work fine at this cadence; only exit timing is
  slightly coarser.
- Some hosts (cPanel) enforce a minimum interval of 5 minutes — the bot
  still works, just with slower reaction.
- If the host's PHP lacks `pdo_sqlite`, the `php_sqlite.sh` wrapper
  handles it (see §1).
- `php bin/kriptobot daemon:cron` prints the exact line for your paths.

## 8. Web UI (optional)

The web UI is a thin consumer of the REST API (`/api/v1/`). Any PHP server
works. Quick start with the built-in server:

```bash
./bin/php_sqlite.sh -S 127.0.0.1:8081 -t public
# open http://127.0.0.1:8081/
```

For a persistent service, mirror the daemon unit but use
`ExecStart=/path/to/kriptobot/bin/php_sqlite.sh -S 0.0.0.0:8081 -t /path/to/kriptobot/public`.

The single-user session is hardcoded for the local admin; for remote exposure,
put it behind a reverse proxy with auth, or use API bearer tokens
(Settings → API Tokens, or `POST /api/v1/index.php/tokens`).

## 9. Verify the install

```bash
# Health: API responds
curl -s http://127.0.0.1:8081/api/v1/index.php/bots | head -c 200

# Daemon is ticking (wait ~1 min after activating a bot)
sqlite3 database/kriptobot.sqlite "SELECT COUNT(*) FROM price_ticks"

# Test suite
./bin/php_sqlite.sh vendor/bin/phpunit
```

A successful install shows: API JSON envelope `{"ok":true,...}`, growing
`price_ticks`, and all tests green.

## 10. TUI dashboard (optional)

```bash
./bin/kriptobot dashboard   # live terminal dashboard, Ctrl+C to quit
```

## Troubleshooting

- **`could not find driver (pdo_sqlite)`** — use `./bin/php_sqlite.sh` instead
  of bare `php` for every entry point.
- **Daemon won't start: lock file exists** — the unit's `ExecStartPre` removes
  `bin/bot_daemon.lock`; remove it manually if you ran the daemon outside systemd.
- **Empty charts** — the daemon only records ticks for ACTIVE bots; activate one
  and wait a minute.
- **Testnet rate limits** — Binance Testnet is throttled; the daemon backs off
  automatically. See `storage/logs/daemon.log`.

Next steps: [ARCHITECTURE.md](ARCHITECTURE.md) · [API.md](API.md) ·
[CONFIGURATION.md](CONFIGURATION.md) · [TESTING.md](TESTING.md)
