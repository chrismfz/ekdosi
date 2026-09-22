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
# Pre-flight (before maintenance, same as update.sh): refuses an unknown ref,
# uncommitted TRACKED changes, or a checkout git's own dry run says would fail
# (e.g. an environment-edited skip-worktree / assume-unchanged file GIT_REF
# changes); then copies aside everything the forced checkout would destroy
# that git can't give back (untracked or gitignored files on a path GIT_REF uses)
# to storage/app/deploy-untracked/.
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
# Same three-step chain as deploy/update.sh (hook → systemd → portable
# `ops:queue-drain`, which needs no privileges); see the comment there.
QUEUE_SERVICE="${QUEUE_SERVICE:-ekdosi-queue}"
QUEUE_DRAIN_TIMEOUT="${QUEUE_DRAIN_TIMEOUT:-60}"
_have_unit() { command -v systemctl >/dev/null 2>&1 && systemctl cat "${QUEUE_SERVICE}.service" >/dev/null 2>&1; }
# What actually stopped the worker: "" | hook | systemd. Only that path restarts it
# (the portable drain stops nothing — the supervisor/cron brings it back on `up`).
_stopped_by=""
stop_queue_worker() {
  if [[ -n "${QUEUE_STOP_CMD:-}" ]]; then
    echo "▶ Draining queue worker"; if eval "${QUEUE_STOP_CMD}"; then _stopped_by="hook"; return 0; fi
    echo "⚠ QUEUE_STOP_CMD failed — falling back to the portable drain." >&2
  elif _have_unit; then
    echo "▶ Draining queue worker (systemd: ${QUEUE_SERVICE})"
    if systemctl stop "${QUEUE_SERVICE}" 2>/dev/null; then _stopped_by="systemd"; return 0; fi
    echo "⚠ Cannot stop ${QUEUE_SERVICE} (no permission?) — falling back to the portable drain." >&2
  fi
  echo "▶ Draining queue worker (portable: ops:queue-drain)"
  _stopped_by="drain"
  $ART ops:queue-drain --timeout="${QUEUE_DRAIN_TIMEOUT}" ${QUEUE_DRAIN_ARGS:-}
}
start_queue_worker() {
  [[ -z "$_stopped_by" ]] && return 0
  if [[ -n "${QUEUE_START_CMD:-}" ]]; then echo "▶ Starting queue worker"; eval "${QUEUE_START_CMD}" || true; return 0; fi
  if [[ "$_stopped_by" == "systemd" ]] || _have_unit; then
    echo "▶ Starting queue worker (systemd: ${QUEUE_SERVICE})"
    systemctl start "${QUEUE_SERVICE}" 2>/dev/null && return 0
  fi
  [[ "$_stopped_by" == "drain" ]] && { echo "▶ Queue: workers exited on their own — the supervisor/cron restarts them."; return 0; }
  echo "✗ The queue worker was stopped but could not be started back — START IT YOURSELF NOW." >&2
}

# --- pre-flight: the same guards as deploy/update.sh, all BEFORE maintenance --
# (so an abort changes nothing). The forced checkout below would otherwise wipe a
# hand edit to a tracked file, and replace an untracked file that $REF ships as
# tracked — both without a copy.

# Environment-managed files (cPanel rewrites public/.htaccess): skip-worktree, so
# the environment's edit neither reads as "dirty" below nor gets wiped. Same list
# and caveat as update.sh.
ENV_MANAGED_FILES=(public/.htaccess)
for _envfile in "${ENV_MANAGED_FILES[@]}"; do
  if git ls-files --error-unmatch "$_envfile" >/dev/null 2>&1; then
    git update-index --skip-worktree "$_envfile" 2>/dev/null \
      && echo "▶ Ignoring environment-managed $_envfile (skip-worktree)"
  fi
done

# Resolve the target first: the untracked probe below reads "<sha>:<path>", and
# an unknown ref would otherwise only fail at the checkout, in maintenance mode.
if ! TARGET_SHA="$(git rev-parse --verify --quiet "${REF}^{commit}")"; then
  echo "✗ Unknown ref: $REF — nothing was changed." >&2
  exit 1
fi

# TRACKED changes are a hard stop. Untracked ones never are: shield:generate
# leaves policy stubs behind, and a bare `git status --porcelain` would count them
# and block every rollback (the deadlock update.sh already hit).
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo "✗ Working tree not clean — commit/stash changes on the server first. Nothing was changed." >&2
  git status --short --untracked-files=no | sed 's/^/    /' >&2
  exit 1
fi

