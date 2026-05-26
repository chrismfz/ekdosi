# ekdosi — Laravel 13 / Filament 5 / MariaDB

Modern rewrite of a legacy **C++Builder (VCL) + Firebird** invoicing
app ("ekdosi"). Multi-tenant (one MariaDB, `company_id` on every table),
operator-only Filament panel, myDATA via `firebed/aade-mydata`, WHMCS
bridge over the WHMCS API. Estonian tenants get PEPPOL e-invoicing
later. See `CLAUDE.md` for the full design + status.

## What's here
- `app/`, `database/`, `config/`, ... — Laravel 13 application (scaffolded at the repo root).
- `database/migrations/` — 22 migrations: 3 Laravel defaults + 19 ekdosi tables.
- `app/Console/Commands/MigrateFromFirebird.php` — re-runnable ETL (`php artisan migrate:firebird`).
- `/legacy/` — read-only reference material from the old C++Builder app:
  - `/legacy/ekdosi-schema.sql` — `isql -x` schema dump.
  - `/legacy/ekdosi-main/` — C++Builder source (read for VAT/rounding/discount math).
  - `/legacy/ekdosi-main/db_backup/ekdosi.fbk` — Firebird gbak for sandboxed ETL dev.

## Key decisions (and why)
- **Multi-tenant, not per-DB.** Superset: deploys per-DB later if needed; the reverse can't.
- **Surrogate keys + `legacy_id`.** Legacy integer PKs collide across companies
  (CUST_ID=1 exists in myip and nixpal). Every table gets a fresh `id`; `legacy_id`
  (unique per company) is kept for audit and to make the ETL re-runnable. FKs are
  rewired via in-memory `legacy_id → new_id` maps during import.
- **Numbering: continuous counter per type.** Discovered from the triggers, not the
  schema: `INVTYPE.INVCOUNT` is the running ΑΑ, bumped after each insert. It maps to
  `invoice_types.invcount` and is copied as-is. **No need to cut over on a fiscal
  boundary** — at cutover the new app simply continues from the copied counter.
  In myDATA terms: `invoice_types.code` → series, `invoices.code` → ΑΑ.
  NOTE: the legacy trigger serialised increments implicitly. In Laravel, increment
  `invcount` inside a transaction with `lockForUpdate()` to keep it race-free.
- **myDATA: `mydata_marks` is the source of truth.** Full `request`/`response` XML kept
  (legal audit trail). `invoices.mydata_*` are a denormalised cache mirroring the latest
  state, exactly as the legacy `MARK_AI0` trigger maintained them.
- **Reserved words renamed:** ORDER→`sort_order`, VALUE→`amount`/`value`, GROUP→(dropped),
  DATE/TIME→`mark_date`/`mark_time`. INVDATE+INVTIME merged into `invoices.issued_at`.
- **Domains → concrete types:** CURRENCY→decimal(14,2), QUANTITY→decimal(9,3),
  PERCENTAGE→decimal(5,2), BIG_NUMERIC→decimal(15,4), T_BOOLEAN→boolean, BLOB TEXT→text.

## Charset — the one thing that bites
The source DB is declared **WIN1253** (seen in the DDL header). The ETL connects with
`charset=UTF8` so the Firebird client transliterates on read (UTF8 is a superset →
no transliteration errors, unlike the earlier ISO8859_1 attempts). `clean()` is a final
safety net that strips any stray invalid byte. If your FB client lib misbehaves, switch
to `charset=NONE` and `iconv('Windows-1253','UTF-8//IGNORE',$v)` per string field.

## Deliberately NOT migrated
- `EAFDSS_SCRIPT` — dead pre-myDATA ΕΑΦΔΣΣ signing.
- `REPORTS` / `REPORT_INPUT_DATA` — report-template definitions (rebuild in Laravel).
- `lpad` UDF and legacy trigger-defaults (AFM=CUST_ID, BARCODE=PRODUCT_ID).
- `GET_COMB_*` procedures — they do cross-DB `EXECUTE STATEMENT` against a hardcoded
  `C:\Users\haris\...\ekdosi-arif-rossidis\ekdosi.fdb` with SYSDBA/masterkey baked in.
  Credential landmine + a two-company "combined invoice" feature unrelated to myip.
- **CS-Cart** bridge is dropped entirely — the legacy code exists
  (`FCSConnect.*`, `FManageCS*`, `CUSTOMER_CS_ACCEPTED`) but was never
  actually used. Don't carry it over.

The **WHMCS bridge stays in scope** (needed by myip) and is reshaped:
- Customer↔WHMCS link: flat column `customers.whmcs_client_id` (no bridge table).
- `AUTO_INVOICE_LOG` → `whmcs_invoice_log` (audit + idempotency).
- The polling job (legacy `FAutoInvoice.cpp`) becomes a Laravel scheduled
  command or queue worker that pulls from the WHMCS API and issues invoices
  through the normal myDATA path — single code path, no special-case.

**Possible future**: Blesta (same niche as WHMCS). Don't pre-abstract today,
but keep the WHMCS pull logic behind a thin "billing-provider" interface so
adding Blesta is a new implementation, not a refactor.

## Run order
```bash
php artisan migrate
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" --host=10.23.22.5 \
    --fbuser=EKDOSI --fbpass=ekdosi1234
# repeat per legacy DB (nixpal, systemworx, ...) — each is one tenant
```
Needs the `pdo_firebird` PHP extension on the box running artisan.

## Golden test (do this before trusting it)
The legacy DB stores per-line and per-invoice results (`PRICE`, `PRICEWVAT`). After import,
re-run your new calculation logic over the imported inputs and assert it reproduces the
stored totals — this is where VAT/rounding port bugs surface:
```sql
-- lines whose recomputed totals diverge from the imported (legacy) values
SELECT il.invoice_id, il.id, il.net_price, il.gross_price
FROM invoice_lines il
WHERE ABS(il.gross_price - ROUND(il.net_price * (1 + il.vat_percent/100), 2)) > 0.01;
```
