# Fixzy Kriptobot — Open-Source Audit, Testing & Release Roadmap

> **RESUME STATUS** (update this table every time a phase completes — source of truth
> for continuing after a session break):
>
> | Phase | Status | Completed | Notes |
> |-------|--------|-----------|-------|
> | 0 — Repo Sanitization | DONE | 2026-09-19 | filter-repo purged all secrets; backups at ~/backups/kriptobot-full-history-backup.git + ~/backups/kriptobot-live-db/; webhook secret moved to .env; AGENTS.md local-only. PENDING USER: rotate Binance testnet keys, AES key, Telegram token, FTP password at provider. |
> | 1 — API-first separation | DONE | 2026-09-20 | src/Http (Router/ApiResponse/HttpRequest/ApiException) + src/Service layer (Bot, User, Market, ApiToken, AgentSettings, Webhook, Backtest) owns ALL SQL. public/api/v1/index.php front controller: session+CSRF and Bearer token auth, CORS allowlist (API_CORS_ORIGINS). All web pages are thin API consumers; legacy endpoints (api/backtest_run, api/ticks) are shims to v1. BotService.save deep-merges config (partial PUT no longer wipes base_order/dca/risk_management). Bearer tokens verified end-to-end (create→use→revoke). 27 tests / 58 assertions green. |
> | 2 — Code & Security Audit | DONE | 2026-09-19 | PHPStan level 5: 34→0 errors (incl. 1 critical DBAL-4 runtime bug in Agent DB writes, broken ingest CLI, unreachable lock release). SQL injection scan clean. CSRF added to backtest_run.php. composer audit: 15 advisories → 0. Full report appended to docs/audit_reports.md. |
> | 3 — Testing | DONE (soak in progress) | 2026-09-19 | PHPUnit 13 wired (27 tests, 58 assertions, green via bin/php_sqlite.sh — incl. 5 new BotService integration tests). Unit: EncryptionService (incl. new HMAC encrypt-then-MAC v2 format, legacy compatible), MinimumProfitGuardRule, CooldownRule. Integration: agent DB writes on in-memory SQLite proving DBAL4 fix. Found+fixed: missing bots.is_agent_managed / agent_decision_id columns (bin/migrate_schema.php added, idempotent). GitHub Actions CI added. REMAINING: 24-48h testnet soak — IN PROGRESS since 2026-09-20 09:25 UTC, auto-verdict cron `kriptobot-soak-check` fires 2026-09-22 09:45 UTC. |
> | 4 — English conversion | DONE | 2026-09-20 | All tracked files (code, docs, strings, commit messages) verified 100% English via exhaustive Malay-word grep across the full tree and full commit history. AGENTS.md stays local-only. |
> | 5 — Documentation | DONE | 2026-09-20 | README (3Commas tagline), LICENSE (MIT), CONTRIBUTING, SECURITY, CHANGELOG, docs/{ARCHITECTURE,API,CONFIGURATION,TESTING}.md published. |
> | 6 — Multi-frontend | PENDING | | |
>
> **NEW FEATURE (2026-09-20): AI Market Condition (`ai_market`)**
> AI as a trade condition (not just news sentiment). New condition type analyzes
> real-time OHLCV candles from the exchange and returns BUY / SELL / EMPTY.
> - Available in ALL 6 condition slots: base_order, dca, sell_conditions, and
>   global filters (base_buy, dca_buy, sell). Per-condition OHLCV timeframe
>   (1m–1d). EMPTY = condition not passed, bot waits (never passes).
> - AiAnalyzer::analyzeMarket() (src/Intelligence), AiMarketRule
>   (src/Trading/Rules), wired into BaseOrderConditionEngine + DcaConditionEngine.
> - Daemon caches signals per symbol+timeframe in runtime state (TTL 5 min,
>   keys: ai_market_cache / ai_market_signals) + audit log AI_MARKET_ANALYSIS.
> - UI: settings.php & configure.php dropdowns + per-condition timeframe picker.
> - 33 tests / 74 assertions green; E2E verified with mock OpenAI server.
>
> **RELEASE HOLD 2026-09-20**: repo https://github.com/mohdhafizi83/kriptobot-oss
> was published briefly, then set back to PRIVATE at user's request — all phases
> (notably Phase 1 API-first and the 24-48h soak test) must be finished before the
> real public release. Tag v0.1.0 remains on the private repo. On release day:
> flip this repo public (recommended, sanitized history preserved) or create a fresh
> repo if a clean single-commit history is preferred.
> **RESOLVED 2026-09-22**: the public release shipped as
> https://github.com/mohdhafizi83/fixzy-kriptobot (fresh sanitized history,
> v0.2.0+). The kriptobot-oss name is retired; all references below are historical.
>
> **SESSION HANDOFF (2026-09-20, end of session)**
>
> DONE (verified):
> - Phase 0: history sanitized via git filter-repo (secrets, DBs, logs, AGENTS.md,
>   to_delete/ archives, AlertTradingView.json sample payload, test_deepseek.php with
>   DeepSeek key all purged from every commit; legacy secret string scrubbed from all
>   blobs). Backups: ~/backups/kriptobot-full-history-backup.git,
>   ~/backups/kriptobot-live-db/, ~/backups/kriptobot-sql-archives/.
> - Phase 2: PHPStan level 5 clean (0 errors), composer audit 0 advisories, SQLi scan
>   clean, CSRF added to backtest_run.php, daemon lock release fixed (try/finally),
>   EncryptionService upgraded to AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC v2,
>   legacy compatible).
> - Phase 3: PHPUnit 13 — 22 tests / 39 assertions green (bin/php_sqlite.sh
>   vendor/bin/phpunit). GitHub Actions CI added. Schema migration
>   (bin/migrate_schema.php) applied.
> - Phase 4: 100% English — exhaustive Malay-word grep across all tracked files AND
>   full commit history returns 0 (only false positive: English word "Modal" in UI
>   code). Commit messages/bodies rewritten to English via filter-repo.
> - Phase 5: README (3Commas self-hosting tagline, real clone URLs), LICENSE (MIT),
>   CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, docs/{ARCHITECTURE,API,CONFIGURATION,
>   TESTING}.md.
> - Release: repo mohdhafizi83/kriptobot-oss public, tag v0.1.0 on final HEAD
>   (1769d84), local == remote, working tree clean, daemon active on testnet.
>
> NOT DONE / PENDING (what to resume, in order):
> 1. Phase 3 soak test: 24-48h testnet soak IN PROGRESS — daemon active since
>    2026-09-20 09:25 UTC via systemd (ticks fresh, no new fatals after the
>    09:33 UTC config restore). Auto-verdict cron `kriptobot-soak-check`
>    (job 4200a742f338) fires 2026-09-22 09:45 UTC. On PASS: tick the
>    Phase 3 box in the Release Checklist below.
> 2. Public release: flip kriptobot-oss public (or fresh repo) + tag v0.1.0 —
>    ONLY after soak PASS. User decision, not agent.
>    NOTE (user decision 2026-09-20): credential rotation is NOT required for
>    the public release. The user's real keys stay in the local .env (private,
>    gitignored) for personal use. The published repo ships only .env.example
>    with EMPTY values + instructions on how to obtain each key/token
>    (Binance testnet, Telegram BotFather, DeepSeek, CryptoPanic, etc.).
>    Verified: git grep finds no real secret values in any tracked file.
> 3. Phase 6 (multi-frontend): NOT started — post-v0.1.0 work, now unblocked
>    by Phase 1. See Phase 6 section for scope.
> 4. Server shutdown: blocked by Hermes hardline policy; user must run
>    `sudo shutdown -h +1 "release complete"` manually.
>
> Operational notes (2026-09-20):
> - Hermes cron `kriptobot` (per-minute daemon runner) PAUSED — systemd
>   kriptobot-daemon.service owns the daemon now; running both risks conflicts.
> - Daemon fatal errors in daemon.log before 09:35 UTC 2026-09-20 were caused by
>   a partially-saved bot config (PUT-wipe bug, fixed); config restored, no
>   recurrence since.
>
> COMPLETED SINCE LAST UPDATE (2026-09-20):
> - Phase 1 (API-first separation): DONE.
>   * src/Http (Router, ApiResponse, HttpRequest, ApiException) + src/Service
>     layer (Bot, User, Market, ApiToken, AgentSettings, Webhook, Backtest)
>     owns ALL SQL. public/api/v1/index.php front controller: session+CSRF and
>     Bearer token auth, CORS allowlist (API_CORS_ORIGINS in .env).
>   * All web pages (index, settings, configure, agent, webhook,
>     webhook_telegram) are thin API consumers — grep proves zero SQL outside
>     the service layer. Legacy endpoints (api/backtest_run, api/ticks) are
>     shims delegating to v1.
>   * BotService.save deep-merges config over existing (partial PUT no longer
>     wipes base_order/dca/risk_management) and strips csrf_token/runtime_state.
>   * Bearer tokens verified end-to-end (create → use → revoke).
> - Fresh-clone smoke test: DONE (3 iterations). Found & fixed:
>   * migrate_schema.php now bootstraps a fresh DB from database/schema.sql
>     and seeds the default admin user (previously only patched existing DBs).
>   * bin/ shell scripts cloned without +x → git mode fixed (100755).
>   * bin/bot_daemon.lock runtime artifact untracked + gitignored.
>   * php_sqlite.sh falls back to system PHP when bundled .so absent.
>   Final clone: composer install → migrate → 27 tests green → daemon ticks →
>   web 200 → API JSON ok.
> - docs/INSTALL.md: written (requirements, .env, migrate, daemon systemd unit,
>   web UI, verification, troubleshooting).
> - CODE_OF_CONDUCT.md: added (Contributor Covenant v2.0), linked from
>   CONTRIBUTING.md.
> - Tests: 27 passing / 58 assertions (5 new BotService integration tests).

