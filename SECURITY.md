# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 0.1.x   | ✅ Yes              |
| < 0.1   | ❌ No               |

## Reporting a Vulnerability

If you discover a security vulnerability in Fixzy Kriptobot, please report it
privately:

- **GitHub Security Advisory**: https://github.com/mohdhafizi83/fixzy-kriptobot/security/advisories/new
- **Email**: the repository owner's contact via GitHub profile

Please include:
- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

We aim to acknowledge reports within 48 hours and release a fix within
7 days for critical issues.

## Important Security Notes for Operators

Fixzy Kriptobot handles API credentials for cryptocurrency exchanges. Follow
these practices:

1. **Rotate keys regularly.** Generate fresh API keys when installing.
   Never reuse keys from other services.
2. **Restrict API key permissions.** On Binance, disable withdrawals on
   API keys used by the bot. Restrict by IP where possible.
3. **Protect your `.env` file.** It contains the AES master key that
   encrypts stored API secrets. File permissions: `chmod 600 .env`.
4. **Testnet first.** `BINANCE_TESTNET=1` is the default. Only switch to
   live trading after thorough testnet validation.
5. **Webhook secrets are mandatory** when exposing webhook endpoints to
   the internet. Configure `TRADINGVIEW_WEBHOOK_SECRET` and
   `TELEGRAM_WEBHOOK_SECRET`.
6. **This is single-user software.** There is no multi-tenant isolation.
   Do not expose the web UI to untrusted networks without a reverse proxy
   and authentication.

## Endpoint Security Matrix

Last audited: 2026-10 (automated checks in CI: PHPStan, PHPUnit,
`composer audit`, gitleaks full-history secret scan).

| Endpoint | Auth | CSRF | Rate limit | Fail-closed behaviour |
|---|---|---|---|---|
| `POST /webhook.php` (TradingView) | Shared secret `TRADINGVIEW_WEBHOOK_SECRET`, `hash_equals` | n/a (no session) | 60/min/IP | 500 if secret unset; 401 on missing/wrong secret |
| `POST /webhook_telegram.php` | `X-Telegram-Bot-Api-Secret-Token`, `hash_equals` + `update_id` replay guard | n/a (no session) | 60/min/IP | 500 if secret unset; 403 on mismatch |
| `POST /login.php` | public form, bcrypt via `AuthService` | ✅ `csrf_token` | 10/15 min/IP | 419 on bad token; generic error message |
| `POST /setup.php` (wizard) | self-locks once admin password exists | ✅ `csrf_token` | 5/15 min/IP | 419 on bad token |
| `POST /logout.php` | session | ✅ `csrf_token` (POST-only) | n/a | GET is a no-op redirect; wrong token = no logout |
| `GET/POST /index.php`, `/agent.php`, `/configure.php`, `/settings.php`, `/faq.php` | `SessionGuard::requireWeb()` → redirect to login | ✅ forms/fetch send `csrf_token` | n/a | unauthenticated → `login.php` |
| `/api/v1/index.php/*` (REST) | Bearer API token **or** session | ✅ required on all session-auth POST/PUT/PATCH/DELETE | 300/min/IP | 401 unauthenticated; 403 bad CSRF |
| `POST /api/agent_approve.php` | session | ✅ all actions | n/a | 401/403 fail-closed |
| `POST /api/agent_chat.php` | session | ✅ all actions (incl. reads — endpoint is session-scoped) | 20/min/IP | 401/403 fail-closed |
| `/api/backtest_run.php`, `/api/ticks.php` | shims → v1 front controller (same auth) | via v1 | via v1 | via v1 |

Session layer (`src/Security/SessionGuard.php`): HttpOnly + SameSite=Lax
cookies, `Secure` auto-set under HTTPS (incl. `X-Forwarded-Proto`), 8-hour
idle timeout, session ID regenerated on login. CSRF tests:
`tests/Unit/SessionGuardTest.php`.

Known design notes (accepted, not vulnerabilities):
- Bearer-token API clients are CSRF-exempt by design (no ambient credentials).
- `tests/manual_setup_e2e.php` contains fixed dummy secrets; allowlisted in
  `.gitleaks.toml` — they are not real credentials.
