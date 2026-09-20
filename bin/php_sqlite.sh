#!/bin/bash
# Wrapper to run PHP with SQLite extensions for kriptobot.
# If the bundled extensions in bin/extensions/ are missing (e.g. a fresh clone
# on a system whose PHP already ships pdo_sqlite), fall back to plain php.
# Usage: ./bin/php_sqlite.sh <script.php> [args...]
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

SQLITE3_SO="${PROJECT_DIR}/bin/extensions/sqlite3.so"
PDO_SQLITE_SO="${PROJECT_DIR}/bin/extensions/pdo_sqlite.so"

if [[ -f "$SQLITE3_SO" && -f "$PDO_SQLITE_SO" ]]; then
  exec php \
    -d "extension=${SQLITE3_SO}" \
    -d "extension=${PDO_SQLITE_SO}" \
    "$@"
fi

# No bundled extensions — check the system PHP actually has sqlite support.
if php -m 2>/dev/null | grep -q pdo_sqlite; then
  exec php "$@"
fi

echo "ERROR: pdo_sqlite is not available and bin/extensions/*.so are missing." >&2
echo "Install php-sqlite3 (e.g. 'sudo apt install php8.5-sqlite3') or provide" >&2
echo "the bundled extensions under bin/extensions/." >&2
exit 1