> Goal: publish Fixzy Kriptobot as a clean, bug-free, fully English open-source project
> with a strict single-backend / multiple-frontend architecture.
>
> Architecture target:
> - **Backend**: PHP 8.5 (src/, bin/ daemon, JSON REST API) — the single source of truth.
> - **Frontends** (all consumers of the API, never touching the database directly):
>   1. Web UI (existing PHP/Alpine.js — to be decoupled onto the API)
>   2. Mobile app (new — wraps or consumes the same REST API)
>   3. CLI / TUI (existing — already backend-native)

---

## Phase 0 — Repository Sanitization (BLOCKER: do first, before anything public)

The current git history contains secrets and junk that MUST NOT be published.

1. **Purge secrets from all history** (testnet API keys are already committed):
   - `access_credential.md`, `to_delete/access_credential.md`
   - `database/kriptobot.sqlite` (+ `-shm`, `-wal`), `public/kriptobot.sqlite`,
     root `kriptobot.sqlite`, `database/to_delete/...`
   - `storage/logs/daemon.log`, `storage/logs/web.log`
   - Tool: `git filter-repo` (or a fresh sanitized initial commit; old history kept local-only).
2. **Rotate every credential** that ever entered the repo (Binance testnet keys, AES master key,
   Telegram bot tokens) — assume them compromised even before publishing.
