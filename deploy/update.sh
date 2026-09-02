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
#   6b. pre-migration data checks (customers:afm-duplicates)
#   7. php artisan migrate --force
#   8. build assets (only if a package-lock.json exists)
#   9. php artisan optimize  (config/route/view cache)
#  10. shield:generate          (create permission rows for any NEW resources)
#  11. shield:sync-super-admin  (re-sync role→permission maps — super_admin AND
#                                the per-tenant company_admin/operator)
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
# Three ways, tried in order — the LAST one needs no privileges at all, so this
# works the same on a systemd VM, on cPanel/Plesk/DirectAdmin shared hosting, and
# with a cron-driven `queue:work`:
#   1. QUEUE_STOP_CMD / QUEUE_START_CMD — explicit hooks (win if set), e.g.
#        QUEUE_STOP_CMD='sudo systemctl stop ekdosi-queue'
#   2. the documented systemd unit ($QUEUE_SERVICE, default ekdosi-queue), when
#      systemctl exists AND this user may stop it (`systemctl stop` blocks until
#      the current job drains).
#   3. `php artisan ops:queue-drain` — portable: `queue:restart` (each worker
#      finishes its current job and exits) + WAIT until no job is reserved. The
#      app is already in maintenance mode here, and a worker started WITHOUT
#      `--force` sleeps while the app is down — so even a supervisor that
#      restarts it (systemd Restart=always, cron) brings up a worker that does
#      nothing until we are done. NEVER run the worker with `--force`.
# Only if the portable drain ALSO fails (a job still running after the timeout)
# do we abort — at that point nothing has changed yet.
QUEUE_SERVICE="${QUEUE_SERVICE:-ekdosi-queue}"
QUEUE_DRAIN_TIMEOUT="${QUEUE_DRAIN_TIMEOUT:-60}"
_have_unit() { command -v systemctl >/dev/null 2>&1 && systemctl cat "${QUEUE_SERVICE}.service" >/dev/null 2>&1; }
# True only if we could actually stop the unit (it may exist but be root-only).
# What actually stopped the worker: "" | hook | systemd. Only that path restarts it
# (the portable drain stops nothing — the supervisor/cron brings it back on `up`).
_stopped_by=""

# Returns 0 when the queue is drained (call inside `if !`, which suspends `set -e`
# for the body so the status propagates).
stop_queue_worker() {
  if [[ -n "${QUEUE_STOP_CMD:-}" ]]; then
    log "Draining queue worker (QUEUE_STOP_CMD)"
    if eval "${QUEUE_STOP_CMD}"; then _stopped_by="hook"; return 0; fi
    fail "QUEUE_STOP_CMD failed — falling back to the portable drain."
  elif _have_unit; then
    log "Draining queue worker (systemd: ${QUEUE_SERVICE})"
    if systemctl stop "${QUEUE_SERVICE}" 2>/dev/null; then _stopped_by="systemd"; return 0; fi
    fail "Cannot stop ${QUEUE_SERVICE} (no permission?) — falling back to the portable drain."
    echo  "  Tip: allow it once via sudoers, or set QUEUE_STOP_CMD — see INSTALL.md."
  fi

  # Portable fallback — no root, no systemd, works on shared hosting. It STOPS
  # nothing: the workers are told to exit and whoever supervises them (systemd,
  # cron) brings them back once we run `up`.
  log "Draining queue worker (portable: ops:queue-drain)"
  _stopped_by="drain"
  $ART ops:queue-drain --timeout="${QUEUE_DRAIN_TIMEOUT}" ${QUEUE_DRAIN_ARGS:-}
}

