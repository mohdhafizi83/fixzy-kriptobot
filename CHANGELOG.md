# Changelog

All notable changes to Fixzy Kriptobot are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-16
### Added
- Docker support: `Dockerfile` + `docker-compose.yml` (healthcheck, optional
  trading-daemon profile, `.env` bind mount). One-command install:
  `docker compose up -d`.
- Self-hosted front-end assets (Tailwind build, Alpine.js, Axios, Chart.js,
  Tom Select, Inter font) — the UI no longer loads anything from public CDNs,
  so the dashboard works fully offline / on air-gapped hosts.
- Tailwind design system (`tailwind.config.js` + `assets/src/input.css`,
  build via `npm run build:css`).
- Brand logo mark (SVG) replacing emoji across all pages; Lucide-style inline
  icons in navigation; improved empty states.
- GitHub Release workflow (tag `v*` → release notes + GHCR container image).
- Docker quickstart in `docs/INSTALL.md`.

### Fixed
- First-run on Docker: when no `.env` existed, the setup wizard copied
  `.env.example` verbatim, carrying the placeholder `DB_PATH` and leaving the
  app unable to open its database after setup. Compose now bind-mounts the
  host `.env` so configuration stays consistent.

## [0.1.0] - 2026-09-19

First public release. The 3Commas self-hosting alternative.

### Added
- **Rule-based entry engine** — RSI, Bollinger, QFL, price-drop, sentiment,
  TradingView webhook signals, composable AND/OR condition groups
- **DCA engine** — configurable steps, price-drop triggers, step/volume scaling
- **Trailing take-profit & stop-loss** with watermark tracking
- **Partial sell** at configurable target levels
- **Smart recovery mode** — dedicated recovery bots with idle-capital protection
- **AI strategy agent** — AI-powered assistant with human-in-the-loop
  approval workflow (or opt-in full autonomy)
- **Backtesting** — Binance historical OHLCV ingestion + strategy replay
- **Market scanner** — candidate USDT pair discovery by volume/volatility
- **Telegram notifications** — trade, error, and proposal alerts
- **Three interfaces** — CLI (`bin/kriptobot`), live TUI dashboard
  (`bin/kriptobot-tui`), web UI (`public/`)
- **Testnet-first safety** — `BINANCE_TESTNET=1` default; live trading
  requires explicit opt-in
- **Encryption at rest** — AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC)
  for stored API secrets
- **Idempotent schema migration** — `bin/migrate_schema.php`
- **Test suite** — PHPUnit unit + integration tests, PHPStan level 5,
  GitHub Actions CI
- **Documentation** — README, ARCHITECTURE, API, CONFIGURATION, TESTING,
  CONTRIBUTING, SECURITY

### Security
- All secrets removed from git history (fresh repository)
- Webhook secrets moved to environment configuration (fail-closed)
- CSRF protection on all mutating web endpoints
- Dependency security advisories resolved (guzzle, psr7, react/http)
- Tamper detection on encrypted API secrets (HMAC verification)

### Known limitations
- Single-user by design (no multi-tenant isolation)
- Binance only (other exchanges via CCXT are on the roadmap)
- Web UI is server-rendered PHP; a decoupled `/api/v1` for third-party
  frontends (mobile) is planned for v0.2
