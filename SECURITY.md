# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 0.1.x   | ✅ Yes              |
| < 0.1   | ❌ No               |

## Reporting a Vulnerability

If you discover a security vulnerability in Fixzy Kriptobot, please report it
privately:

- **GitHub Security Advisory**: https://github.com/<owner>/kriptobot/security/advisories/new
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
