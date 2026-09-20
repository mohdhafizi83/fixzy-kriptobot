# Contributing to Fixzy Kriptobot

Thanks for your interest in improving Fixzy Kriptobot! This document explains how to
set up a development environment and what we expect from contributions.

## Development Environment

1. **Requirements**
   - PHP 8.5 with `pdo_sqlite`, `sqlite3`, `bcmath`, `curl`, `mbstring`
     (and `apcu` for ticker caching)
   - Composer 2.x
   - Linux or macOS

2. **Setup**

   ```bash
   git clone git@github.com:mohdhafizi83/kriptobot-oss.git
   cd kriptobot-oss
   composer install
   cp .env.example .env   # then edit .env — see docs/CONFIGURATION.md
   php bin/migrate_schema.php
   ```

   If your system PHP lacks `pdo_sqlite`, use the bundled wrapper:

   ```bash
   ./bin/php_sqlite.sh <command>
   ```

3. **Verify your setup**

   ```bash
   ./bin/php_sqlite.sh vendor/bin/phpunit    # tests must pass
   vendor/bin/phpstan analyse                # static analysis must pass
   ```

## Code Style

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/).
- All code, comments, documentation, commit messages, and pull request text
  must be in **English**.
- Never commit secrets: API keys, passwords, tokens, or database files.
  `.env` and `*.sqlite*` are git-ignored for a reason.
- Trading logic changes must include tests. Money-handling code without test
  coverage will not be merged.

## Pull Request Process

1. Fork the repository and create a feature branch from `main`.
2. Keep changes focused. One feature or fix per PR.
3. Ensure CI passes: PHPStan level 5, PHPUnit, `composer audit`.
4. Update documentation when behavior or configuration changes.
5. Describe *what* and *why* in the PR description. Link related issues.

## Testing Expectations

- New trading rules: unit tests covering pass/fail/boundary conditions.
- Engine changes: integration tests against the in-memory SQLite fixture.
- Never test against live exchanges. Binance **testnet** only.

## Reporting Bugs

Open a GitHub issue with:
- Your PHP version and OS
- Steps to reproduce
- Relevant log output (`storage/logs/daemon.log`)
- Expected vs actual behavior

## Security Vulnerabilities

Do **not** open a public issue for security problems. See SECURITY.md.

## Code of Conduct

This project is governed by the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md).
By participating, you are expected to uphold this code.
