# Company portability — backup / export / import (plan)

> **Status: PLAN ONLY.** No code yet. This documents the target design so we
> build it in safe, reviewable phases. Decisions still open are marked **⟨DECISION⟩**.

## Why

Today the only backup is a **full-DB dump** (`clean.sh`). There is **no in-app
way** to move or rebuild a single tenant. Two concrete needs drove this:

1. **Move a tenant to its own VM.** A large company shouldn't have to live on
   the shared test VM forever — we want a "lift one company out" bundle.
2. **Scheduled, self-service backups per tenant.** Each company configures its
   own backup cadence + **destination** (keep locally, or push out via
   FTP / SFTP / rsync / email / cloud disk). Bundles are **downloadable** from
   the panel and **restorable from the interface** (upload → import) on the same
   *or another* VM — not CLI-only.
3. **Rebuild a tenant's data without losing its settings.** During the
   dual-run / pre-cutover phase we cut test invoices, then want to **wipe the
   test data and re-import cleanly from Firebird** — but a company carries a
   *lot* of hand-entered config (myDATA dev+prod credentials, GSIS, SMTP, PDF
   template, WHMCS bridge url/token, **invoice types already mapped to myDATA
   income classes**, VAT categories with exemption codes, payment methods with
   myDATA payment types, …). Re-entering all that by hand is the blocker.

### What we already have (so we don't over-build)

The Firebird ETL (`migrate:firebird`) is **re-runnable and already
settings-safe** — verified in `MigrateFromFirebird::copyInvoiceTypes()`:

- It **never touches** the `companies` settings columns (creds / mail / PDF /
  WHMCS). A re-import leaves all tenant config intact automatically.
- It **upserts** transactional rows by `(company_id, legacy_id)` → **no
  duplicates** on re-run.
- For invoice types it **preserves** your myDATA mapping and never lowers the
  counter:
  ```php
  $invcount    = max($legacyInvcount, $existing?->invcount);                 // counter never goes back
  $incomeClass = $legacyIncomeClass ?? $existing?->mydata_income_class;        // your myDATA map survives
  $incomeCat   = $legacyIncomeCat   ?? $existing?->mydata_income_class_category;
  ```
- **Filament-created rows (`legacy_id` null) are left alone** — i.e. the few
  test invoices you cut by hand are *not* removed by a re-import; they must be
  cleared explicitly.

**Implication:** for need #2 you do **not** strictly need delete+rebuild — a
targeted wipe of the orphan (`legacy_id` null) test rows + a re-import already
preserves everything. The export/import feature below still matters for need #1
(VM move) and as a safety net, but it is *not* on the critical path for a clean
re-import.

---

## Data taxonomy (per company)

We classify every tenant-owned table into three buckets. The export bundles
pick buckets.

### A. Settings (the `companies` row)
Single row. Includes the **7 encrypted** columns (see "Secrets" below):
`mydata_subscription_key_sandbox/production`, `gsis_password`,
`mail_smtp_password`, `whmcs_api_secret`, `whmcs_webhook_secret`,
`einvoice_provider_config`. Plus non-secret config: AFM, tax office, address,
mydata ids + mode, `mydata_send_item_descr`, mail from/templates,
`pdf_footer_text`, `logo_path` (+ the **logo file** itself), all `whmcs_*`
fields, custom-field map, `einvoice_provider*`.

### B. Setup / lookups (small, operator-curated — the "keep these" list)
The 9 tables the operator explicitly wants preserved:
`invoice_types` (⚠ carry `mydata_type` / `mydata_income_class` /
`mydata_income_class_category` / `mydata_requires_quantity` / `invcount`),
`payment_methods` (`mydata_payment_type`, `due_days`), `bank_accounts`,
`delivery_methods`, `distribution_aims`, `product_categories`,
`vat_categories` (`rate`, `vat_exemption_category`, `is_default`),
`metric_units`, `tags`.
**Ambiguous (⟨DECISION⟩ which bucket):** `billing_connections` (WHMCS/connector
registry — config-like → lean A/B), `servers` / `server_groups` (WHMCS infra).

