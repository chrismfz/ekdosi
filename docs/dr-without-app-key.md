# Disaster recovery without the APP_KEY (Phase 6)

> **Goal:** a plain `mysqldump` is **self-sufficient** — restore it on a fresh VM
> and the app works, **without** carrying the old `APP_KEY`. Backups/restores need
> **no encryption or password** by default.

## What used to tie you to APP_KEY

Laravel uses `APP_KEY` for: (1) **`encrypted` cast columns**, (2) the **session
cookie** encryption, (3) signed URLs / password-reset tokens.

Only **(1) is a hard DR blocker**: restore the DB on a VM with a *different*
`APP_KEY` and every `encrypted` column becomes undecryptable garbage. (2)/(3) are
**soft** — a new key just means existing sessions/cookies are invalid, so users
**re-login**. No data loss. (We keep `SESSION_ENCRYPT=false`; the session *data*
isn't key-bound, only the cookie that names it.)

## The fix — optional at-rest encryption (default OFF)

The secret columns now use the **`App\Casts\MaybeEncrypted`** cast instead of
Laravel's `encrypted`, driven by `config('ekdosi.secrets.encrypt_at_rest')`
(`EKDOSI_ENCRYPT_SECRETS_AT_REST`, **default `false`**):

| Flag | At rest | DR |
|---|---|---|
| **false (default)** | **plaintext** | mysqldump self-sufficient — **no APP_KEY needed** |
| true | encrypted under APP_KEY | classic posture — DR must carry the key |

The columns covered (every `MaybeEncrypted` cast):
`companies` (myDATA sandbox/production keys, `einvoice_provider_config`,
`gsis_password`, `mail_smtp_password`, `whmcs_api_secret`, `whmcs_webhook_secret`),
`servers`/`server_groups` (`secret_encrypted`), `company_backup_settings`
(`passphrase`), `users` (`app_authentication_secret`, `…_recovery_codes`).

**Robustness:** the cast's **getter always decrypts legacy ciphertext first** and
falls back to the raw value, so it reads **both** plaintext and old ciphertext
**regardless of the flag** — flipping the flag never breaks existing rows.

### Security note
With the flag OFF, secrets are readable by anyone with **DB access** — the DB (and
disk) **is the trust boundary**. This is the deliberate, operator-chosen posture
(easy DR over app-level encryption). Lock down DB users, disk, and backups
(filesystem perms / SFTP / S3 access control) accordingly. Flip the flag ON if you
need at-rest encryption and accept carrying `APP_KEY` into DR.

## Switching modes

After changing `EKDOSI_ENCRYPT_SECRETS_AT_REST`, rewrite existing rows to match:

```bash
php artisan secrets:reencrypt --to=plain        # decrypt → store plaintext (DR-ready)
php artisan secrets:reencrypt --to=encrypted    # encrypt under the current APP_KEY
php artisan secrets:reencrypt --to=plain --dry-run   # preview, write nothing
```

Idempotent + re-runnable (skips columns already in the target form). Run it while
you **still have the working APP_KEY** (so existing ciphertext can be decrypted).

**Safety net:** `--to=plain` will **not** freeze an unreadable blob — if it meets
ciphertext it can't decrypt (wrong/lost APP_KEY) it **skips** that column, warns,
and **exits non-zero**, so you can't silently lose a secret by converting before
restoring the right key. Put the correct `APP_KEY` back and re-run.

**Portability bundles** never carry server creds: `servers`/`server_groups`
`secret_encrypted` is redacted on `company:export` (a secret must not ride in a
bundle), so re-enter those on the target after a settings/full import.

## DR runbook (default plaintext mode)

1. New VM: clone the repo, `composer install`, `npm run build`.
2. `cp .env.example .env`, set DB creds + `APP_URL`; `php artisan key:generate`
   (a **fresh** APP_KEY is fine — nothing in the DB depends on it).
3. Restore the dump: `mysql ekdosi < backup.sql` (or `gunzip -c … | mysql …`).
4. `php artisan migrate` (no-op if the dump is current), `php artisan optimize`,
   restart the queue worker. Done — secrets are already readable (plaintext).
5. Users re-login (their old session cookies are from another key — expected).

(The per-company **portability** export/import — `company:export`/`import` and the
panel «Εξαγωγή/Εισαγωγή ρυθμίσεων» — is a separate, finer-grained path and already
works without a passphrase via `raw` mode; see `docs/company-portability-plan.md`.)