# Would the forced checkout FAIL? Ask git itself: a dry run of the same reset
# refuses exactly when the real one would — notably on a skip-worktree (the list
# above) or assume-unchanged file the environment edited (git decides by stat, not
# content), which the checkout then cannot overwrite («Entry … not uptodate.
# Cannot merge.», exit 128) — with the site already down. Refuse now instead. It
# does NOT refuse the untracked/ignored collisions below (the checkout overwrites
# those — hence the copies). Same check as update.sh and SelfUpdate. (NUL lists
# are read via temp files: CloudLinux CageFS has no /dev/fd, so process
# substitution dies there.)
_list="$(mktemp)"
if ! git read-tree -n -u --reset "$TARGET_SHA" >/dev/null 2>"$_list"; then
  echo "✗ The checkout of $REF would fail — refusing before maintenance. Nothing was changed." >&2
  sed 's/^/    /' "$_list" >&2
  sed -n "s/^.*Entry '\\(.*\\)' not uptodate.*\$/\\1/p" "$_list" | while IFS= read -r _f; do
    echo "  $_f was edited here while flagged skip-worktree/assume-unchanged. Keep a copy, then:" >&2
    echo "    git update-index --no-skip-worktree --no-assume-unchanged $_f && git checkout -- $_f" >&2
  done
  echo "  Re-run, then re-apply the environment's edit (cPanel: re-save the PHP handler)." >&2
  rm -f "$_list"
  exit 1
fi

# What the forced checkout destroys that git can NOT give back: anything on disk
# that isn't in the index — untracked OR gitignored — and collides with a path $REF
# tracks: the exact path, a directory where $REF has a file (deleted with all its
# contents), or a file where $REF has a directory. Copy those aside first, and
# refuse if a copy fails. Same algorithm as update.sh and SelfUpdate.
declare -A _in_index=() _risk_seen=()
_at_risk=()
git ls-files -z > "$_list"
while IFS= read -r -d '' p; do _in_index["$p"]=1; done < "$_list"
_risk() {
  if [[ -z "${_in_index[$1]:-}" && -z "${_risk_seen[$1]:-}" ]]; then
    _risk_seen["$1"]=1
    _at_risk+=("$1")
  fi
}
git ls-tree -r --name-only -z "$TARGET_SHA" > "$_list"
_sub="$(mktemp)"
while IFS= read -r -d '' p; do
  if [[ -n "${_in_index[$p]:-}" ]]; then
    continue   # tracked here too: git holds both versions
  fi
  # A symlink or a file standing where it has a directory: git replaces THAT
  # entry and never looks past it — so it is what's at risk, not $p reached
  # through it (a symlinked dir's contents live elsewhere and survive). The
  # shallowest such ancestor is the one git meets.
  _blocked=""
  a="$p"
  while [[ "$a" == */* ]]; do
    a="${a%/*}"
    if [[ -L "$a" || -f "$a" ]]; then _blocked="$a"; fi
  done
  if [[ -n "$_blocked" ]]; then
    _risk "$_blocked"
    continue
  fi
  if [[ -L "$p" || -f "$p" ]]; then
    _risk "$p"
  elif [[ -d "$p" ]]; then
    # a directory where it has a file: git deletes it, contents and all (links
    # and regular files only — `find` doesn't follow links, and a FIFO/socket
    # carries nothing to keep)
    if ! find "./$p" \( -type f -o -type l \) -print0 > "$_sub"; then
      echo "✗ Can't read everything inside '$p/' ($REF has a file there, so it would all be deleted unseen) — refusing. Nothing was changed." >&2
      rm -f "$_list" "$_sub"
      exit 1
    fi
    while IFS= read -r -d '' s; do _risk "${s#./}"; done < "$_sub"
  fi
done < "$_list"
rm -f "$_list" "$_sub"

if [[ ${#_at_risk[@]} -gt 0 ]]; then
  _backup="storage/app/deploy-untracked/$(date -u +%Y%m%d-%H%M%S)"
  for f in "${_at_risk[@]}"; do
    if ! mkdir -p -- "$_backup/$(dirname -- "$f")" || ! cp -pP -- "$f" "$_backup/$f"; then
      rm -rf -- "$_backup"   # a refused run leaves no copies behind
      echo "✗ Could not back up '$f' — refusing to overwrite it. Nothing was changed." >&2
      exit 1
    fi
    echo "  copied aside: $f → $_backup/$f"
  done
  echo "▶ $REF replaces or removes the files above — copies kept in $_backup/ (delete them once you've checked)."
fi

echo "▶ Maintenance mode ON"
# See update.sh: without maintenance mode the drain guarantees nothing.
if ! $ART down --retry=15; then
  echo "✗ Could not enter maintenance mode — aborting the rollback (a worker could write mid-restore)." >&2
  exit 1
fi
if ! stop_queue_worker; then
  echo "✗ A queue job is still running — aborting rollback (it would write into the DB mid-restore). Wait for it, or raise QUEUE_DRAIN_TIMEOUT." >&2
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
