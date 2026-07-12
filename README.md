# ekdosi

A homegrown Greek invoicing app (τιμολογιέρα) for internal, operators-only use —
**myDATA** (AADE) e-invoicing, **QR/MARK**, customer ledger (καρτέλα), expenses,
quotes, and a **WHMCS bridge**. This is the modern rewrite of a legacy
C++Builder (VCL) + Firebird application, now on **Laravel 13 + FilamentPHP 5 +
MariaDB**, multi-tenant and multi-country from day one.

**Version:** `v1.6.0` (SemVer — `config('app.version')`; cut releases with
`php artisan ekdosi:release`). What's built → [`FEATURES.md`](FEATURES.md) ·
what's left → [`docs/BACKLOG.md`](docs/BACKLOG.md) · changes → [`CHANGELOG.md`](CHANGELOG.md).

> Internal tool — no public/customer portal. Operator-facing UI is Greek; code
> identifiers are English.

## Stack

- **PHP 8.4+**, **Laravel 13**, **MariaDB 11.x** (`utf8mb4_unicode_ci`)
- **FilamentPHP 5** — admin panel **and** tenancy driver (Company = tenant)
- **myDATA**: `firebed/aade-mydata` (wrapped in `App\Services\MyDataSubmitter`)
- **Roles**: `spatie/laravel-permission` + `bezhanSalleh/filament-shield` —
  per-tenant `super_admin` / `company_admin` / `operator`, with a role-picker UI
- **Audit log**: `spatie/laravel-activitylog` — who-changed-what on invoices,
  customers, payments (read-only «Ιστορικό» tab per record)
- **PDF**: `barryvdh/laravel-dompdf` · **Backups**: `spatie/laravel-backup`
- **Queue/scheduler**: Laravel built-in (DB driver)

## What it does

- **Invoices (παραστατικά)** — issuance with per-type continuous numbering (ΑΑ),
  the exact legacy VAT/discount/rounding math, QR + PDF, a two-axis lifecycle
  (`local_status` × `mydata_state`), and local + live AADE reconciliation.
- **myDATA** — submit / cancel / dry-run (sandbox-validated), full request/response
  XML kept in `mydata_marks` as the legal source of truth.
- **Money** — payments + credit notes; `App\Services\InvoiceBalance` is the single
  source for paid/credited/balance; customer ledger (καρτέλα) with YoY KPIs.
- **Expenses / Suppliers** — the inbound mirror of the sales side, with myDATA
  expense classification and ΦΠΑ/E3 reporting.
- **Quotes (προσφορές)** — non-legal sales offers in their own tables (never
  myDATA-filed, never counted in money/ΦΠΑ); accept → **convert to a draft
  invoice** with bidirectional history. PDF + email + send-log.
- **Recurring services (υπηρεσίες/συμβόλαια)** — WHMCS-style subscriptions adapted
  to per-invoice myDATA: a product catalogue with a per-cycle price matrix +
  per-customer contracts; renewals **stage a DRAFT** (operator-gated, never
  auto-AADE), the billing cursor advances on issue; opt-in per-product **dunning**
  (auto suspend/terminate + unsuspend-on-payment, default OFF); MRR/upcoming
  widgets; a native, WHMCS-independent provisioning seam (servers + Null module).
- **WHMCS bridge** — operator-gated inbox: WHMCS push/poll → webhook →
  review → issue via the normal invoice lifecycle. PHP-to-PHP via the WHMCS API
  (our plugin lives in `whmcs-plugin/ekdosi_bridge/`).
- **Multi-tenant / multi-country** — one codebase, one panel with tenant
  switching; `companies.einvoice_provider` selects the submitter
  (`gr-mydata` / `ee-peppol` / `none`) behind a common issue flow.
- **Roles & audit** — per-tenant roles (`super_admin` / `company_admin` /
  `operator`) assigned via a role-picker; an activity log records
  who-changed-what on invoices, customers and payments, shown as a read-only
  «Ιστορικό» tab on each record.

## Layout

```
/                       Laravel 13 app at repo root
  app/                  models, Filament resources, services, actions
  database/migrations/  schema
  whmcs-plugin/ekdosi_bridge/   our WHMCS-side plugin
  docs/                 design docs, roadmaps, history
  legacy/               read-only reference (do NOT build)
```

