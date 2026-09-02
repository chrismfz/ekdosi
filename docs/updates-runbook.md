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

**Queue-worker drain (OPS-6).** `update.sh`/`rollback.sh` drain the queue worker
before `migrate`/restore, so a long in-flight job (e.g. the 30-min Firebird
import) can't write into a half-migrated/half-restored schema. Three ways, tried
in order — **the last needs no privileges, so this works on cPanel / Plesk /
DirectAdmin / shared hosting and with a cron-driven worker too**:

1. `QUEUE_STOP_CMD` / `QUEUE_START_CMD` — explicit hooks, if set;
2. the `ekdosi-queue` systemd unit, when `systemctl` exists **and this user may
   stop it** (a unit that exists but is root-only just falls through);
3. `php artisan ops:queue-drain` — `queue:restart` (each worker finishes its
   current job and exits) + wait until no job is reserved.

Only if a job is STILL running after `QUEUE_DRAIN_TIMEOUT` (default 60s) does the
deploy abort — and then nothing has changed yet. The app is in maintenance mode
throughout, and a worker started **without `--force`** sleeps while it is down,
so a supervisor that restarts the worker mid-deploy does no harm. Never run the
worker with `--force`.

```bash
export QUEUE_STOP_CMD='sudo systemctl stop ekdosi-queue'   # step 1 (see INSTALL.md for the sudoers line)
export QUEUE_START_CMD='sudo systemctl start ekdosi-queue' # ALWAYS set this when you set STOP
export QUEUE_SERVICE=my-queue        # a different unit name
export QUEUE_DRAIN_TIMEOUT=300       # a box that runs the long Firebird import
export QUEUE_DRAIN_ARGS=--assume-idle  # redis/SQS: accept an unverifiable queue (last resort)
```

> Set `QUEUE_STOP_CMD` **and** `QUEUE_START_CMD` together — with only STOP the scripts stop the worker
> and shout at the end that you must start it yourself. On a queue we cannot inspect (redis/SQS) the
> portable drain REFUSES rather than green-light a `migrate`: set the hooks, or accept the risk with
> `QUEUE_DRAIN_ARGS`.

## The normal cycle

### 1. On the DEV box — cut a release
Work on a branch, keep `CHANGELOG.md` / `FEATURES.md` current (see CLAUDE.md),
then:

```bash
php artisan ekdosi:release --minor     # or --patch / --major
git push && git push --tags            # push the commit AND the new tag
```

`ekdosi:release` rolls `[Unreleased]` → a dated `[X.Y.Z]` heading, bumps
`config/app.php`, and prints the exact `git tag` command. With **no flag** it
**auto-infers** minor/patch from `[Unreleased]` (`--major` explicit for a milestone).

> **PR flow (the common path):** if the release commit goes through a PR, cut the
> tag AFTER merge with **`sh tag-release.sh --tag`** (repo root) — bare `sh tag-release.sh`
> first shows STATUS + options (version, is-it-tagged, pending). It reads the version
> from `config/app.php`, pulls `main`, tags `vX.Y.Z`, and pushes. Use the inline
> `git push --tags` above only when you cut the release straight on a branch you push yourself.

### 2. On the PROD box — deploy that tag
```bash
cd /var/www/ekdosi
deploy/update.sh v1.3.0        # omit the tag to take the latest tag
```

`update.sh` does, in order: pre-flight (clean tree) → maintenance ON →
**drain the queue worker** → **DB snapshot** (after `down`, so no write is lost
between snapshot and the window) → checkout tag → `composer install --no-dev` →
`migrate --force` → asset build (if a lockfile exists) → `optimize` →
`shield:sync-super-admin` → `queue:restart` → maintenance OFF → **restart the
worker** → `ops:health`. Idempotent; the maintenance window is a few seconds.
A snapshot failure is a clean abort (maintenance lifted, worker restarted,
nothing deployed). `ops:health` now returns a real exit code (0/1/2), so a
non-zero tail on the deploy flags a real issue.

## Release-specific pre-flight: the customer ΑΦΜ unique constraint

The release that adds `UNIQUE(customers.company_id, afm_key)` **refuses to migrate** while any
tenant has two customers with the same ΑΦΜ (soft-deleted included). Before `update.sh`:

