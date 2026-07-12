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

**Phase 2 (later, if wanted):** a one-click apply that *triggers `deploy/update.sh`*
server-side (queued job + maintenance mode), reusing that safety — **not** a naive
file-swap.
