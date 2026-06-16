#!/usr/bin/env bash
#
# ekdosi — one-step production update.
#
#   deploy/update.sh [GIT_REF]
#
# GIT_REF = a release tag (RECOMMENDED, e.g. v1.3.0) or a branch. Defaults to
# the highest SemVer tag. Cut the tag on your dev box first with `php artisan
# ekdosi:release --minor|--patch|--major`, push it, then run this on the server.
# A DOWNGRADE (target older than current HEAD) is refused unless ALLOW_DOWNGRADE=1.
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
#  10. shield:generate          (create permission rows for any NEW resources)
#  11. shield:sync-super-admin  (re-sync role→permission maps to them)
#  12. queue:restart           (workers pick up new code)
#  13. maintenance mode OFF
#  14. ops:health
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
  # Highest SemVer tag — NOT `git rev-list --tags --max-count=1`, which is the
  # most recently CREATED tag object and can be an OLD release still sitting on a
  # branch, so a no-arg deploy could silently roll prod BACKWARDS.
  REF="$(git tag --sort=-v:refname | head -n1)"
  if [[ -z "$REF" ]]; then
    fail "No ref given and no tags exist — pass an explicit ref (a tag, or 'main')."
    exit 1
  fi
  log "No ref given — using latest tag: $REF"
fi

CURRENT="$(git rev-parse --short HEAD)"
echo "Current: $CURRENT   →   Target: $REF"

# --- safety: REFUSE a downgrade --------------------------------------------
# If the target resolves to an ANCESTOR of the current HEAD (older code), bail.
# Defaulting to the latest tag while HEAD is AHEAD of it would otherwise roll the
# app back — and if the target predates a tracked file (e.g. this very script),
# the checkout DELETES it from the working tree. ALLOW_DOWNGRADE=1 for a
# deliberate rollback (prefer deploy/rollback.sh for that).
TARGET_SHA="$(git rev-parse --verify "${REF}^{commit}" 2>/dev/null)" \
  || { fail "Unknown ref: $REF"; exit 1; }
if [[ "$TARGET_SHA" != "$(git rev-parse HEAD)" ]] \
   && git merge-base --is-ancestor "$TARGET_SHA" HEAD; then
  if [[ "${ALLOW_DOWNGRADE:-0}" != "1" ]]; then
    fail "Target $REF is OLDER than current HEAD ($CURRENT) — refusing to downgrade."
    echo  "  Cut a new release tag first (php artisan ekdosi:release …) and deploy that,"
    echo  "  or pass an explicit newer ref. Deliberate rollback: ALLOW_DOWNGRADE=1 deploy/update.sh $REF"
    exit 1
  fi
  log "ALLOW_DOWNGRADE=1 — proceeding with a DOWNGRADE to $REF"
fi

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

# A release may add new resources/pages → create their Permission rows now, so
# the role re-sync below (and the per-tenant role picker) has something to grant.
# Code-driven + idempotent: a no-op when nothing new was added.
log "Generating Shield permissions (new resources)"
$ART shield:generate --all --panel=admin --ignore-existing-policies --no-interaction || true

log "Syncing permissions (super admin + tenant roles)"
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