### C. Transactional (the re-importable / bulk data)
`customers` (+`customer_contacts`), `suppliers` (+sources), `products`
(+`product_price_tiers`, `product_billing_prices`), `invoices` (+`invoice_lines`,
`mydata_marks`, `return_invoice_extras`), `payments`, `quotes` (+`quote_lines`,
`quote_mail_logs`), `expenses` (+`expense_lines`, `expense_marks`),
`delivery_notes` (+lines, marks), `service_contracts`, `stock_movements`,
`pending_whmcs_invoices`, `invoice_mail_logs`, `notes`, `attachments`,
`activity_log`.

---

## Secrets / encryption — the key decision ⟨DECISION⟩

Operator's instinct: *"per-company key, fully portable; maybe un-encrypted in
phase 1."* Portability goal is right; here's the honest tradeoff so we pick a
**secure** version of it.

| Option | Portable? | Secure at rest? | Notes |
|---|---|---|---|
| **1. Status quo — Laravel `encrypted` (APP_KEY)** | ❌ (other VM has a different APP_KEY → blobs won't decrypt) | ✅ | What we have now. |
| **2. Per-company key stored *in the DB row*** | ✅ | ❌ **NO** | The key travels with the ciphertext → a DB dump leaks everything. Equivalent to plaintext for anyone with DB read. |
| **3. Plaintext in DB (phase-1 idea)** | ✅ | ❌ **NO** | mydata keys / GSIS / SMTP / WHMCS secrets readable in any dump or `select *`. A real regression. |
| **4. Envelope encryption (RECOMMENDED)** | ✅ | ✅ | Per-company random `data_key`; the 7 secret columns are encrypted with `data_key`; `data_key` itself is stored **wrapped by APP_KEY**. Migration = re-wrap **one** small key on the target VM; columns untouched. |
| **5. Passphrase-protected export (no schema change)** | ✅ | ✅ | Keep option 1 at rest; at **export** time decrypt + re-encrypt the bundle under an operator passphrase; on **import** the passphrase decrypts and re-encrypts under the new VM's APP_KEY. Zero plaintext at rest, zero schema change. |

**Recommendation:** **Option 5 now** (smallest, no migration, unblocks
everything) and treat **Option 4** as the longer-term "fully portable" target
the operator described — it makes future moves trivial (re-wrap one key) while
keeping at-rest security. **Avoid 2 & 3** — they store credentials effectively
in clear. (Dual-use note: these are live tax-authority + billing-provider
credentials; plaintext-at-rest is the one thing we shouldn't ship.)

Either way the **same-VM rebuild** (need #2 / tomorrow) needs *no* re-keying at
all: carry the encrypted blobs verbatim, same APP_KEY, they just work.

---

## Bundle format

- A single **`.zip`** (or `.json` + sidecar files):
  - `manifest.json` — schema version, app version, source company slug/AFM,
    export timestamp, bucket flags (settings? setup? transactional?), secrets
    mode (`appkey` / `passphrase` / `envelope`), counts per table for
    validation.
  - `company.json` — settings (secrets handled per chosen mode).
  - `setup/*.json` — one file per lookup table.
  - `data/*.json` — transactional (only in full bundle).
  - `files/` — logo, any attachments.
- **Idempotent import** keyed by natural keys (`afm` for company; `code` for
  invoice_types / vat / payment; name for lookups; `(company_id, legacy_id)` for
  transactional) so re-import converges instead of duplicating — mirrors the
  ETL's discipline.
- **Surrogate-PK rewiring:** importing into a fresh company gets new `id`s;
  rebuild the `legacy_id → new_id` and `code → new_id` maps and rewrite FKs,
  exactly like the ETL does. Never trust exported `id`s.
- **Versioned + validated:** refuse mismatched schema versions; dry-run mode
  prints a per-table create/update/skip plan before writing.

---

## Automated backups — scheduling & destinations (per company)

The export bundle (above) is the *artifact*; this layer decides **when** it's
produced, **where** it goes, and **how** it's restored — all configurable per
tenant, no two companies forced to the same policy.

### Per-company config (a `company_backup_settings` row, or a settings tab)
- `enabled`, `frequency` (off / daily / weekly / monthly) + time, `buckets`
  (settings / settings+setup / full), `retention` (keep N / keep days),
  `secrets_mode` (verbatim / passphrase / envelope — per the Secrets decision),
  optional `passphrase` (encrypted at rest).
- One or more **destinations** (a company can fan out to several).

### Destination drivers (pluggable — one interface, many transports)
`App\Contracts\BackupDestination` with `push(bundlePath, manifest)`; resolved
by a registry (mirrors `EInvoiceSubmitterFactory` / `BillingSourceRegistry`):
- **Local** — a Laravel filesystem disk (default `backups/{slug}/…`), the
  source for the **"Download"** button.
- **Email** — attach (or link if too big) to a configured address.
- **SFTP** — `league/flysystem-sftp-v3` (preferred over plain FTP — see
  security).
- **FTP** — `league/flysystem-ftp` (only if a tenant insists; warn it's
  cleartext).
- **rsync** — shell out to `rsync` to a configured `user@host:path` (SSH key);
  guarded, host-allowlisted.
- **S3 / cloud disk** — any configured Flysystem disk (future).

> Reuse, not reinvent: `spatie/laravel-backup` is already in the stack but is
> **whole-DB**. We keep it for disaster recovery; this feature is **per-tenant**
> (one company's data + files + settings), so it's a separate, tenant-scoped
> pipeline that can *borrow* spatie's destination/notification ideas.

### Scheduling
A single scheduled command (`company:run-scheduled-backups`, gated by an
`EKDOSI_SCHEDULE_*` flag like the rest of `routes/console.php`) iterates tenants
whose cadence is due, runs the export under `CompanyContext::actAs(...)`, pushes
to each destination, prunes per retention, and writes a **backup-run log**
(when / bucket / destinations / bytes / ok|failed) surfaced in the panel.
Requires the OS cron + queue worker already documented in CLAUDE.md "Env-prep".

### UI (Filament — first-class, not CLI-only)
- **Company → «Αντίγραφα ασφαλείας» tab:** config form (cadence + destinations
  + retention + secrets mode), a **run-now** action, a **history table** with
  per-row **Download**, and a **status badge** (last run / next run / failures).
- **Restore from interface:** an **upload-a-bundle → preview (dry-run plan) →
  import** action (buckets selectable). Works on a *fresh* VM too: stand up the
  app, log in, upload the bundle, restore — no shell.
- **In transit + at rest:** bundles carry secrets → default secrets_mode for any
  **remote** destination is `passphrase` (option 5), and SFTP/rsync (encrypted)
  are preferred; plain FTP/email are opt-in with a clear warning.

---

## Phased plan

### Phase 1 — Settings + setup export/import  ← unblocks tomorrow
Buckets **A + B**. `company:export --tenant=SLUG [--out=file]` and
`company:import --file=… [--into=SLUG|--new]`, plus a Filament action on the
Company resource ("Εξαγωγή ρυθμίσεων" / "Εισαγωγή ρυθμίσεων") — **download** the
bundle and **upload-to-restore** (dry-run preview → import) from the UI, not
CLI-only. Secrets via the chosen mode (default: same-VM verbatim; `--passphrase`
for option 5). Carries the logo file. Idempotent by natural keys. **This is the
piece the operator's chosen tomorrow-route depends on.**

### Phase 2 — Full company export/import
Add bucket **C** + FK rewiring + the `files/` (attachments) payload. Big-bundle
streaming, memory guard. Enables need #1 (lift a company onto its own VM),
paired with option 4/5 for the cross-VM secrets.

### Phase 3 — Per-entity selective export/import + selective Firebird web form
- Per-entity CSV/JSON export+import: customers, suppliers, products, invoices.
- **Selective Firebird import UI**: the operator's idea — a web form on top of
  `migrate:firebird` to **choose what to pull** (e.g. customers + invoices only,
  skip invoice types / VAT). Backed by the ETL's existing per-table copy
  methods, gated by checkboxes.

### Phase 4 — Automated backups + destinations + UI restore
Build the "Automated backups" layer above: per-company config, the
`BackupDestination` driver registry (local / email / SFTP / FTP / rsync /
cloud), the scheduled `company:run-scheduled-backups`, retention pruning, the
backup-run log, and the Company «Αντίγραφα ασφαλείας» tab (run-now + history +
download + upload-restore). Depends on Phase 1 (and Phase 2 for full bundles).

### Phase 5 (optional) — Envelope-key migration (secrets option 4)
Introduce per-company `data_key` (wrapped by APP_KEY), migrate the 7 columns,
make cross-VM moves a one-key re-wrap. Only if cross-VM friction proves real
and option 5 (passphrase) isn't enough.

---

## Tomorrow's runbook (operator's chosen route: export → delete → rebuild → import → Firebird)

> Requires **Phase 1** built first. Until then, the **targeted-wipe alternative**
> works with zero new code (see "What we already have").

1. `company:export --tenant=myip --settings-only --out=myip-settings.zip`
   (buckets A+B; logo included; secrets verbatim — same VM).
2. Clear the tenant's transactional rows. **Note:** to actually *delete the
   company* you must first remove dependent rows (FKs) — so a transactional wipe
   is needed either way. (This is why the targeted-wipe route is strictly
   simpler; flagged for reconsideration.)
3. Re-create the company, `company:import --file=myip-settings.zip --new`.
4. `php artisan migrate:firebird --company="MyIP" --slug=myip --fdb=… …` —
   pulls customers/products/invoices, **preserves** the just-imported
   myDATA-mapped invoice types, and sets `invcount = max(legacy, current)`
   (also resolves the dual-run counter collision).
5. `php artisan invoices:recompute-balances --company=myip`.
6. Cancel the stray test MARK (ΤΠΥ6654 / `400013829677137`) at AADE once it
   indexes — it duplicates a real legacy ΑΑ.

---

## Open decisions (need operator sign-off before Phase 1 code)
- **⟨DECISION⟩ Secrets mode for Phase 1:** option 5 (passphrase) vs verbatim
  same-VM only vs commit to option 4 envelope now. (Recommend: verbatim default
  + `--passphrase` opt-in; envelope later.)
- **⟨DECISION⟩ Bucket for `billing_connections` / `servers` / `server_groups`.**
- **⟨DECISION⟩** Do we export `activity_log` and the full myDATA request/response
  XML in the full bundle (legal audit) or treat them as non-portable history?
- **⟨DECISION⟩** Confirm the "keep" setup list is exactly the 9 tables above.
- **⟨DECISION⟩ Destination drivers** for v1 of automated backups (local + email +
  SFTP first? rsync/FTP later?) and **retention** defaults.
- **⟨DECISION⟩ Encryption-in-transit policy:** force `passphrase` bundles for any
  remote destination; allow plain FTP/email only behind an explicit warning?

---

## On the APP_KEY constraint ("το κλειδί μας περιορίζει")

It does — **but only for at-rest encryption tied to one VM, not for
portability itself.** The fix is to *decouple* the two:

- **At rest**, secrets stay encrypted (today: APP_KEY; later: a per-company
  `data_key` *wrapped* by APP_KEY — envelope, option 4). This is what protects a
  DB dump.
- **For moving**, the bundle is re-keyed at the boundary: option 5 re-encrypts
  the export under an operator **passphrase** (no schema change, works today);
  option 4 makes it a one-key re-wrap on the target VM.

So the key isn't a wall — it's just that "encrypted at rest on VM-A" and
"readable on VM-B" must be bridged by *something the operator carries* (a
passphrase) or *something we re-wrap* (the per-company data_key). The one path
we reject is removing encryption to dodge the bridge: that trades a small
convenience for plaintext tax-authority + billing credentials in every dump.
Net: **portability is fully achievable without weakening at-rest security** —
the key constrains *how* we bridge, not *whether* we can.
