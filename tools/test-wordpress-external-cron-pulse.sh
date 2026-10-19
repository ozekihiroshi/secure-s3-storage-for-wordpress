#!/bin/sh
set -eu
# crond starts this pulse once per minute under a nonblocking flock.
# Admit new invocations for 45 seconds; an in-flight invocation may finish later.
test "${ODBFS3_EXTERNAL_CRON:-}" = 1
started=$(date +%s)
printf '{"event":"pulse_start","at":%s,"pid":%s}\n' "$started" "$$" >> /private/external-cron.jsonl
deadline=$((started + 45))
while [ "$(date +%s)" -lt "$deadline" ]; do
    if /usr/local/bin/php /site/wp-cron.php >> /private/external-cron-worker.log 2>&1; then
        printf '{"event":"entry_returned","at":%s,"pid":%s}\n' "$(date +%s)" "$$" >> /private/external-cron.jsonl
    else
        printf '{"event":"entry_failed","at":%s,"pid":%s}\n' "$(date +%s)" "$$" >> /private/external-cron.jsonl
        exit 1
    fi
    sleep 2
done
printf '{"event":"pulse_end","at":%s,"pid":%s}\n' "$(date +%s)" "$$" >> /private/external-cron.jsonl
