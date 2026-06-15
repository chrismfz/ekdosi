# Updates runbook — release on dev, deploy on prod, roll back

How ekdosi moves from your dev VM (`ekdosi.myip.gr`) to a production box and
back, safely. The model: **dev box cuts versioned tags; prod deploys a tag with
one command; a pre-update DB snapshot is the rollback.**

> One codebase, many boxes. Prod always runs a **known-good tag**, never
> whatever happens to be on `main`. Keep the dev VM on a branch; pin prod to tags.

## One-time setup on the production box
Follow `INSTALL.md` once (AlmaLinux + PHP-FPM + MariaDB + systemd queue unit +
cron). Then:

```bash
# the deploy user owns the checkout (see INSTALL.md §5)
cd /var/www/ekdosi
# make sure both helper scripts are executable (they are in git, but after a
# fresh clone on some filesystems):
chmod +x deploy/update.sh deploy/rollback.sh
```

If `php`/`composer` aren't on the default PATH for the deploy user, export them:

```bash
export PHP=/usr/bin/php8.4
export COMPOSER=/usr/local/bin/composer
```

## The normal cycle

### 1. On the DEV box — cut a release
Work on a branch, keep `CHANGELOG.md` / `FEATURES.md` current (see CLAUDE.md),
then:

```bash
php artisan ekdosi:release --minor     # or --patch / --major
git push && git push --tags            # push the commit AND the new tag
```

`ekdosi:release` rolls `[Unreleased]` → a dated `[X.Y.Z]` heading, bumps
`config/app.php`, and prints the exact `git tag` command.

### 2. On the PROD box — deploy that tag
```bash
cd /var/www/ekdosi
deploy/update.sh v1.3.0        # omit the tag to take the latest tag
```

`update.sh` does, in order: pre-flight (clean tree) → **DB snapshot** →
maintenance ON → checkout tag → `composer install --no-dev` → `migrate --force`
→ asset build (if a lockfile exists) → `optimize` → `shield:sync-super-admin` →
`queue:restart` → maintenance OFF → `ops:health`. Idempotent; the maintenance
window is a few seconds.

## Rollback

Every `update.sh` run takes a snapshot first into `storage/app/db-snapshots/`
(keeps the 10 newest) and prints the rollback command at the end.

```bash
# code only — safe ONLY if the bad update ran NO migrations
deploy/rollback.sh <old-short-sha-or-tag>

# code + data — when the update changed the schema (the safe default if unsure)
deploy/rollback.sh <old-short-sha-or-tag> storage/app/db-snapshots/ekdosi-<ts>.sql.gz
```

> A forward migration may be irreversible, so **reverting code alone is not
> always enough** — restore the snapshot when the update touched the schema.

## Snapshots & a restore drill (do this BEFORE you trust production)
The snapshot/restore commands stand alone, too:

```bash
php artisan ekdosi:db-snapshot                 # → storage/app/db-snapshots/ekdosi-<ts>.sql.gz
php artisan ekdosi:db-snapshot --keep=10       # prune to the 10 newest
php artisan ekdosi:db-restore --file=…sql.gz   # DESTRUCTIVE (needs --force in production)
```

**Restore drill (run once on a staging/dev DB):** take a snapshot → make a
visible change → restore it → confirm the change is gone. An untested backup is
not a backup. These local snapshots are the *rollback* layer; the *off-site*,
scheduled, per-company archives are `spatie/laravel-backup` (see
`docs/operator-health.md` / Company → backups).

## Notes
- `ekdosi:db-snapshot`/`-restore` use the **active DB connection's** credentials
  (`.env`) — nothing typed by hand; the password goes via `MYSQL_PWD`, never the
  process list. MariaDB/MySQL only.
- They need `mysqldump` + `mysql` on PATH (the MariaDB client package — already
  present on an `INSTALL.md` box).
- `update.sh` refuses to run with a dirty working tree: never hand-edit code on
  prod; fix on dev, tag, deploy.
- After config changes that are cached, `update.sh`'s `optimize` re-caches; if
  you edit `.env` manually outside a deploy, run `php artisan config:clear`.
