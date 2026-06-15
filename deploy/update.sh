#!/usr/bin/env bash
#
# ekdosi — one-step production update.
#
#   deploy/update.sh [GIT_REF]
#
# GIT_REF = a release tag (RECOMMENDED, e.g. v1.3.0) or a branch. Defaults to
# the latest tag. Cut the tag on your dev box first with `php artisan
# ekdosi:release --minor|--patch|--major`, push it, then run this on the server.
#
# What it does, in order (safe + idempotent):
#   1. pre-flight: working tree must be clean
#   2. fetch tags/commits
#   3. DB snapshot (rollback point)  →  storage/app/db-snapshots/
#   4. maintenance mode ON
#   5. checkout the target ref
#   6. composer install --no-dev
#   7. php artisan migrate --force
#   8. build assets (only if a package-lock.json exists)
#   9. php artisan optimize  (config/route/view cache)
#  10. shield:sync-super-admin  (permission sync)
#  11. queue:restart           (workers pick up new code)
#  12. maintenance mode OFF
#  13. ops:health
#
# Env overrides:  PHP=/usr/bin/php8.4  COMPOSER=/usr/local/bin/composer
#
set -Eeuo pipefail

cd "$(dirname "$0")/.."          # repo root
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
ART="$PHP artisan"
REF="${1:-}"

log()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; }

# --- pre-flight -------------------------------------------------------------
if [[ -n "$(git status --porcelain)" ]]; then
  fail "Working tree not clean — commit/stash changes on the server first (don't edit code on prod)."
  exit 1
fi

log "Fetching tags + commits"
git fetch --all --tags --prune

if [[ -z "$REF" ]]; then
  REF="$(git describe --tags "$(git rev-list --tags --max-count=1)")"
  log "No ref given — using latest tag: $REF"
fi

CURRENT="$(git rev-parse --short HEAD)"
echo "Current: $CURRENT   →   Target: $REF"

# --- safety: DB snapshot BEFORE anything changes ----------------------------
log "Pre-update DB snapshot (rollback point)"
$ART ekdosi:db-snapshot --keep=10 || { fail "Snapshot failed — aborting before any change."; exit 1; }

# --- maintenance window -----------------------------------------------------
log "Maintenance mode ON"
$ART down --retry=15 || true
# On any FAILURE after this point, deliberately STAY in maintenance mode — a
# half-applied update (e.g. a failed migration on new code) must never be served.
# Only the success path below lifts maintenance.
deploy_failed() {
  local code=$?
  [[ $code -eq 0 ]] && return 0
  fail "Update FAILED (exit $code) — app LEFT IN MAINTENANCE MODE on purpose."
  echo  "  Investigate, then fix-forward or roll back:"
  echo  "    deploy/rollback.sh $CURRENT  <newest in storage/app/db-snapshots/>"
  echo  "  When healthy again:  $ART up"
}
trap deploy_failed EXIT

# --- update -----------------------------------------------------------------
log "Checkout $REF"
git checkout --force "$REF"

log "composer install (--no-dev)"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction

log "Database migrations"
$ART migrate --force

# Front-end build only when there's a committed lockfile (skipped on a repo
# that still uses Filament's pre-built assets).
if [[ -f package-lock.json ]]; then
  log "Building front-end assets"
  npm ci && npm run build
fi

log "Caching config / routes / views"
$ART optimize

log "Syncing permissions (super admin)"
$ART shield:sync-super-admin || true

log "Restarting queue workers"
$ART queue:restart

# --- done -------------------------------------------------------------------
log "Maintenance mode OFF"
$ART up
trap - EXIT

NEW="$(git rev-parse --short HEAD)"
ok "Updated $CURRENT → $REF ($NEW)"

log "Health check"
$ART ops:health || fail "ops:health flagged issues — review the output above."

echo
echo "Rollback if needed:"
echo "  deploy/rollback.sh $CURRENT  <newest snapshot in storage/app/db-snapshots/>"
