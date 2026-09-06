# Release / versioning process (reference)

> Extracted from `CLAUDE.md` (2026-09-06). SemVer `X.Y.Z`, app semantics (canonical:
> `config('app.version')`). The CHANGELOG `[Unreleased]` block is the source of truth for
> the level, so a machine cuts it — you don't judge it by hand.

## Cut a release — `php artisan ekdosi:release`

With **no flag it INFERS the level** from `[Unreleased]` (rolls it → dated `[X.Y.Z]`, bumps
`config/app.php`):

- **minor (x.Y.0)** = `[Unreleased]` has a non-empty **`### Added`** (a new feature).
- **patch (x.x.Z)** = only `Fixed`/`Changed`/`Security`/`Removed` — no `Added`.
- **major (X.0.0)** = a milestone/epoch (PEPPOL live, a cutover) — a machine can't tell, so it
  stays **explicit `--major`**. `--minor`/`--patch` still override the inference.
- **`--check`** = non-destructive preflight (what would it cut? anything pending?) — wired into
  `clean.sh` step 5 so a forgotten bump surfaces on deploy.
- **`--commit --tag`** = also git-commit the roll + create `vX.Y.Z` (never pushes). Handy for a
  release cut straight on `main`; in the PR flow the tag is made post-merge instead.

## Post-merge tag (PR flow) — `sh tag-release.sh` (repo root)

- bare = STATUS + options (version, is-it-tagged, pending changes).
- **`--tag` = the WHOLE release in one safe step** — pull `main` → (if `[Unreleased]` has changes)
  run `ekdosi:release` + commit → preview + confirm (`-y` to skip) → push `main` → tag `vX.Y.Z` +
  push. It **refuses on a dirty tree** and **verifies `HEAD:config/app.php` == the tag version**
  before tagging (so it can never tag a commit that doesn't carry the bump — the failure mode that
  put `v1.12.0` on a `1.11.0` commit once). No more manual `ekdosi:release` → `git commit` →
  `git tag` dance.
