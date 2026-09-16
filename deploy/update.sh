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
#   1. pre-flight: no uncommitted TRACKED changes (untracked files only warn;
#      any the release ships as tracked are copied to storage/app/deploy-untracked/)
#   2. fetch tags/commits
#   3. DB snapshot (rollback point)  →  storage/app/db-snapshots/
#   4. maintenance mode ON
#   5. checkout the target ref
#   6. composer install --no-dev
#   6b. pre-migration data checks (customers:afm-duplicates)
#   7. php artisan migrate --force
#   7b. passport:keys  (create the MCP/claude.ai OAuth keypair once, if missing)
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
warn() { printf '\n\033[1;33m! %s\033[0m\n' "$*"; }

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

# --- environment-managed files ---------------------------------------------
# cPanel's MultiPHP rewrites public/.htaccess (its «# php -- BEGIN cPanel-
# generated handler» block) whenever the PHP handler is (re)applied — a tracked
# file, so its edit makes the tree "dirty" and the pre-flight below would refuse
# to deploy EVERY time (and the force-checkout would wipe the handler block).
# Mark such files skip-worktree so git ignores the environment's edits: the
# pre-flight sees a clean tree AND the force-checkout leaves the block in place.
# Idempotent, and a no-op on hosts where nothing external touches the file.
# ⚠ HARD PRE-STEP: while skip-worktree is set, a release that CHANGES one of these
# files in the repo will NOT apply here — worse, the force-checkout below then
# HARD-ERRORS ("Entry '<file>' not uptodate. Cannot merge.", exit 128) and, since
# we're already in maintenance mode, the deploy ABORTS with the site DOWN. So if a
# release touches one of these files, clear the flag ON THE SERVER *before*
# deploying: git update-index --no-skip-worktree <file>  (this step re-sets it
# after). In practice public/.htaccess is Laravel boilerplate we ~never change.
ENV_MANAGED_FILES=(public/.htaccess)
for _envfile in "${ENV_MANAGED_FILES[@]}"; do
  if git ls-files --error-unmatch "$_envfile" >/dev/null 2>&1; then
    git update-index --skip-worktree "$_envfile" 2>/dev/null \
      && log "Ignoring environment-managed $_envfile (skip-worktree)"
  fi
done

# --- pre-flight -------------------------------------------------------------
# TRACKED changes are a hard stop: the checkout below would clobber real edits.
# (Environment-managed files above are already skip-worktree, so they don't count.)
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  fail "Working tree not clean — commit/stash changes on the server first (don't edit code on prod)."
  git status --short --untracked-files=no | sed 's/^/    /' >&2
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

# --- untracked files: report + protect, never refuse -------------------------
# They USED to be a hard stop, and that deadlocked the box: `shield:generate`
# (step 10) writes a policy file for any resource that ships without one, so one
# deploy left an untracked artefact behind and EVERY later deploy refused — with
# no way out from inside the script (`git stash` does not touch untracked files,
# and the operator is told not to edit code on prod). So we report them instead.
# The checkout below is `--force`, so an untracked file whose path IS tracked in
# the target ref gets REPLACED by the release's version (exactly what should
# happen to a generated stub). Those we name separately AND copy aside first, so
# the deploy never stops and nothing is ever destroyed unseen.
# NUL-separated + quotePath=off: git C-quotes non-ASCII paths by default
# («Πελάτες.md» → "\316\240…"), which would break the cat-file probe below on a
# Greek filename — exactly the kind we have.
# Read via a temp file, NOT process substitution `< <(...)`: CloudLinux CageFS
# does not expose /dev/fd, so `< <(…)` dies with «/dev/fd/63: No such file or
# directory». A real file works everywhere and preserves the NUL separation.
_untracked=()
_untracked_list="$(mktemp)"
git -c core.quotePath=false ls-files --others --exclude-standard -z > "$_untracked_list"
while IFS= read -r -d '' f; do
  _untracked+=("$f")
done < "$_untracked_list"
rm -f "$_untracked_list"

if [[ ${#_untracked[@]} -gt 0 ]]; then
  _clobbered=()
  for f in "${_untracked[@]}"; do
    if git cat-file -e "${TARGET_SHA}:${f}" 2>/dev/null; then
      _clobbered+=("$f")
    fi
  done

  warn "Untracked files present — this deploy leaves them alone:"
  printf '    %s\n' "${_untracked[@]}"

  if [[ ${#_clobbered[@]} -gt 0 ]]; then
    # These the checkout WILL replace (it is `--force`). For a generated artefact
    # that is exactly right, and it must not stop the deploy — that rigidity is
    # what deadlocked prod. But we never destroy an operator's file blind: copy
    # them aside FIRST, and abort if the copy fails.
    _backup="storage/app/deploy-untracked/$(date -u +%Y%m%d-%H%M%S)"
    warn "…except these, which $REF ships as tracked files — the checkout REPLACES them:"
    printf '    %s\n' "${_clobbered[@]}"
    for f in "${_clobbered[@]}"; do
      if ! mkdir -p "$_backup/$(dirname "$f")" || ! cp -p "$f" "$_backup/$f"; then
        fail "Could not back up '$f' to $_backup — refusing to overwrite it. Nothing was deployed."
        exit 1
      fi
    done
    ok "Copies kept in $_backup/ (delete them once you've checked)."
  fi
fi

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

# --- MCP OAuth (Passport) keys: create once if missing (idempotent) ---------
# The claude.ai remote connector authenticates via OAuth 2.1, for which Passport
# needs an RSA keypair (storage/oauth-private.key + oauth-public.key — a per-host
# secret, gitignored via /storage/*.key). laravel/passport is a committed dep and
# the oauth_* tables ship in the baseline schema, so composer+migrate already put
# everything in place EXCEPT the keys — the one step everyone forgets (it lived
# only in MCP.md §8). Generate them ONLY when absent: never rotate an existing
# keypair, that would invalidate every live OAuth token. Skipped when the keys are
# supplied via PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY (multi-node shared keypair).
if $ART list --raw 2>/dev/null | grep -q '^passport:keys'; then
  _pk=storage/oauth-private.key
  _pub=storage/oauth-public.key
  if [[ -n "${PASSPORT_PRIVATE_KEY:-}" ]] || grep -qE '^PASSPORT_PRIVATE_KEY=.' .env 2>/dev/null; then
    # Keys supplied via env (multi-node shared keypair) — checked in the deploy
    # shell AND .env; don't write files (a systemd-only Environment= we can't see
    # is still harmless: config env wins over the file at runtime).
    ok "Passport OAuth keys come from PASSPORT_*_KEY env — not generating files."
  elif [[ -f "$_pk" && -f "$_pub" ]]; then
    ok "Passport OAuth keys already present — leaving them untouched."
  elif [[ -f "$_pk" || -f "$_pub" ]]; then
    # Half a keypair (interrupted gen / partial restore): `passport:keys` without
    # --force ABORTS because one file exists, so we won't silently no-op. We also
    # won't auto --force (it could clobber a key live tokens rely on) — surface it.
    warn "Partial Passport keypair — one of ${_pk}/${_pub} is missing. NOT auto-generating. Fix by hand: $ART passport:keys --force (see MCP.md §8)."
  else
    log "Generating Passport OAuth keys (first time on this host — MCP claude.ai connector)"
    $ART passport:keys --no-interaction \
      || warn "passport:keys failed — the claude.ai MCP connector stays down until keys exist (see MCP.md §8)."
  fi
fi

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
