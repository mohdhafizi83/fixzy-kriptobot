# Fixzy Kriptobot — Product Roadmap

Last updated: 2026-09-20

This is the public product roadmap. Priorities may shift based on user
feedback — open an issue to weigh in.

## Current Status (v0.1)

The core platform is feature-complete for single-user, testnet-first
trading:

- Rule-based trading engine (RSI, Bollinger, QFL, TradingView webhooks)
- DCA with per-condition gating
- Trailing take-profit / stop-loss, partial sells, smart recovery
- AI market condition (OHLCV analysis, BUY/SELL/EMPTY, per-condition
  timeframe) and AI news sentiment
- AI strategy agent with human-approval workflow
- Backtesting with the exact live engines
- Market scanner, centralized data hub
- Feature gating (exchange compulsory; AI/Telegram/CryptoPanic optional,
  verified before enable)
- CLI, TUI dashboard, web UI, REST API v1

## Roadmap

### 1. Hardening & Multi-Exchange (near term)

- [ ] Multi-exchange support: Bybit, OKX, KuCoin (CCXT abstraction is
      already in place; needs per-exchange testing and fee models)
- [ ] Live-trading graduation path: staged rollout tooling (paper →
      small-cap live → full live) with kill-switches
- [ ] Database backup/restore automation and integrity checks
- [ ] Performance profiling for 100+ concurrent bots

### 2. Mobile App (iOS & Android) — next major milestone

A companion app to **monitor and control** Fixzy Kriptobot on the go.
The app talks to the existing REST API v1 (Bearer token auth) — no new
backend required for the MVP.

**Phase M1 — Read-only monitoring (MVP)**
- [ ] Secure login: paste your API token (created in Settings → API
      Keys); optional QR pairing with the web UI
- [ ] Dashboard: portfolio value, active deals, open positions with
      live P&L, daemon health
- [ ] Bot list: status, capital, today's trades
- [ ] Push notifications: trade executed, take-profit hit, stop-loss
      triggered, daemon down

**Phase M2 — Control**
- [ ] Approve / reject AI agent proposals with one tap (full proposal
      diff view before decision)
- [ ] Activate / pause bots; emergency global stop (flatten watch)
- [ ] Edit safe bot parameters (capital, TP/SL, DCA steps) with
      confirmation dialogs
- [ ] Feature-gating status view (what's enabled and why)

**Phase M3 — Intelligence**
- [ ] AI verdict feed: see every AI market analysis (BUY/SELL/EMPTY,
      confidence, reason) as a scrollable timeline
- [ ] Backtest results viewer with charts
- [ ] Price & condition alerts per pair
- [ ] Biometric app lock (Face ID / fingerprint)

**Platform plan**
- Cross-platform: Flutter (single codebase, iOS + Android)
- Auth: existing Bearer tokens; tokens are hashed server-side, revocable
- Self-hosted by design: the app connects to *your* server URL — we
  never host your data
- App distribution: TestFlight / Play Store internal testing first,
  public release after M2

### 3. Community & Extensibility

- [ ] Strategy preset sharing (export/import JSON with validation)
- [ ] Plugin API for custom indicators and signal sources
- [ ] Multi-user support (multi-tenant, per-user isolation)
- [ ] Webhook outgoing events (your integrations subscribe to our events)

### 4. Advanced AI

- [ ] AI-assisted strategy generation (describe a strategy in plain
      language → draft bot configuration)
- [ ] Backtest-vs-AI cross-validation reports
- [ ] Optional local-model support (Ollama) for fully offline AI

## Contributing

Ideas and PRs welcome — see `CONTRIBUTING.md`. For major features, open
an issue first to align on scope.
