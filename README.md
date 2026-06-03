# ekdosi

A homegrown Greek invoicing app (τιμολογιέρα) for internal, operators-only use —
**myDATA** (AADE) e-invoicing, **QR/MARK**, customer ledger (καρτέλα), expenses,
quotes, and a **WHMCS bridge**. This is the modern rewrite of a legacy
C++Builder (VCL) + Firebird application, now on **Laravel 13 + FilamentPHP 5 +
MariaDB**, multi-tenant and multi-country from day one.

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
- **Queue worker** — `php artisan queue:work` under systemd/supervisor
  (`ekdosi-queue.service`). Email, PDF, and import run as queued jobs, so
  **without the worker nothing in the queue executes**.
- On every deploy: `php artisan queue:restart` so the worker picks up new code.

## Documentation

- **`CLAUDE.md`** — architecture, decisions, conventions, current status (read first).
- **`INSTALL.md`** — production install (RHEL/nginx/php-fpm/MariaDB, systemd, cron).
- **`docs/Comparison.md`** — legacy → new mapping + what's net-new / deferred.
- **`docs/services-quotes-roadmap.md`** — Quotes + Services/recurring (both built).
- **`docs/CLAUDE-history.md`** — archived per-PR history and resolved findings.
- **`myDATA_API_Documentation_v2.0.0_preofficial_erp.md`** — the AADE spec.
