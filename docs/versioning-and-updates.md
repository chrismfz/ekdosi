# Versioning & update checking

Two identifiers, kept distinct on purpose:

| | What it answers | Who sets it | Where |
|---|---|---|---|
| **SemVer `X.Y.Z`** | *What kind* of release is this (milestone / feature / fix)? | `php artisan ekdosi:release` — **auto-infers** minor/patch from `[Unreleased]`; `--major` explicit for a milestone | `config('app.version')` + `CHANGELOG.md` |
| **Build stamp `2026.07.11-150101`** | *Which exact build* is on this box right now? | **Automatic**, derived from the git commit at deploy | `storage/app/build.json` (per-box, git-ignored) |

The SemVer is a human judgement (a machine can't tell a milestone from a fix), so
it stays a deliberate command — **not** auto-bumped per PR. In the **PR flow** the
release commit rolls the CHANGELOG + `config/app.php` on the branch, and the `vX.Y.Z`
tag is created AFTER merge with **`sh tag-release.sh --tag`** (bare = STATUS + options;
reads the version from `config/app.php`, pulls `main`, tags, pushes). The build stamp is the
support/diagnostics identity and the updater's compare key, so it's automatic and
never hand-committed (a committed timestamp is churn + wrong the moment you deploy
at another time).

## How the build stamp is produced

`deploy/update.sh`, right after it checks out the target ref, writes:

```json
{"sha":"a1b2c3d","committed_at":"2026-07-11T12:01:01+00:00","ref":"v1.1.0"}
```

`App\Support\BuildInfo` reads it and formats `committed_at` into the **app timezone**
(`Europe/Athens`) as `Y.m.d-His`. Resolution order:

1. `storage/app/build.json` (the production path, written by the deploy script)
2. a live `git log -1` read — **local/development only** (never shells out in prod)
3. nothing → the build stamp is hidden, the SemVer is still shown

Shown as `v1.1.0 · 2026.07.11-150101 (a1b2c3d)`:
- under the brand in the panel sidebar (all roles — «what am I running?»)
- `php artisan ekdosi:version` (`--json` for support/cron)

## Update checking — Phase 1 is READ-ONLY

`App\Services\Updates\UpdateChecker` compares the deployed version against the repo's
latest GitHub **release** (fallback: highest **tag**) and reports «N commits behind /
νέα έκδοση Y διαθέσιμη» on the super_admin **«Υγεία συστήματος»** page + `ekdosi:version --check`.

- Cached (default 6h, `config/ekdosi.php` → `updates`), degrades gracefully offline
  (falls back to the last good result, never throws into the UI), and the current
  build is always reported regardless.
- Token (`EKDOSI_UPDATE_TOKEN` / `GITHUB_TOKEN`) is needed **only for a private repo**.
  Public repos work unauthenticated within the cache window. Use a **read-only** PAT.
- It **never** downloads or applies anything.

### Why not a one-click in-app updater (yet)

Packages like `salahhusa9/laravel-updater` do «download zip → replace files →
migrate». For a multi-tenant **money** app that's risky: no pre-update **DB snapshot**
(no rollback if a migration on a money table fails), it may not **restart the systemd
queue worker** (a stale worker on a half-migrated schema is exactly the class of
money bug the deploy pipeline guards against), it runs as the web user (permissions on
`vendor/` / `systemctl`), and `composer install` inside a web request is fragile.

We already have a safer upgrade path: **`deploy/update.sh`** (snapshot → maintenance →
checkout → migrate → optimize → shield → `queue:restart` → `ops:health`) + `rollback.sh`.

**Phase 2 (the design below):** a one-click, UI-driven apply that reuses that same
safety ordering — **not** a naive file-swap. The section below is the agreed design;
it is not built yet. Build it in the order in §"Implementation order".

## In-app update (Phase 2) — shared-hosting-first design

### The constraint that shapes everything

The target is **not only our own VPS**. ekdosi must also update cleanly on
**shared hosting** (cPanel / DirectAdmin / CloudLinux), where there is **no root,
no `sudo`, no `systemd`/`systemctl`**. So the design takes nothing privileged for
granted.

The upside of that same environment: web/FPM, the cron scheduler and any deploy
step all run as **the same account user**, which already owns the whole tree
(`vendor/`, `.git`, `storage/`, everything). There is **no privilege boundary to
cross** — so an in-app updater needs **zero** special grants (no sudoers line, no
`systemd-run`). It just does, as that user, what `deploy/update.sh` already does.

Three real problems remain, each solved without privilege:

### 1. Detached execution → via the cron scheduler (not the web request, not the queue worker)

The web action does **not** run the update. It writes one `UpdateRun` row
(`status=queued`, target SHA) and returns. The update is picked up by the
**scheduler that already runs from cron** (`* * * * * php artisan schedule:run` —
ekdosi already requires it for its scheduled features):

```php
// routes/console.php
Schedule::command('ekdosi:self-update --pending')
    ->everyMinute()
    ->when(fn () => \App\Models\UpdateRun::hasPending())
    ->withoutOverlapping();   // cache-lock store — the same one the rest of the schedule uses
```

Each `schedule:run` tick is its own short-lived process tree, fully decoupled from
the web request **and** from any persistent queue worker. This avoids the
worker-suicide trap: if the update ran on the `ekdosi-queue` worker and then
restarted it, it would kill the very process running the update. It also needs no
`nohup`/`fastcgi_finish_request`/`systemd-run` tricks — it works on the most
locked-down shared host, as long as the one cron line (already needed) is present.

*Fallback:* a host with no cron at all can dispatch to the queue instead — on shared
hosting we never force-restart the worker, so `queue:restart` stays graceful and the
suicide case does not arise.

### 2. Rug-pull mitigation (code changes under the running process)

A PHP self-updater's real hazard: while `ekdosi:self-update` runs, `composer install`
swaps `vendor/` and `git checkout` swaps app files underneath it, so classes
autoloaded *after* the swap can mismatch. Mitigation is the same shape as
`deploy/update.sh`: the command is a **thin orchestrator that shells out to
subprocesses** — `git`, `composer`, and **fresh `php artisan …`** invocations (each a
new PHP process that loads the new code) via `Symfony\Component\Process`. The parent
process does minimal PHP work after the swap and autoloads no new classes itself.

### 3. Restart without `systemctl`

- **Queue worker** — `php artisan queue:restart` is just a cache flag; **no root**.
  A daemon or cron-based `queue:work --stop-when-empty` reads it and restarts itself.
- **php-fpm / opcache** (so the new code is actually served) — we do **not** reload
  the FPM daemon. Either the update hits an HMAC-protected internal route
  (`/internal/opcache-flush`) that calls `opcache_reset()` **inside** the FPM pool
  (clears the shared opcache, no root), or we rely on `opcache.validate_timestamps=On`
  (default on many shared hosts) picking up changed files within seconds.
- **Maintenance mode** (`artisan down`/`up`) and the **DB snapshot**
  (`ekdosi:db-snapshot`, already pure PHP + `mysqldump`) are pure PHP — work anywhere.

### The command: one `ekdosi:self-update`, two strategies

Rather than *wrap* `deploy/update.sh` (which assumes a shell + `systemctl`), the
default is a **PHP port of its orchestration** in an artisan command — same ordered
steps, zero privilege:

```
down ▸ ekdosi:db-snapshot --keep=10 ▸ git fetch + checkout <sha>
     ▸ composer install --no-dev --optimize-autoloader ▸ php artisan migrate --force
     ▸ optimize ▸ shield:generate + shield:sync-super-admin ▸ queue:restart
     ▸ opcache flush ▸ up ▸ ops:health          (each step a subprocess; output → UpdateRun)
```

**`composer install`, never `composer update` — deliberately.** The updater installs
from the **committed `composer.lock`** (deterministic, identical on every box).
`composer update` does the opposite — it bumps dependencies to newer versions and
**rewrites `composer.lock`**, i.e. changes code that isn't in git, with no review. For
a production money app that breaks the whole "deploy a known commit" model, so it is
**not** exposed in the UI. Dependency bumps flow through git like everything else: run
`composer update` on the dev box → commit the new lock → cut a release → the in-app
updater `composer install`s it.

A config knob selects the execution strategy, so the **same UI** drives both
environments:

| `EKDOSI_UPDATE_STRATEGY` | Runs | Restart FPM/worker | Where |
|---|---|---|---|
| `php` (default, portable) | the PHP orchestration above | opcache self-hit + `queue:restart` | shared hosting **and** VPS |
| `script` (VPS opt-in) | `deploy/update.sh <sha>` | whatever the script already does | our managed hosts |

`script` mode is a thin optional extra for boxes that have the shell tooling and
prefer the existing bash path; it is **not** required and never the shared-hosting path.

### Authentication — one token covers both channels

There are two distinct auth channels; keep them straight:

- **The read-only "is there an update?" check** (GitHub API — releases/tags/commits)
  already uses `EKDOSI_UPDATE_TOKEN` (`config/ekdosi.php` → `updates.token`).
- **The `git fetch`/`checkout` itself** uses whatever the on-disk git remote is
  configured with — **not** the config token, today.

Since a single token is preferred, the `php` strategy performs an **authenticated
https fetch with the same token** (`https://x-access-token:${TOKEN}@github.com/<repo>`),
so one PAT covers both. (An SSH deploy key is the alternative — cleaner boundary,
credential never in app config — but the token path is fine for a private-repo pull.)
The token is **never** rendered in the UI or persisted to logs — redact
`x-access-token:…` / `token=` / `password=` before writing anything (as ngm's updater
does).

### UI + logging

Reuse the existing `RunFirebirdImport` job + `FirebirdImportRun` model +
`FirebirdImportRuns` resource pattern (operator-triggered, long-running, live output
in the panel):

- **`UpdateRun` model** — `status` (`queued` → `running` → `succeeded` | `failed` |
  `rolled_back`), `phase`, `from_sha`, `to_sha`, `snapshot_file`, `output` (longtext),
  `error_message`, `started_at`, `finished_at`. Because the update runs in a separate
  (cron) process, the UI simply reads this row — no streaming plumbing.
- The command writes **live output** via a `Symfony\Component\Process` output callback
  (append per line to the row **and** to `storage/logs/updates/<id>.log`, a permanent
  audit trail), and advances `phase` at each stage.
- **`UpdateRuns` Filament resource** (List + View), super_admin-gated exactly like
  `SystemHealth` (`TenantRoleProvisioner::isSuperAdminAnywhere`, **not** a per-tenant
  Shield permission a company_admin would also hold). The View page uses `wire:poll.2s`
  (built-in — no Echo/websockets) to re-render a **phase checklist** + the live output
  `<pre>` + status badge. History of every update lives here.
- **Home** for the trigger: an "Εγκατάσταση ενημέρωσης" action on the existing
  **`SystemHealth`** page (it already shows the `UpdateChecker` status + `BuildInfo`).
  The action locks the exact commit SHA the operator saw at check-time, creates the
  `UpdateRun`, and returns. A **"Επαναφορά"** action (Phase B) runs
  `git checkout <from_sha>` + `ekdosi:db-restore --file=<snapshot> --force` as a new
  `UpdateRun`.

### Preflight + honest caveats

- **Preflight** — before offering the button, check that `exec`/`proc_open` are not
  disabled (some tight cPanel setups block them); if they are, in-app update is not
  possible and the UI says so (manual path only). Also surface: dirty working tree
  (refuse, as `update.sh` does), and the current strategy.
- **opcache visibility** — after a `php`-strategy update the operator's browser may
  briefly hit stale bytecode until the opcache flush/route hit lands; the flush step
  handles it, but note it.
- **No atomic code rollback** like ngm's binary swap — the PHP equivalent is
  `git checkout <from_sha>` + DB snapshot restore, kept on the `UpdateRun`.
- **`composer install`** may need `COMPOSER_MEMORY_LIMIT=-1` and real time — fine
  under cron (no web timeout).

### Security

- **super_admin only**, cross-tenant (system op, not a per-company permission).
- **Lock the SHA** the operator saw and **re-verify** the remote head still equals it
  immediately before applying (guards a race where the branch advances between check
  and apply) — the same discipline as ngm's Prepare→Activate boundary checks.
- **Token redaction** in all persisted/displayed output; **HMAC** on the internal
  opcache-flush route; snapshots stay in `storage/app/db-snapshots/` (never web-served,
  already excluded from spatie file backups).

### Implementation order

- **Phase A ✅ BUILT** — `UpdateRun` model + migration · `ekdosi:self-update`
  (both `php` and `script` strategies) · the `routes/console.php` scheduler hook
  (`self_update`, gated on `hasPending()`) · the `SystemHealth` «Εγκατάσταση
  ενημέρωσης» action · the super_admin `UpdateRuns` resource (live-poll View +
  history) · the signed `/internal/opcache-flush` route · config keys
  (`EKDOSI_UPDATE_APPLY` — default OFF, `EKDOSI_UPDATE_STRATEGY`,
  `EKDOSI_SCHEDULE_SELF_UPDATE`). A working «update» button with a live phase log.
  - **On-failure policy:** once the app has gone into maintenance, a failed apply
    **leaves it down** (deliberate, like `deploy/update.sh`) — recover with Phase B
    «Επαναφορά» or `php artisan up`. The snapshot is taken *after* `down` (exact
    rollback point); a preflight failure (dirty tree / no `.git` / no `proc_open`)
    aborts *before* `down`, so the app is never touched.
  - **UI note:** the trigger lives on `SystemHealth`; the run detail is the
    `UpdateRuns` resource View, which auto-polls its sections while non-terminal
    (Filament-native `->poll('3s')`, the `FirebirdImportRun` pattern) rather than a
    hand-built `wire:poll` blade — same live effect, no custom panel.css.
- **Phase B** — "Επαναφορά" action (git checkout + `db-restore`) + snapshot picker.
- **Phase C** — a dry-run/preflight preview (commits-behind + pending migrations)
  before apply · richer per-step checklist UI.