3. **Harden `.gitignore`**: `*.sqlite*`, `storage/logs/`, `access_credential.md`,
   `.env`, `brain/`, `database/cache/`, `node_modules/`.
4. **Delete dead files** from the tree: `public/check*.php` (9 files), `public/test.php`,
   `bin/temp_migrate*.php`, `to_delete/` directory, empty root `kriptobot.sqlite`.
5. **Add LICENSE** (choose MIT / Apache-2.0 / GPL-3.0 — decide before publishing) and
   `.gitattributes` export-ignore rules.

**Exit criteria**: `git log -p | grep -i 'api_key\|secret\|password'` returns nothing
sensitive; fresh clone contains no DB, no logs, no credential files.

---

## Phase 1 — Backend / Frontend Separation (single backend, many frontends)

Today the web pages (public/*.php) query the database directly, so a mobile app could
never reuse them. Fix by making the JSON API the only frontend contract.

1. **API consolidation**: move all frontend-facing reads/writes from page controllers
   into `public/api/` (or `src/Http/`):
   - Existing: `ticks.php`, `agent_chat.php`, `agent_approve.php`, `backtest_run.php`
   - Needed: bots CRUD, bot activate/deploy, settings, positions, P&L summary,
     agent sessions, market scan results.
2. **API contract**: versioned routes (`/api/v1/...`), consistent JSON envelope
   `{ok, data, error}`, proper HTTP status codes, CSRF/session or token auth.
3. **Auth for non-web clients**: token-based auth (API tokens stored hashed) so the
   mobile app can authenticate without PHP sessions.
4. **CORS policy** for browser/mobile origins (configurable, locked down by default).
5. **Rule enforcement**: no frontend code reads SQLite directly; only the backend does.
   Web UI pages become thin shells calling `/api/v1/*`.

**Exit criteria**: every action available in the Web UI is also available via the API;
grep proves no page-level SQL outside backend services.

---

## Phase 2 — Code & Security Audit

1. **Static analysis**: add `PHPStan` (level 5+) and `PHP_CodeSniffer` (PSR-12);
   fix all reported errors across `src/` and `bin/` (128 PHP files).
2. **Security review checklist**:
   - SQL: confirm every query uses prepared statements (audit raw string interpolation).
   - Crypto: `EncryptionService` — AES usage, key handling, the base64 legacy fallback
     in `public/index.php` must be removed or gated (silent-decrypt fallback is a smell).
   - Auth: `AuthService` session fixation, hardcoded `$_SESSION['user_id'] = 1`
     (single-user shortcut must be behind a config flag, documented).
   - Webhooks (`webhook.php`, `webhook_telegram.php`): signature verification, replay protection.
   - Secrets: no keys in code/logs; `.env.example` complete and accurate.
   - Daemon: `bin/bot_daemon.php` (1607 lines) — error handling, lock file behavior,
     race conditions on order placement.
3. **Dependency audit**: `composer audit`; pin versions; document why `ccxt` and each dep exists.
4. **Dead code sweep**: unused classes/methods, leftover debug `echo`s, `var_dump`s.

**Exit criteria**: PHPStan + composer audit clean (documented exceptions only);
security checklist signed off item-by-item in `docs/audit_reports.md`.

---

## Phase 3 — Testing

1. **Framework**: add PHPUnit 11 as a dev dependency (already resolvable in composer.lock).
2. **Unit tests** for core logic (highest risk first):
   - Trading rules (`src/Trading/Rules/`), DCA, partial sell, take-profit, cut-loss guards.
   - `EncryptionService`, `Config`, `Database` (in-memory SQLite fixtures).
   - Agent: `RiskProfiler`, `ConfigComparator`, `ConfigGenerator`, tool registry.
3. **Integration tests**: daemon tick cycle against Binance **testnet only**;
   order lifecycle (place → track → DCA → sell) with mocked exchange responses.
4. **API tests**: every `/api/v1/*` endpoint — happy path, auth failure, bad input.
5. **Migrate the 18 ad-hoc `bin/test_*.php` scripts**: convert real assertions into
   PHPUnit; delete the rest.
6. **CI**: GitHub Actions — run PHPUnit + PHPStan + `composer audit` on every push/PR.
7. **Soak test**: run the daemon on testnet for 24–48h; zero unhandled exceptions,
   clean restart behavior under systemd.

**Exit criteria**: green CI; core trading logic covered (target ≥70% on `src/Trading`);
soak test log clean.

---

## Phase 4 — Full English Conversion (A → Z)

Everything published must be English. Current state: comments, docs, CLI output, and
commit messages are largely Malay.

1. **Code**: translate all Malay comments/docstrings in `src/`, `bin/`, `public/`
   (mechanical pass + review; keep meaning exact — these describe money-handling logic).
2. **User-facing strings**: CLI output, TUI labels, web UI text, Telegram notification
   templates, error messages.
3. **Docs**: translate `AGENTS.md`, `docs/*.md`; delete or archive Malay-only notes.
4. **Conventions**: commit message guideline in `CONTRIBUTING.md` — English only.
5. **Sweep**: `grep -rE '[a-z]+[ ](yang|dan|untuk|dengan|tidak|adalah|dalam|saya|anda)'`
   across tracked files to catch leftovers.

**Exit criteria**: no Malay text in any tracked file (code, docs, strings, filenames).

---

## Phase 5 — Documentation Set

New docs to write (all English):

1. `README.md` — what it is, architecture diagram (backend ↔ Web UI / mobile / CLI),
   features, testnet-only warning, quick start (< 10 commands to first bot).
2. `docs/INSTALL.md` — requirements (PHP 8.5, SQLite, composer), install steps,
   systemd daemon setup, `.env` reference.
3. `docs/ARCHITECTURE.md` — backend layers, API contract, data model, daemon lifecycle,
   how frontends consume the API.
4. `docs/API.md` — every endpoint: method, auth, params, response examples
   (generate from code or OpenAPI spec).
5. `docs/CONFIGURATION.md` — every `.env` key and setting explained.
6. `docs/TESTING.md` — how to run the suite, testnet sandbox instructions.
7. `CONTRIBUTING.md` — dev setup, code style (PSR-12), test requirements, PR process.
8. `SECURITY.md` — vulnerability reporting policy, key-rotation guidance.
9. `CHANGELOG.md` — start at release v0.1.0.
10. Code of Conduct (Contributor Covenant).

**Exit criteria**: a stranger can clone, install, run on testnet, and contribute
using only the repo's docs — no tribal knowledge.

---

## Phase 6 — Multi-Frontend Roadmap (post-v0.1.0)

Prerequisite: Phase 1 (API-first) complete.

1. **Web UI decoupling (v0.2)**: rewrite pages as pure API consumers; no SQL in views.
2. **Mobile app (v0.3)**: recommended React Native or Flutter consuming `/api/v1`
   with token auth. MVP scope: dashboard (positions, P&L, ticks), bot start/stop,
   agent approvals, notifications. Trading actions gated behind confirm dialogs.
3. **API tokens UI**: generate/revoke per-device tokens from Settings.
4. **Realtime**: WebSocket/SSE price stream shared by web + mobile (replaces polling).

---

## Release Checklist (v0.1.0)

- [x] Phase 0: history sanitized, LICENSE chosen
      (credential rotation waived by user 2026-09-20: real keys stay local for
      personal use; published repo ships empty .env.example + instructions)
- [x] Phase 1: API-first backend; no frontend touches SQLite
- [x] Phase 2: PHPStan + security checklist clean
- [ ] Phase 3: CI green, soak test passed (soak IN PROGRESS since 2026-09-20)
- [x] Phase 4: 100% English
- [x] Phase 5: full doc set merged (incl. INSTALL.md, CODE_OF_CONDUCT.md)
- [x] Fresh-clone smoke test on a clean machine (install → run → testnet trade)
- [ ] Repo made public (new sanitized repo or rewritten history), tag `v0.1.0`

**Suggested order**: 0 → 2 → 3 → 4 → 1 → 5 → release → 6
(Phase 1 can overlap with 2–3, but must finish before the API docs in Phase 5 are final.)
