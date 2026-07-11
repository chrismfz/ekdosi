#!/usr/bin/env bash
#
# ekdosi — roll back to a previous code ref and (optionally) restore the DB
# snapshot taken before the last update.
#
#   deploy/rollback.sh <GIT_REF> [SNAPSHOT_FILE]
#
#   GIT_REF       = the commit/tag to return to (deploy/update.sh prints it).
#   SNAPSHOT_FILE = a .sql.gz from storage/app/db-snapshots/ (optional).
#
# IMPORTANT: reverting code alone is safe ONLY if the bad update had no schema
# change. If it ran migrations, restore the snapshot too — a forward migration
# may be irreversible. When in doubt, pass the snapshot.
#
# Env overrides:  PHP=/usr/bin/php8.4  COMPOSER=/usr/local/bin/composer
#
set -Eeuo pipefail

cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
ART="$PHP artisan"
REF="${1:-}"
SNAP="${2:-}"

if [[ -z "$REF" ]]; then
  echo "Usage: deploy/rollback.sh <git-ref> [snapshot.sql.gz]" >&2
  exit 1
fi

# --- queue worker drain (OPS-6): don't let a live worker write into a database
# that's being restored under it. Same hooks as deploy/update.sh.
QUEUE_SERVICE="${QUEUE_SERVICE:-ekdosi-queue}"
_have_unit() { command -v systemctl >/dev/null 2>&1 && systemctl cat "${QUEUE_SERVICE}.service" >/dev/null 2>&1; }
# Returns the stop command's real status (call inside `if !`). A DETECTED hook
# that FAILS → non-zero → abort; NO hook → warn + return 0 (fallback).
stop_queue_worker() {
  if [[ -n "${QUEUE_STOP_CMD:-}" ]]; then echo "▶ Draining queue worker"; eval "${QUEUE_STOP_CMD}"; return;
  elif _have_unit; then echo "▶ Draining queue worker (systemd: ${QUEUE_SERVICE})"; systemctl stop "${QUEUE_SERVICE}"; return;
  else echo "⚠ No queue-worker stop hook — set QUEUE_STOP_CMD; a live worker could write into the DB mid-restore." >&2; return 0; fi
}
start_queue_worker() {
  if [[ -n "${QUEUE_START_CMD:-}" ]]; then echo "▶ Starting queue worker"; eval "${QUEUE_START_CMD}" || true;
  elif _have_unit; then echo "▶ Starting queue worker (systemd: ${QUEUE_SERVICE})"; systemctl start "${QUEUE_SERVICE}" || true; fi
}

echo "▶ Maintenance mode ON"
$ART down --retry=15 || true
if ! stop_queue_worker; then
  echo "✗ Could not stop the queue worker — aborting rollback (would write into the DB mid-restore). Fix perms / set QUEUE_STOP_CMD." >&2
  $ART up || true
  trap - EXIT
  exit 1
fi
# On failure, STAY in maintenance (a half-done rollback must not be served).
rollback_failed() {
  local code=$?
  [[ $code -eq 0 ]] && return 0
  printf '\n\033[1;31m✗ Rollback FAILED (exit %s) — app LEFT IN MAINTENANCE MODE. Fix, then: %s up\033[0m\n' "$code" "$ART" >&2
}
trap rollback_failed EXIT

echo "▶ Checkout $REF"
git checkout --force "$REF"

# Re-stamp the deployed build identity to the rolled-back ref, else the version
# badge keeps advertising the newer build we just rolled away from.
echo "▶ Recording build identity (storage/app/build.json)"
mkdir -p storage/app
printf '{"sha":"%s","committed_at":"%s","ref":"%s"}\n' \
  "$(git rev-parse --short HEAD)" \
  "$(git log -1 --format=%cI)" \
  "$REF" > storage/app/build.json

echo "▶ composer install (--no-dev)"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction

if [[ -n "$SNAP" ]]; then
  echo "▶ Restoring DB from $SNAP"
  $ART ekdosi:db-restore --file="$SNAP" --force
fi

echo "▶ optimize + queue restart"
$ART optimize
# Realign the cached update-check status with the rolled-back build (see update.sh).
$ART cache:forget ekdosi.updates.status || true
$ART queue:restart

echo "▶ Maintenance mode OFF"
$ART up
trap - EXIT

# Bring the worker back on the rolled-back code (OPS-6).
start_queue_worker

printf '\033[1;32m✓ Rolled back to %s%s\033[0m\n' "$REF" "${SNAP:+ (+ DB restored)}"
