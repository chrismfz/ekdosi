#!/usr/bin/env bash
# Deploy refresh. Runs after every `git pull`.
set -euo pipefail

BACKUP_DIR=storage/app/private/ekdosi
MIN_BACKUP_BYTES=100000   # < 100 KB ⇒ the DB is almost certainly empty/broken

echo "==> [1/5] DB backup before deploy..."
if sudo -u ekdosi php artisan backup:run --only-db --disable-notifications; then
    echo "    ✓ DB backup written."
else
    echo "    ✋ DB backup FAILED — aborting deploy (do not migrate without a backup)."
    exit 1
fi

echo "==> [1b/5] Backup sanity (catch an already-empty/broken DB BEFORE migrating)..."
NEW=$(ls -t "$BACKUP_DIR"/*.zip 2>/dev/null | head -1 || true)
if [ -z "$NEW" ]; then
    echo "    ✋ No backup file found in $BACKUP_DIR — aborting."
    exit 1
fi
NEW_SZ=$(stat -c%s "$NEW")
# (a) absolute floor — an empty dump is worse than none (false sense of safety,
#     and retention will eventually evict the good ones).
if [ "$NEW_SZ" -lt "$MIN_BACKUP_BYTES" ]; then
    echo "    ✋ Backup is only ${NEW_SZ} bytes (< ${MIN_BACKUP_BYTES}) — the DB looks empty/broken."
    echo "       NOT migrating. Investigate / restore the last healthy backup first:"
    echo "       ls -lh $BACKUP_DIR"
    exit 1
fi
# (b) sudden collapse vs the previous backup (>66% smaller) — catches a partial wipe.
#     COMPARE LIKE WITH LIKE: this dir holds two kinds of zip — the nightly cron
#     backup (files+DB, ~40 MB) and this pre-deploy one (--only-db, ~1.4 MB). Naively
#     taking "the previous zip" compared db-only against a full backup and aborted
#     every deploy that ran after a nightly backup. A zip is db-only when it holds
#     nothing but db-dumps/ entries; compare against the newest older backup OF THE
#     SAME KIND.
is_db_only() {
    ! unzip -Z1 "$1" 2>/dev/null | grep -qv '^db-dumps/'
}
if is_db_only "$NEW"; then NEW_KIND=db; else NEW_KIND=full; fi
PREV=""
for f in $(ls -t "$BACKUP_DIR"/*.zip 2>/dev/null | tail -n +2 || true); do
    if is_db_only "$f"; then F_KIND=db; else F_KIND=full; fi
    if [ "$F_KIND" = "$NEW_KIND" ]; then PREV="$f"; break; fi
done
if [ -n "$PREV" ]; then
    PREV_SZ=$(stat -c%s "$PREV")
    if [ "$PREV_SZ" -gt 0 ] && [ "$NEW_SZ" -lt $(( PREV_SZ / 3 )) ]; then
        echo "    ✋ Backup ${NEW_SZ} bytes vs previous ${NEW_KIND}-only backup ${PREV_SZ} ($(basename "$PREV")) — sudden collapse. Aborting; investigate."
        exit 1
    fi
else
    echo "    ℹ No previous ${NEW_KIND}-kind backup to compare against — floor check only."
fi
echo "    ✓ Backup size OK (${NEW_SZ} bytes)."

echo "==> [2/5] Autoload + migrations..."
sudo -u ekdosi composer dump-autoload --optimize
sudo -u ekdosi php artisan migrate --force

echo "==> [3/5] Compiled-cache refresh..."
# NOTE: optimize:clear also runs cache:clear, so the app DATA cache goes too — the
# queue-worker heartbeat lands there, so `ops:health` reports it "missing" until the
# scheduler re-dispatches RecordQueueHeartbeat (<= 5 min). Expected, not a fault.
sudo -u ekdosi php artisan optimize:clear     # cache+config+route+view+compiled+events
sudo -u ekdosi php artisan optimize           # rebuild config+route caches

echo "==> [4/5] Restart runtime..."
sudo systemctl restart php-fpm                # flush opcache → new code
sudo -u ekdosi php artisan queue:restart      # workers pick up new code

echo "==> [5/5] Version reminder (advisory — never aborts)..."
# If CHANGELOG [Unreleased] has entries, the deployed code carries changes that
# weren't cut into a version yet — nudge to run `ekdosi:release`. Non-fatal.
sudo -u ekdosi php artisan ekdosi:release --check || true
