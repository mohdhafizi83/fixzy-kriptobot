# Testing

## Running the Test Suite

```bash
# All suites (use the wrapper if your PHP lacks pdo_sqlite)
./bin/php_sqlite.sh vendor/bin/phpunit

# Or with composer scripts
composer test              # all
composer test:unit         # unit tests only
composer test:integration  # integration tests only

# Static analysis
composer phpstan           # PHPStan level 5

# Dependency security
composer audit
```

## Test Layout

```
tests/
├── Unit/           # Pure logic, no I/O
│   ├── EncryptionServiceTest.php
│   ├── MinimumProfitGuardRuleTest.php
│   └── CooldownRuleTest.php
└── Integration/    # Database round-trips on in-memory SQLite
    └── AgentDatabaseTest.php
```

- **Unit tests** cover trading rules (pass/fail/boundary/timeout/smart-mode),
  encryption round-trips, tamper detection, and key validation.
- **Integration tests** build the real schema in an in-memory SQLite
  database and exercise the exact write patterns used by the agent
  subsystem (sessions, messages, decisions, bots).

## Testnet Sandbox

All manual testing must use **Binance Testnet**:

1. Create testnet account: https://testnet.binance.vision/
2. Generate testnet API keys
3. Store them via `php bin/kriptobot keys` (encrypted at rest)
4. Keep `BINANCE_TESTNET=1` in `.env` (the default)
5. Create a bot with small capital and watch it trade fake money

Never point the bot at live exchanges during development.

## Soak Testing (pre-release)

Before a release, run the daemon on testnet for 24–48 hours:

```bash
sudo systemctl restart kriptobot-daemon
# monitor
tail -f storage/logs/daemon.log
```

Pass criteria:
- Zero unhandled exceptions
- Clean restart behavior (lock released, state consistent)
- No memory growth trends
- All trade operations audit-logged

## CI

GitHub Actions (`.github/workflows/ci.yml`) runs on every push/PR:
PHP 8.5 setup → composer install → PHPStan → PHPUnit (Unit + Integration)
→ composer audit.

## Adding Tests

- New trading rule → add a `*RuleTest` in `tests/Unit/` covering:
  pass, fail, boundary value, disabled state, and any special modes.
- New DB write path → add a case in `tests/Integration/` using the
  in-memory schema pattern from `AgentDatabaseTest`.
- Keep tests deterministic — no network calls, no real clock dependencies
  beyond `time()` deltas.
