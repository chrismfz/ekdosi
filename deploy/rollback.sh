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

echo "▶ Maintenance mode ON"
$ART down --retry=15 || true
trap '$ART up || true' EXIT

echo "▶ Checkout $REF"
git checkout --force "$REF"

echo "▶ composer install (--no-dev)"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction

if [[ -n "$SNAP" ]]; then
  echo "▶ Restoring DB from $SNAP"
  $ART ekdosi:db-restore --file="$SNAP" --force
fi

echo "▶ optimize + queue restart"
$ART optimize
$ART queue:restart

echo "▶ Maintenance mode OFF"
$ART up
trap - EXIT

printf '\033[1;32m✓ Rolled back to %s%s\033[0m\n' "$REF" "${SNAP:+ (+ DB restored)}"
