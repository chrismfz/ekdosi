# Secrets at rest — the plaintext trade-off (SEC-1)

**Decision (accepted): per-tenant secrets are stored PLAINTEXT at rest.**

ekdosi keeps each tenant's credentials — myDATA subscription keys, WHMCS API
identifier/secret + webhook secret, per-tenant SMTP password, GSIS password,
the AI key, server/provisioning creds, 2FA secrets, backup passphrase — in DB
columns. By default (`EKDOSI_ENCRYPT_SECRETS_AT_REST=false`) those columns are
**plaintext**, not encrypted under `APP_KEY`.

This is deliberate, and it is the accepted posture for this deployment.

## Why plaintext is the default here

- **DR without the key.** A plain `mysqldump` is self-sufficient: you can
  restore onto a fresh VM and the app works immediately, without also having to
  carry (and protect, and never lose) the old `APP_KEY`. Losing `APP_KEY` with
  encrypted secrets means the secrets are unrecoverable — a worse failure mode
  than the one encryption defends against, for a small operators-only app.
- **The DB is the trust boundary.** Protection rests on DB + disk + backup
  access control, not on column encryption:
  - MariaDB bound to localhost (`127.0.0.1`), no public port.
  - The app host is access-controlled (SSH keys, fail2ban — see `INSTALL.md`).
  - Backups go to an **access-controlled** off-site target (SFTP/S3 with
    scoped credentials) and are password-protected via
    `BACKUP_ARCHIVE_PASSWORD` (spatie zip encryption).
- **Small, internal, operators-only.** No customer portal, ~3 tenants. The
  attacker who can read the DB or an off-site backup archive has already
  defeated the boundary that column encryption would sit behind.

## What this is NOT

- It is **not** a reason to relax DB/host/backup access control — that IS the
  control. Keep MariaDB localhost-only, keep the backup destination and its
  passphrase tight, rotate host and DB credentials.
- It does **not** put secrets in logs, `toArray()`/API output, or exception
  traces — the secret columns are `$hidden` and cast, independent of this flag.

## The escape hatch (if the posture ever changes)

Encryption-at-rest is one flag away, and the `App\Casts\MaybeEncrypted` cast
always decrypts legacy ciphertext on read, so flipping it never breaks existing
rows:

```bash
# 1. keep APP_KEY safe and part of your DR bundle FIRST, then:
EKDOSI_ENCRYPT_SECRETS_AT_REST=true      # in .env
php artisan secrets:reencrypt --to=encrypted
php artisan config:clear
# to go back: --to=plain with the flag false
```

## go-live gate

`php artisan ekdosi:go-live-check` includes a **«Μυστικά at-rest»** gate:

- `EKDOSI_ENCRYPT_SECRETS_AT_REST=true` → **pass** (encrypted).
- plaintext **and** `EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED=true` → **pass**
  (this document's decision, made explicit).
- plaintext without the acknowledgement → **warn**, to force the choice at
  cutover rather than let it be a silent default.

For this deployment, set in production `.env`:

```
EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED=true
```

---

## Webhook secrets must be per-tenant (SEC-3)

The WHMCS bridge authenticates every webhook with an HMAC-SHA256 over the raw
body (push/write-back) or the `"{slug}:{id}"` canonical string (status), keyed
by the tenant's `companies.whmcs_webhook_secret`. The signature binds the
**secret**, not the tenant identity in the wire format — so the *only* thing
that stops tenant A's signed webhook from being replayed against tenant B is
that **they hold different secrets**.

**Rule: never reuse a `whmcs_webhook_secret` across tenants.** Two tenants
sharing one means either could forge the other's webhook (bounded — effects are
idempotent + verify-before-side-effect, and the leak is read-only to a
secret-holder — which is why this stays LOW, not a wire-format change). Each
tenant gets its own random secret; rotate on the ekdosi side and the plugin
side together.

`php artisan ops:health` now surfaces a collision: the **Security → «Shared
webhook secret»** row (and a warning in the verdict) lists any tenants sharing a
secret, comparing a hash of the decrypted value so the plaintext never enters
the report. `none` = clean.

> Deferred (documented, not built): binding the slug + a timestamp/nonce into
> the canonical string to add replay protection and make the tenant explicit on
> the wire. That couples to a coordinated plugin-first rollout, so it waits —
> see `docs/CLAUDE-history.md`. The per-tenant-secret rule above is the current
> guarantee.

## The public invoice-PDF signed URL never expires (SEC-4)

The invoice PDF is served from a **permanently-valid** signed URL (Laravel
`signedRoute` without an expiry) — by design, so a WHMCS-side link a customer
was given keeps working indefinitely. Consequences to accept:

- A leaked URL is **permanent** read access to *that one* invoice PDF (it is
  per-invoice signed, not a blanket key — one leak ≠ the whole tenant).
- The **only** revocation is rotating `APP_KEY`, which invalidates **every**
  signed URL at once (and would need the WHMCS-side links reissued). There is no
  per-URL revoke.

Keep it in mind before pasting such a URL anywhere it could be indexed or
logged; if one is known-leaked and the exposure matters, `APP_KEY` rotation is
the (blunt) lever.
