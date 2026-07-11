#!/usr/bin/env bash
#
# ekdosi — one-step production update.
#
#   deploy/update.sh [GIT_REF]
#
# GIT_REF = what to deploy. Omit it for the everyday flow: it deploys the CURRENT
# branch's pushed tip (i.e. `origin/main` when you're on main) — the `git pull`
# workflow, no tags to remember. Pass a tag (e.g. v1.3.0) for a pinned release or
# a rollback; tags check out detached on purpose. A DOWNGRADE (target older than
# current HEAD) is refused unless ALLOW_DOWNGRADE=1.
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

# --- queue worker drain (OPS-6) --------------------------------------------
# A long-running in-flight job (e.g. the 30-min Firebird import) would otherwise
# keep processing against a HALF-MIGRATED schema while `migrate` runs. Cleanly
# STOP the worker before touching the schema and START it again on the new code.
#
#   QUEUE_STOP_CMD / QUEUE_START_CMD — explicit hooks (win if set), e.g.
#       QUEUE_STOP_CMD='sudo systemctl stop ekdosi-queue'
#   Otherwise auto-detect the documented systemd unit ($QUEUE_SERVICE, default
#   ekdosi-queue). `systemctl stop` blocks until the current job drains (SIGTERM
#   → queue:work finishes the job, then exits). If neither is available we warn
#   and fall back to maintenance-mode pause only (a non-`--force` worker sleeps
#   while `down`, but an already-in-flight long job is NOT interrupted).
QUEUE_SERVICE="${QUEUE_SERVICE:-ekdosi-queue}"
_have_unit() { command -v systemctl >/dev/null 2>&1 && systemctl cat "${QUEUE_SERVICE}.service" >/dev/null 2>&1; }

# Returns the stop command's real exit status (call inside `if !`, which suspends
# `set -e` for the body so the status propagates). A DETECTED hook/unit that
# FAILS to stop returns non-zero → the caller aborts (we couldn't guarantee no
# writes during migrate). With NO hook at all we warn and return 0 (deliberate
# maintenance-pause fallback — the deploy still proceeds).
stop_queue_worker() {
  if [[ -n "${QUEUE_STOP_CMD:-}" ]]; then
    log "Draining queue worker (QUEUE_STOP_CMD)"; eval "${QUEUE_STOP_CMD}"; return
  elif _have_unit; then
    log "Draining queue worker (systemd: ${QUEUE_SERVICE})"; systemctl stop "${QUEUE_SERVICE}"; return
  else
    fail "No queue-worker stop hook — a long in-flight job could run during migrate."
    echo  "  Set QUEUE_STOP_CMD/QUEUE_START_CMD (e.g. 'sudo systemctl stop ekdosi-queue')."
    echo  "  Falling back to maintenance-mode pause only (does NOT interrupt a running job)."
    return 0
  fi
}

# Best-effort restart — never aborts the script (the app is already back up).
start_queue_worker() {
  if [[ -n "${QUEUE_START_CMD:-}" ]]; then
    log "Starting queue worker (QUEUE_START_CMD)"; eval "${QUEUE_START_CMD}" || true
  elif _have_unit; then
    log "Starting queue worker (systemd: ${QUEUE_SERVICE})"; systemctl start "${QUEUE_SERVICE}" || true
  fi
}

# --- pre-flight -------------------------------------------------------------
if [[ -n "$(git status --porcelain)" ]]; then
  fail "Working tree not clean — commit/stash changes on the server first (don't edit code on prod)."
  exit 1
fi

log "Fetching tags + commits"
git fetch --all --tags --prune

if [[ -z "$REF" ]]; then
  # Everyday flow: deploy the branch you're on (its pushed tip). Detached HEAD
  # (e.g. left over from a prior tag deploy) → fall back to main, which also
  # auto-recovers you onto the branch.
  REF="$(git symbolic-ref --quiet --short HEAD || echo main)"
  log "No ref given — deploying branch tip: $REF (the git-pull workflow)"
fi

CURRENT="$(git rev-parse --short HEAD)"

# Resolve what we'll ACTUALLY land on. For a BRANCH, that's its pushed (origin)
# tip — so a no-arg deploy ships what's on GitHub, exactly like `git pull`; we
# also stay ON the branch at checkout. For a TAG / sha (no matching origin
# branch), the ref itself, checked out detached (a pinned release).
if git rev-parse --verify --quiet "origin/${REF}^{commit}" >/dev/null 2>&1; then
  TARGET_REF="origin/${REF}"
  ON_BRANCH=1
else
  TARGET_REF="$REF"
  ON_BRANCH=0
fi
TARGET_SHA="$(git rev-parse --verify "${TARGET_REF}^{commit}" 2>/dev/null)" \
  || { fail "Unknown ref: $REF"; exit 1; }