```bash
php artisan customers:afm-duplicates          # exit 0 = clean, 1 = duplicates listed
```
`deploy/update.sh` runs this itself — after checkout + composer and **before** `migrate`, inside
the maintenance window (the command derives the identity in PHP while the `afm_key` column
doesn't exist yet). On duplicates the update aborts with the list, maintenance stays ON, and
the schema is untouched; fix the data and re-run `update.sh`. To look ahead without deploying,
run it on any checkout of the new tag against the production DB.

Resolve each group (fix the wrong ΑΦΜ, or move its documents and delete the duplicate), then
deploy. Placeholder ΑΦΜ (000000000 …) and blanks are NOT identities and never collide.

**Merge them with the tool — no SQL:**

```bash
php artisan customers:merge <keep-id> <drop-id> --dry-run   # τι θα μεταφερθεί
php artisan customers:merge <keep-id> <drop-id>             # η συγχώνευση (ρωτά πρώτα)
```

`customers:afm-duplicates` marks with ✓ the row `customers:merge` would keep (the one carrying the
most documents) and prints the exact command per pair. The merge moves everything (παραστατικά,
πληρωμές, προσφορές, επαφές, ΔΑ, συμβόλαια, σημειώσεις, συνημμένα, ετικέτες, ιστορικό) in ONE
transaction, records the fields that differed as a pinned note on the survivor, and **force-deletes**
the loser (a soft-deleted twin would keep holding the ΑΦΜ). There is also a «Συγχώνευση με άλλον
πελάτη» action on the customer page.

Note it needs no schema change, so it works in either direction: `update.sh` now runs the duplicate
check **before** maintenance mode (nothing has changed, the app is still up), and the check after
checkout is the authority. If you are stopped mid-deploy, run the merge right there — but do **not**
edit customers in the panel in that state (new code, old schema).

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

**Restore drill (do it — an untested backup is not a backup):** take a snapshot
→ make a visible change → restore it → confirm the change is gone. These local
snapshots are the *rollback* layer; the *off-site*, scheduled, per-company
archives are `spatie/laravel-backup` (see `docs/operator-health.md` / Company →
backups).

**Cadence (OPS-15) — don't let the drill be a one-off:**

| When | What to drill | Why |
|------|---------------|-----|
| **Before go-live** (per tenant) | Full restore drill on a *staging copy* of that tenant's real data. | The `ekdosi:go-live-check` backup gate only confirms a *recent successful backup exists* — it can't prove the archive actually restores. The drill is the human half. |
| **Quarterly** (every ~3 months) | Restore the newest **off-site** per-company archive (`spatie`) to a scratch DB and spot-check invoices/marks. | Off-site pushes can silently rot (rotated creds, changed bucket, disk full). |
| **After any change to** the backup pipeline, DB engine/version, or destinations | One restore of each affected tenant's archive. | A config change can break restore without breaking backup. |
| **After the go-live-check backup gate WARNs** «καμία επιτυχημένη εκτέλεση» / «τελευταίο πριν N ημ.» | Run a fresh backup, then a restore drill, before trusting the gate. | The gate is telling you the evidence is missing or stale. |

Log each drill (date, tenant, archive restored, outcome) somewhere durable — a
passed drill is the only thing that turns «backups are enabled» into «we can
actually recover». For a restore when the **APP_KEY is lost**, see
`docs/dr-without-app-key.md`.

## Notes
- `ekdosi:db-snapshot`/`-restore` use the **active DB connection's** credentials
  (`.env`) — nothing typed by hand; the password goes via `MYSQL_PWD`, never the
  process list. MariaDB/MySQL only.
- They need `mysqldump` + `mysql` on PATH (the MariaDB client package — already
  present on an `INSTALL.md` box).
- **Connection transport:** if the connection defines a unix socket
  (`DB_SOCKET` → `unix_socket`), the commands use `--socket=…`; otherwise
  `--host`/`--port`. So both `localhost` (socket) and TCP setups work.
- **DB privileges:** the snapshot dumps routines/triggers/events, so the DB user
  needs the matching privileges. The INSTALL.md grant (`GRANT ALL PRIVILEGES ON
  ekdosi.*`) covers them. A least-privilege user without TRIGGER/EVENT/CREATE
  ROUTINE will make `mysqldump` fail — which **aborts the update** (fails safe,
  no half-backup), but fix the grant before relying on snapshots.
- **Clean-slate restore (OPS-7):** `db-restore` now `DROP DATABASE` +
  `CREATE DATABASE`s the **restore connection's** database first, then loads the
  (DB-agnostic) snapshot. So a table a later (bad) migration ADDED — which used to
  survive a restore and make the next deploy fail with "table already exists" — is
  wiped, and the target db is recreated if it's missing (self-heals an interrupted
  restore). It always targets the connection's db (matching the confirmation
  prompt), not a name baked into the snapshot. After restoring an OLDER snapshot,
  run `php artisan migrate --force` to re-apply forward migrations. The db user
  needs DROP/CREATE on the database (the INSTALL.md `GRANT ALL ON ekdosi.*` covers it).
- **Pin prod to tags.** `update.sh <branch>` checks out the local branch, which
  may lag `origin` after a fetch; tags are immutable and always correct. Deploy
  tags on prod; use branches only on the dev VM.
- `update.sh` refuses to run with a dirty working tree: never hand-edit code on
  prod; fix on dev, tag, deploy.
- **On failure, `update.sh`/`rollback.sh` STAY in maintenance mode** on purpose
  (a half-applied update must not be served). They print the rollback command;
  bring the app back with `php artisan up` only once it's healthy.
- After config changes that are cached, `update.sh`'s `optimize` re-caches; if
  you edit `.env` manually outside a deploy, run `php artisan config:clear`.
- **Snapshots are sensitive.** A `db-snapshot` is a full plaintext dump that
  includes the plaintext-at-rest secrets (myDATA/WHMCS/GSIS/SMTP). They live in
  `storage/app/db-snapshots/` (outside `public/`, never web-served) and are
  excluded from the spatie file backups. Keep the dir owner-only, set a retention
  (`--keep`), and don't copy them to a less-trusted location uncompressed.