## Quick start (dev)

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed                 # if seeders are configured for your env
php artisan shield:generate         # sync resource permissions
php artisan shield:sync-super-admin # sync per-tenant role maps + super_admin
php artisan serve
```

Tests: `php vendor/bin/phpunit`

## Deploy notes

Two long-running pieces must both be live (see **`INSTALL.md`** for the full,
verified production setup):

- **Scheduler** — one cron line: `* * * * * cd /path && php artisan schedule:run`
- **Operator health** — run `php artisan ops:health` (or `--json`) for queue, scheduler, backup, mail, WHMCS, myDATA, and disk checks; see `docs/operator-health.md`.
- **Queue worker** — `php artisan queue:work` under systemd/supervisor
  (`ekdosi-queue.service`). Email, PDF, and import run as queued jobs, so
  **without the worker nothing in the queue executes**.
- On every deploy: `php artisan queue:restart` so the worker picks up new code.

## Backup / restore (per company)

Per-tenant settings backup — restore **one** company without a full-DB rollback
that would clobber other live tenants. Exports the `companies` row (myDATA
dev+prod credentials, GSIS, mail, PDF, WHMCS bridge…), all setup/lookup tables
(invoice types **with their myDATA income-class mapping**, VAT categories,
payment methods, bank accounts, delivery/distribution, product categories,
metric units, tags, server groups, billing connections) and the logo — to a
portable `.zip`. The Firebird ETL never touches settings, so the usual reset is
*wipe transactional → re-import from Firebird*; this protects the hand-entered
config around it.

```bash
# Export. Secrets are passphrase-encrypted by default. --full also includes
# the transactional data (customers/invoices/payments…) for a complete snapshot.
php artisan company:export --tenant=myip                 # settings + setup, prompts passphrase
php artisan company:export --tenant=myip --full          # + transactional data
php artisan company:export --tenant=myip --raw           # cleartext — debug only

# Restore. Dry-run by default (prints the per-table plan); --execute applies.
# A --full bundle restores its data too (FK-rewired); --new is the clean target.
php artisan company:import --file=myip.zip --new                    # create a fresh company
php artisan company:import --file=myip.zip --into=myip --execute    # restore into an existing one
```

```bash
# Wipe a tenant's transactional data (keep company + settings + setup) — the
# clean slate before a Firebird re-import. Dry-run by default; --execute applies.
php artisan company:wipe --tenant=myip                        # preview what would go
php artisan company:wipe --tenant=myip --execute --force      # apply (--force past AADE-filed)
php artisan company:wipe --tenant=myip --keep-parties --execute   # keep customers/suppliers/products
php artisan company:wipe --tenant=myip --reset-counter --execute  # also roll ΑΑ counters → 1
```

Both are also in the panel: Companies → «Αντίγραφα» (export with a «Πλήρες»
toggle, upload-restore, and **«Διαγραφή δεδομένων»**) and a toolbar «Εισαγωγή
εταιρίας από αρχείο».

- **Wipe** keeps the company row + settings + the setup/lookup tables; it only
  removes transactional data (invoices, payments, customers, …). FK order is
  handled automatically. **Take a backup first** (it lives in the same menu for
  exactly that reason). `--force` is required when invoices are filed at AADE —
  a local wipe does **not** cancel them there.
- **`--reset-counter`** (ΑΑ → 1) is safe only **before a Firebird import** (the
  ETL bumps it back to `max(legacy, current)`). If you reset and then issue
  invoices manually *without* importing, the next ΑΑ can collide with a number
  already filed at AADE under that series.

- **Secrets**: the 7 encrypted columns are sealed under your **passphrase**
  (PBKDF2 + AES-256-GCM), so the bundle opens on another VM regardless of its
  `APP_KEY`; the same passphrase is required to import. `--raw` stores them in
  clear text (warned) for a same-box debug dump.
- **Idempotent**: setup rows are matched by a natural key and **updated in
  place** (never delete + insert), so a re-import converges and matched rows
  keep their id — transactional data that references them never dangles.
- **Non-destructive**: import never removes rows absent from the bundle.
- **`--full`** also carries transactional data (customers, suppliers, products,
  invoices + lines + MARKs, payments, quotes, expenses), restored with every FK
  rewired to the new ids (incl. the credit-note self-reference). Deferred (v1):
  delivery notes, service contracts, stock movements, the WHMCS inbox, activity
  log, notes/attachments.
- **Limitation (v1)**: server / server-group provisioning secrets
  (`secret_encrypted`) export as raw APP_KEY ciphertext — portable only within
  the **same** `APP_KEY`; re-enter them after a cross-VM restore.

## Documentation

- **`CLAUDE.md`** — architecture, decisions, conventions, current status (read first).
- **`INSTALL.md`** — production install (RHEL/nginx/php-fpm/MariaDB, systemd, cron).
- **`FEATURES.md`** (root) — the full catalogue of what ekdosi does today.
- **`docs/BACKLOG.md`** — single source for what's left + ideas (incl. «looks like a gap
  but isn't» + an index of the kept design/reference docs).
- **`docs/CLAUDE-history.md`** — archived per-PR history and resolved findings.
- **`docs/aade/myDATA_API_Documentation_v2.0.0_preofficial_erp.md`** — the AADE spec.
