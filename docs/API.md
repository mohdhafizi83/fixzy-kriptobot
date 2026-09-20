# API Reference

Fixzy Kriptobot exposes JSON endpoints under `public/api/`. All mutating
endpoints require the session CSRF token (see below).

> **Roadmap note**: these endpoints will be consolidated into a versioned
> `/api/v1` surface with token-based auth to support third-party
> frontends (mobile apps). The current surface is stable for the web UI.

## Authentication

Two auth modes are supported:

- **Browser session** — log in at `login.php` (email + password). The
  session cookie (`kb_session`) is HttpOnly + SameSite=Lax, and
  state-changing requests additionally require the CSRF token below.
  Unauthenticated session requests get `401`.
- **Bearer token** — `Authorization: Bearer <token>` for mobile/script
  clients. Tokens are created/revoked in the dashboard (session-auth
  only; a token cannot mint tokens). Invalid tokens get `401`.

All endpoints are rate limited per IP (429 on excess).

### CSRF Token

Every mutating request must include the CSRF token:

- Form-encoded: `csrf_token=<token>` field
- JSON body: `"csrf_token": "<token>"` property
- Header: `X-CSRF-Token: <token>`

The token is available in the page as `window.csrfToken` (settings.php)
or `agent.csrfToken` (agent.php).

## Endpoints

### GET `api/ticks.php`

Recent price ticks for chart rendering.

**Response** `200`:
```json
[
  {"price": "81535.92", "pnl_percent": "1.24", "created_at": "2026-09-19 16:45:01"},
  ...
]
```
Returns up to 250 most recent ticks, oldest first.

---

### POST `api/agent_chat.php`

Send a message to the AI strategy agent and receive its reply.

**Request** (JSON):
```json
{
  "session_id": 123,
  "message": "How is my BTC bot performing?",
  "csrf_token": "<token>"
}
```

**Response** `200`:
```json
{
  "ok": true,
  "reply": "Your BTC bot is up 2.3% ...",
  "session_id": 123,
  "tool_calls": [...]
}
```

**Errors**: `403` invalid CSRF, `500` with `error` message on agent failure.

---

### POST `api/agent_approve.php`

Approve or reject a pending AI decision.

**Request** (JSON):
```json
{
  "decision_id": 456,
  "action": "approve",
  "csrf_token": "<token>"
}
```

`action` is `approve` or `reject`.

**Response** `200`:
```json
{"ok": true, "decision_id": 456, "status": "approved"}
```

---

### POST `api/backtest_run.php`

Backtest operations. Three actions via the `action` field:

**1. Check data status**
```json
{"action": "status", "symbol": "BTCUSDT", "csrf_token": "<token>"}
```
Returns available historical data range for the symbol.

**2. Ingest historical data**
```json
{
  "action": "ingest",
  "symbol": "BTCUSDT",
  "from_date": "2026-08-01",
  "to_date": "2026-09-01",
  "csrf_token": "<token>"
}
```

**3. Run a backtest**
```json
{
  "action": "run",
  "symbol": "BTCUSDT",
  "from_date": "2026-08-01",
  "to_date": "2026-09-01",
  "timeframe": "1h",
  "all_conditions": [...],
  "allocated_capital": 100,
  "target_profit": 5.0,
  "tp_type": "percentage",
  "cut_loss": 3.0,
  "max_dca_steps": 5,
  "price_drop_trigger": 2.0,
  "step_scale": 1.5,
  "volume_scale": 1.2,
  "trailing_buy_deviation": 0.5,
  "trailing_tp_deviation": 1.0,
  "fee_rate": 0.001,
  "csrf_token": "<token>"
}
```

**Response** `200`: backtest report JSON (trades, P&L, drawdown, win rate).

---

### POST `webhook.php` (TradingView)

Receives TradingView alert webhooks.

**Request** (JSON):
```json
{
  "bot_id": 1,
  "signal": "BUY",
  "type": "tv_webhook",
  "secret": "<TRADINGVIEW_WEBHOOK_SECRET>"
}
```

**Response**: `200` on success, `401` invalid secret, `400` bad format.
If the server has no secret configured: `500` (fails closed).

---

### POST `webhook_telegram.php`

Receives Telegram bot updates (for approving agent decisions from chat).

Requires header `X-Telegram-Bot-Api-Secret-Token` matching
`TELEGRAM_WEBHOOK_SECRET`. Includes `update_id` replay protection.

## Error Format

All API errors return JSON with an `error` (or `ok:false`) field and an
appropriate HTTP status code:

| Code | Meaning |
|------|---------|
| 400 | Bad request / malformed input |
| 403 | CSRF or secret verification failed |
| 500 | Server-side error (details in logs) |