echo "Current: $CURRENT   →   Target: $REF ($(git rev-parse --short "$TARGET_SHA"))"

# --- safety: REFUSE a downgrade --------------------------------------------
# If the target resolves to an ANCESTOR of the current HEAD (older code), bail.
# Rolling prod back is almost never intended — and if the target predates a
# tracked file (e.g. this very script), the checkout DELETES it from the working
# tree. ALLOW_DOWNGRADE=1 for a deliberate rollback (prefer deploy/rollback.sh).
if [[ "$TARGET_SHA" != "$(git rev-parse HEAD)" ]] \
   && git merge-base --is-ancestor "$TARGET_SHA" HEAD; then
  if [[ "${ALLOW_DOWNGRADE:-0}" != "1" ]]; then
    fail "Target $REF ($(git rev-parse --short "$TARGET_SHA")) is OLDER than current HEAD ($CURRENT) — refusing to downgrade."
    echo  "  Push your changes first, or pass an explicit newer ref."
    echo  "  Deliberate rollback: ALLOW_DOWNGRADE=1 deploy/update.sh $REF"
    exit 1
  fi
  log "ALLOW_DOWNGRADE=1 — proceeding with a DOWNGRADE to $REF"
fi

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

# --- drain the worker BEFORE any schema change (OPS-6) ----------------------
# A DETECTED stop hook/unit that FAILS is a CLEAN abort (nothing has changed yet):
# we can't guarantee the worker won't write during migrate, so don't proceed.
if ! stop_queue_worker; then
  fail "Could not stop the queue worker — aborting before any change."
  echo  "  Fix permissions / set QUEUE_STOP_CMD, then re-run. Nothing was deployed."
  $ART up || true
  trap - EXIT
  exit 1
fi

# --- safety: DB snapshot (OPS-7: AFTER `down` + worker drain) ----------------
# Taken here, not before `down`, so no write that lands between the snapshot and
# the maintenance window can be silently lost on a later rollback-restore (e.g.
# a MARK the ΑΑΔΕ already accepted). Nothing has changed yet, so a snapshot
# failure is a CLEAN abort: lift maintenance, bring the worker back, exit.
log "Pre-update DB snapshot (rollback point)"
if ! $ART ekdosi:db-snapshot --keep=10; then
  fail "Snapshot failed — aborting before any change."
  $ART up || true
  start_queue_worker
  trap - EXIT
  exit 1
fi

# --- update -----------------------------------------------------------------
log "Checkout $REF ($(git rev-parse --short "$TARGET_SHA"))"
if [[ "$ON_BRANCH" == "1" ]]; then
  # Stay ON the branch, reset to the pushed tip — keeps the server on `main`
  # tracking origin (your git-pull mental model) and auto-recovers a detached HEAD.
  git checkout --force -B "$REF" "$TARGET_REF"
else
  git checkout --force "$REF"   # tag / sha → detached on purpose (pinned release)
fi

# Record the DEPLOYED build identity (App\Support\BuildInfo reads this): commit
# sha + strict-ISO commit date + the ref we deployed. NOT tracked in git (it's
# per-box, per-deploy) — see .gitignore. `date` stays UTC/ISO here; the app
# formats it to the configured timezone for the Y.m.d-His build stamp.
log "Recording build identity (storage/app/build.json)"
mkdir -p storage/app
printf '{"sha":"%s","committed_at":"%s","ref":"%s"}\n' \
  "$(git rev-parse --short HEAD)" \
  "$(git log -1 --format=%cI)" \
  "$REF" > storage/app/build.json

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

# Bring the worker back on the NEW code (OPS-6). queue:restart above already
# signalled any survivor to reload; this restarts a unit we stopped to drain.
start_queue_worker

NEW="$(git rev-parse --short HEAD)"
ok "Updated $CURRENT → $REF ($NEW)"

log "Health check"
# ops:health now returns 0=ok / 1=warning / 2=critical. Right after a deploy the
# worker heartbeat may briefly read 'stale' (the worker was stopped for the whole
# window) → an expected WARNING, not a real problem. Only a CRITICAL (exit ≥2) is
# worth flagging here.
hc=0; $ART ops:health || hc=$?
if [[ "$hc" -ge 2 ]]; then
  fail "ops:health CRITICAL (exit $hc) — review the output above."
elif [[ "$hc" -eq 1 ]]; then
  echo "ℹ ops:health warnings (exit 1) — often just the worker heartbeat catching up after the restart."
fi

echo
echo "Rollback if needed:"
echo "  deploy/rollback.sh $CURRENT  <newest snapshot in storage/app/db-snapshots/>"
