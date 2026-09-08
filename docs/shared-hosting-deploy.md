# Shared-hosting / cPanel / DirectAdmin deploy (OPS-003)

For tenants like **MyIP** and **nexon** we deploy ekdosi into an existing
**cPanel / DirectAdmin** account instead of a dedicated VM — leverage the servers
we already run, and get the double backup (cPanel + JetBackup) for free. This is
the shared-hosting counterpart to the VPS/systemd instructions in
[`INSTALL.md`](../INSTALL.md).

> **Don't hand-copy the commands below.** Run **`php artisan ops:cron`** on the box:
> it prints the exact crontab + worker lines computed from *this* account's real PHP
> binary and application path, plus the live state (is cron/worker already alive?).
> The lines here are the reference; `ops:cron` is the source of truth per host.

## What a shared box lacks

No `systemd`, no `supervisord`, no root — so the resident `queue:work` service from
`INSTALL.md` (`ekdosi-queue.service`) isn't available. Two things still MUST run,
and both go in the account's **crontab** (cPanel → *Cron Jobs*, DirectAdmin →
*Cron Jobs*):

### 1) The scheduler — every host needs this

```cron
* * * * * cd /home/ACCOUNT/ekdosi && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
```

This drives every scheduled task (backups, myDATA reconcile, WHMCS fetch,
auto-email…). Without it **nothing** scheduled runs. `ops:health` now proves this
directly: the **scheduler heartbeat** turns `Cron status: ok` within a minute of a
correct cron line, and reads `missing`/`stale` otherwise (distinct from the queue
worker — see below).

### 2) The queue worker — cron-driven fallback

A shared box has no supervisor to keep a worker resident, so run a **cron-driven
drain**: once a minute, process whatever is queued and exit.

```cron
* * * * * flock -n /tmp/ekdosi-queue.lock /usr/local/bin/ea-php84 /home/ACCOUNT/ekdosi/artisan queue:work --stop-when-empty --queue=default --tries=3 --max-time=55 >> /home/ACCOUNT/ekdosi/storage/logs/queue.log 2>&1
```

- `--stop-when-empty` — drains the queue, then exits (no resident process).
- `--max-time=55` — hard-stops the run UNDER the 60s tick, so two never overlap even
  without `flock` (a value ≥ 60 could run into the next minute's invocation).
- `flock -n` — the overlap guard where the host provides `flock` (most cPanel boxes
  do); drop it if it's missing.
- This is **less responsive** than a resident worker (a job waits up to ~1 min) —
  fine for mail / imports / backups. Invoice issuing + myDATA submission are
  **synchronous**, so they never wait on the worker.

> **PHP binary.** cPanel's account CLI php is usually `/usr/local/bin/ea-phpXX`
> (EasyApache) — NOT the plain `php` on PATH, which may be an older system build.
> `ops:cron` prints whatever `PHP_BINARY` resolved to; if the account's CLI php is
> elsewhere, substitute that path in both cron lines.

## Verify after wiring

```bash
php artisan ops:health         # Cron status + Queue status should read «ok» in 1–5 min
php artisan ops:cron           # re-print the recipe + live state
```

`ops:health` exit code: `0` ok · `1` warning · `2` critical. A dead cron is now a
**distinct** finding («Χρονοπρογραμματιστής (cron): …»), so you're not left guessing
whether it's the cron or the worker.

## Backups

The Laravel-side backups (`spatie/laravel-backup` + the per-tenant Phase-4 backups)
still run from the scheduler above; keep at least one **off-site** destination so a
copy leaves the account (`ops:health` → `backup.companies` warns on
`offsite_gap`/`books_gap`). The cPanel/JetBackup layer is an **additional** safety
net at the hosting level, not a replacement — see [`docs/updates-runbook.md`](updates-runbook.md).

## Standard lookups on deploy

A fresh tenant gets its Greek AADE lookups from `php artisan ekdosi:install` — the
standard categories arrive with their §8.6 income bucket already set. After a later
`git pull` that ships new lookup rows (a new invoice type, a missing classification),
top every tenant up headlessly (idempotent; it never reclassifies an existing category):

```bash
php artisan lookups:seed --all      # idempotent + fill-empty; --tenant=SLUG for one
```
