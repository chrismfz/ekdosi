# Laravel Boost — dev tooling setup

> **Status:** installed (`laravel/boost`, `require-dev`). **Zero production
> footprint** — it's a dev dependency (skipped by `composer install --no-dev`)
> and its commands/MCP server only register when `APP_ENV=local` **or**
> `APP_DEBUG=true`. Never enable it on the prod host.

## What Boost is (and why we added it)

Boost is a **dev-time MCP server** that grounds an AI coding assistant (Claude
Code, Cursor, …) in *this* app's real state instead of guessing. It gives the
assistant tools to:

- read the **actual MariaDB schema** + run read-only queries (75 migrations is a
  lot to hold in your head),
- run **`tinker`** snippets to exercise a service (`InvoiceBalance`,
  `RecomputeInvoiceTotals`, `SalesReconciler`, …) instead of reading it cold,
- look up **version-correct docs** for the *exact* packages we run (Filament 5,
  `firebed/aade-mydata` 5.10, Laravel 13), not stale generic answers,
- read application **logs** and config.

It is purely a development aid for polishing / new features / BACKLOG work. It is
**not** the in-app AI «Βοηθός» feature — that's a separate runtime thing, see
`docs/ai-assistant-blueprint.md`. (Boost ≠ the assistant.)

## How it's wired in this repo

- **`.mcp.json`** (repo root, committed) — the Claude Code project MCP config. It
  points at `php artisan boost:mcp`. Claude Code will prompt you once to approve a
  project-scoped MCP server; approve it.
- **`composer.json`** — `laravel/boost` under `require-dev`.

So for **Claude Code**, connection is already done *as long as you run locally
with `APP_ENV=local` (or `APP_DEBUG=true`)*. Nothing else to commit.

## Connecting — Claude Code (the primary case)

1. Make sure you're on a dev checkout with a working `.env` and a reachable
   MariaDB (Boost's DB tools need a live connection).
   ```
   # .env
   APP_ENV=local        # (or) APP_DEBUG=true
   ```
2. `composer install` (with dev deps — the default).
3. Open the project in Claude Code from the repo root. It reads `.mcp.json`,
   asks to approve the `laravel-boost` server → approve.
4. Verify: in Claude Code run `/mcp` (you should see `laravel-boost` connected),
   or from a shell `php artisan boost:mcp` should start and wait on stdio.

> If `APP_ENV=production` / no `APP_DEBUG`, the `boost:*` and `mcp:*` commands are
> **not registered** (by design) and the MCP server won't start. That's why it
> can't run in the remote/CI container — only on a developer's local box.

## Connecting — other editors (Cursor, Copilot, Zed, Codex, …)

Boost ships an interactive installer that writes the right config for each
editor (Cursor uses `.cursor/mcp.json`, etc.). Run it locally and pick your
agent(s):

```bash
php artisan boost:install            # interactive: pick agent(s) + features
```

Useful flags (so you don't get more than you want):

```bash
php artisan boost:install --mcp          # ONLY the MCP server config (recommended)
php artisan boost:install --guidelines   # also inject Boost's AI guidelines
php artisan boost:install --skills        # also install agent skills
```

### A note on `--guidelines` and our `CLAUDE.md`

`boost:install --guidelines` appends a delimited
`<laravel-boost-guidelines>…</laravel-boost-guidelines>` block to `CLAUDE.md`.
Our `CLAUDE.md` is hand-curated and already very specific (locked architectural
decisions, the VAT/rounding math, conventions). **Prefer `--mcp` only.** If you
do want the guidelines block, keep it clearly inside its tags so it never gets
tangled with our own sections — and remember **our `CLAUDE.md` wins** on any
conflict (e.g. "deliver complete files, not diffs"; `round()` only on write).

## Agent skills (`boost:add-skill <owner/repo>`) — deliberately NOT adopted

The community skill directory is mostly **generic** Laravel/PHP best-practice
guides. This project's own `CLAUDE.md` + `docs/` are more specific and would be
*overridden* by generic skills (which is the wrong direction). Decision: **skip
the skill catalogue**; cherry-pick a single targeted skill only if a concrete
need shows up. Don't bulk-install.

## Optional: publish the config

Only if you need to tweak paths/behaviour:

```bash
php artisan vendor:publish --tag=boost-config   # → config/boost.php
```

## Troubleshooting

- **`boost:mcp` "command not found"** → you're not in `local`/`debug`. Fix the env.
- **DB tools error** → no live DB connection; check `.env` DB creds / that MariaDB
  is up. Schema/query tools need a real connection, not sqlite stub.
- **Claude Code doesn't see it** → confirm `.mcp.json` is at the repo root you
  opened, re-approve via `/mcp`.
