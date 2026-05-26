# CLAUDE.md — ekdosi modernization

Context for working in this repo. Read this first.

## What this project is
Porting a legacy **Delphi + Firebird** invoicing app ("ekdosi") to a modern
**Laravel 11 + FilamentPHP + MariaDB** stack. The legacy app is a homegrown
τιμολογιέρα, updated over the years to support **myDATA**, **QR**, and a **WHMCS
bridge**. It is operators-only (internal, no customer-facing frontend) and compiles
only on a fragile Windows 7 VM — escaping that toolchain is the whole point.

Scope: customers, stock/products, services, invoices (παραστατικά), payments,
myDATA submission + audit trail. No customer portal.

## Goal & end state
- Single multi-tenant MariaDB (`company_id` on every table), one Laravel codebase,
  one Filament panel with tenant switching.
- Legacy myDATA logic NOT re-ported by hand — use `firebed/aade-mydata`
  (+ `firebed/laravel-aade-mydata` wrapper). Per-tenant credentials live on `companies`.
- Old Delphi/Firebird app stays read-only/archived for history after cutover.

## Repo layout
```
/legacy/           # original Delphi source + Firebird schema (reference, do not build)
  schema/ekdosi-schema.sql      # isql -x dump (WIN1253 DB; ASCII DDL is clean)
  delphi/                       # old Pascal source — the real VAT/rounding logic lives here
/database/migrations/           # target schema (19 idiomatic Laravel migrations)
/app/Console/Commands/MigrateFromFirebird.php   # re-runnable ETL, one tenant per run
/README.md                      # migration-kit decisions (read alongside this file)
CLAUDE.md                       # you are here
```

## Architectural decisions (do not re-litigate without reason)
- **Multi-tenant, not per-DB.** Superset: can deploy per-DB later; reverse can't.
- **Surrogate PKs + `legacy_id`.** Legacy integer PKs collide across companies
  (CUST_ID=1 exists in myip AND nixpal). Fresh `id` everywhere; `legacy_id` (unique
  per company) kept for audit + re-runnable ETL. FKs rewired via `legacy_id → new_id`
  maps during import.
- **Numbering = continuous counter per invoice type** (from `INVTYPE.INVCOUNT`, found in
  the triggers — NOT per fiscal year). Copied as-is to `invoice_types.invcount`; the new
  app continues from there, so cutover needs no fiscal boundary. myDATA terms:
  `invoice_types.code` → series, `invoices.code` → ΑΑ.
  **Increment under `lockForUpdate()` in a transaction** (legacy trigger serialised this).
- **`mydata_marks` is the source of truth** for myDATA (full request/response XML kept,
  legal audit). `invoices.mydata_*` columns are a denormalised cache of latest state.
- **Charset:** legacy DB is **WIN1253**. ETL connects `charset=UTF8` (FB transliterates
  on read; UTF8 is a superset → no transliteration errors). Fallback: `charset=NONE` +
  `iconv('Windows-1253','UTF-8//IGNORE',...)`.
- Reserved words renamed; INVDATE+INVTIME merged into `invoices.issued_at`; Firebird
  domains → concrete decimals.

## Deliberately dropped
- `EAFDSS_SCRIPT` (dead pre-myDATA ΕΑΦΔΣΣ), `REPORTS`/`REPORT_INPUT_DATA`, `lpad` UDF,
  legacy trigger-defaults (AFM=CUST_ID, BARCODE=PRODUCT_ID).
- `GET_COMB_*` procedures — cross-DB `EXECUTE STATEMENT` to a hardcoded
  `C:\Users\haris\...\ekdosi-arif-rossidis\ekdosi.fdb` with SYSDBA/masterkey inline.
  Security landmine + unrelated two-company feature. **Never carry these creds over.**
- WHMCS bridge collapsed: link now on `customers.whmcs_client_id`; `AUTO_INVOICE_LOG`
  → `whmcs_invoice_log`. `CUSTOMER_CS_ACCEPTED` staging left out (rework as a sync step).

## Plan / phases
1. Build schema + models on MariaDB (done: migrations). Filament tenancy = Company tenant.
2. ETL each `.fdb` → one tenant (`php artisan migrate:firebird ...`). Re-runnable.
3. **Parallel run + golden tests** before trusting it: legacy stores `PRICE`/`PRICEWVAT`
   per line; recompute over imported inputs and assert identical totals. This is where
   VAT/rounding port bugs (hiding in the Delphi code) surface.
4. Planned cutover: ETL one last time, Delphi → read-only archive, kill the Win7 VM.

## Commands
```bash
php artisan migrate
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" --host=10.23.22.5 \
    --fbuser=EKDOSI --fbpass=ekdosi1234     # repeat per legacy DB
```
Requires the `pdo_firebird` PHP extension on the artisan host.

## Conventions
- Deliver **complete, ready-to-drop files**, not diffs.
- Simplicity over cleverness; no premature abstraction / over-engineering.
- snake_case tables (plural), `id` PK, `timestamps`, `softDeletes` where it makes sense.
- utf8mb4 / utf8mb4_unicode_ci.
- Money decimal(14,2), qty decimal(9,3), vat% decimal(5,2).

## Open TODOs / decisions pending
- [ ] Eloquent models + relationships for all tables.
- [ ] Filament: Company as tenant, scoped Resources, invoice-issue flow as a custom page.
- [ ] Invoice issue action: build payload → `firebed/aade-mydata` → persist to
      `mydata_marks` → update `invoices.mydata_*` cache (mirror legacy MARK_AI0 trigger).
- [ ] Re-implement WHMCS bridge cleanly (PHP-to-PHP now: shared DB or API).
- [ ] Confirm whether the GET_COMB_* "combined invoice" feature is used by myip.
- [ ] Port + golden-test the line/total/VAT/rounding math from the Delphi source.
- [ ] Verify `CONF_PARAMS` keys still needed; migrate app config into Laravel config/env.

## Source of truth note
The schema is settled; the *behaviour* (VAT, rounding, discounts, myDATA payload shape)
lives in the Delphi source under /legacy/delphi and in stored values. When in doubt,
trust the legacy stored results and reproduce them — don't reinvent the math.
