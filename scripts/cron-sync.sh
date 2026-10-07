#!/usr/bin/env bash
# System cron wrapper. Called from crontab, see docs/RUNBOOK.md.
set -euo pipefail
export PATH="$HOME/bin:$PATH"
WP_PATH="${WP_PATH:-/home/USER/public_html/papylon}"
cd "$WP_PATH"
# flock: skip this run if the previous one is still going
exec flock -n /tmp/papylon-sync.lock \
  wp papylon sync --path="$WP_PATH" >> /var/log/papylon-sync.log 2>&1