# Best-effort restart — never aborts the script (the app is already back up).
# Only restarts what WE stopped: with the portable drain nothing was stopped
# (the supervisor/cron brings the worker back by itself once `up` runs).
start_queue_worker() {
  [[ -z "$_stopped_by" ]] && return 0   # nothing was touched — nothing to start
  # A START hook always wins, even after a FAILED stop (starting an already
  # running worker is a no-op; a silently dead queue is not).
  if [[ -n "${QUEUE_START_CMD:-}" ]]; then
    log "Starting queue worker (QUEUE_START_CMD)"; eval "${QUEUE_START_CMD}" || true
    return 0
  fi
  if [[ "$_stopped_by" == "systemd" ]] || _have_unit; then
    log "Starting queue worker (systemd: ${QUEUE_SERVICE})"
    systemctl start "${QUEUE_SERVICE}" 2>/dev/null && return 0
  fi
  if [[ "$_stopped_by" == "drain" ]]; then
    # Nothing was stopped: the workers exited on their own and a supervisor
    # (systemd/cron) restarts them. If you start the worker BY HAND, do it now.
    log "Queue: workers were asked to exit — the supervisor/cron restarts them (start it yourself if you run it by hand)."
    return 0
  fi
  fail "The queue worker was stopped but could not be started back — START IT YOURSELF NOW."
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

# --- early data pre-flight (read-only, NO downtime) -------------------------
# The cheap checks run on the CURRENT checkout, before maintenance mode and
# before we touch the worker: a data problem should cost the operator nothing
# but a message. (The command only exists from v1.16 on, hence the guard; the
# authoritative run is still the one after checkout+composer, on the NEW code.)
if $ART list --raw 2>/dev/null | grep -q '^customers:afm-duplicates'; then
  log "Pre-flight (read-only): customers with a duplicate ΑΦΜ"
  if ! $ART customers:afm-duplicates; then
    fail "Duplicate customer ΑΦΜ — the UNIQUE(company_id, afm_key) migration will refuse."
    if $ART list --raw 2>/dev/null | grep -q '^customers:merge'; then
      echo "  Merge them first (nothing has changed, the app is still UP):"
      echo "    $ART customers:merge <keep-id> <drop-id> --dry-run"
      echo "    $ART customers:merge <keep-id> <drop-id>"
    else
      echo "  The merge tool ships WITH this update, so it is not on the current checkout yet."
      echo "  Re-run update.sh: it stops again right after the checkout, where you can run"
      echo "    $ART customers:merge <keep-id> <drop-id>"
      echo "  (or fix the wrong ΑΦΜ in the panel now, if they are NOT the same party)."
    fi
    exit 1
  fi
fi

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
# Maintenance mode is not cosmetic: the portable queue drain relies on a
# non-`--force` worker REFUSING to pick up work while the app is down. If `down`
# fails we have no such guarantee, so we stop before touching anything.
if ! $ART down --retry=15; then
  fail "Could not enter maintenance mode — aborting before any change."
  echo  "  A worker could then consume jobs against a half-migrated schema."
  exit 1
fi
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
  fail "A queue job is still running — aborting before any change."
  echo  "  Wait for it to finish (or raise QUEUE_DRAIN_TIMEOUT), then re-run. Nothing was deployed."
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

# --- pre-migration data checks --------------------------------------------
# Runs on the NEW code, BEFORE migrate, still inside the maintenance window.
# `customers:afm-duplicates` derives the ΑΦΜ identity in PHP while the
# afm_key column may not exist yet; the UNIQUE(company_id, afm_key) migration
# would refuse anyway — failing here gives the operator the list with the app
# still on the OLD schema (the failure trap above keeps maintenance ON).
log "Pre-migration check: customers with a duplicate ΑΦΜ"
if ! $ART customers:afm-duplicates; then
  fail "Duplicate customer ΑΦΜ found — resolve them, then re-run the update."
  echo  "  Merge them right here (the merge tool ships with this checkout and needs no schema change):"
  echo  "    $ART customers:merge <keep-id> <drop-id> --dry-run   # τι θα μεταφερθεί"
  echo  "    $ART customers:merge <keep-id> <drop-id>             # η συγχώνευση"
  echo  "  The app is in maintenance mode and on the OLD schema — do NOT edit customers in the panel"
  echo  "  in this state; use the command above (or deploy/rollback.sh first). See docs/updates-runbook.md."
  exit 1
fi

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

# Bust the cached update-check status — else the System page keeps showing the
# pre-deploy build/«νέα έκδοση διαθέσιμη» for up to cache_hours after an upgrade
# (the sidebar badge + ekdosi:version are already live from build.json; this just
# realigns the health page). Key mirrors UpdateChecker::CACHE_KEY.
$ART cache:forget ekdosi.updates.status || true

# A release may add new resources/pages → create their Permission rows now, so
# the role re-sync below (and the per-tenant role picker) has something to grant.
# Code-driven + idempotent: a no-op when nothing new was added.
log "Generating Shield permissions (new resources)"
$ART shield:generate --all --panel=admin --ignore-existing-policies --no-interaction || true

log "Syncing permissions (super admin + tenant roles)"
# NOTE: this also RE-SYNCS company_admin/operator for every tenant
# (TenantRoleProvisioner::ensureStandardRoles → syncPermissions), which is how a
# new resource's permissions reach the operators. It is a FULL sync: a manual
# per-tenant role customisation does NOT survive a deploy. Use
# `php artisan roles:reprovision --dry-run` to see the drift, or
# `roles:reprovision --tenant=X` to repair ONE tenant additively.
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
