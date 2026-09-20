#!/bin/bash
# Cron daemon tick — runs every minute, flock prevents overlap
LOCK="/home/fizi/projects/kriptobot/bin/bot_daemon.lock"
exec 200>"$LOCK"
flock -n 200 || exit 0
/home/fizi/projects/kriptobot/bin/php_sqlite.sh /home/fizi/projects/kriptobot/bin/bot_daemon.php 2>&1
