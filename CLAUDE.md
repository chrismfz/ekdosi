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
lives in the legacy source under /old/ekdosi-main and in stored values. When in doubt,
trust the legacy stored results and reproduce them — don't reinvent the math.

---

## Notes from inspection (2026-05-26)

Read this before trusting the rest of the file — some of the claims above are
aspirational rather than current state.

### Repo reality vs. the "Repo layout" block above
- There is **no `/legacy/`**. Legacy lives at:
  - `/old/ekdosi-main/`     — the C++Builder source tree
  - `/old/ekdosi-schema.sql` — the `isql -x` schema dump
  - `/old/ekdosi-main/db_backup/ekdosi.fbk` — a Firebird gbak; restore with
    `gbak -r ekdosi.fbk fresh.fdb -user SYSDBA -password masterkey` to get a
    safe sandbox for ETL dev without touching prod.
- There is **no `/database/migrations/` or `/app/Console/Commands/` at repo
  root**. The 19 migrations and `MigrateFromFirebird.php` live inside
  `/ekdosi-migration-kit/` and are duplicated as loose copies at the repo
  root. Decide once: either move the kit's contents up to standard Laravel
  paths (`database/migrations/`, `app/Console/Commands/`) and delete the
  duplicates, or keep the "kit" framing and delete the root copies. Today
  both exist and they are byte-identical.
- **There is no Laravel app here yet** — no `composer.json`, no `artisan`.
  The `php artisan migrate:firebird ...` command in the README will not run
  until somebody scaffolds Laravel 11 (and Filament) and drops the kit into
  it. Calling that out so we don't waste time looking for a missing bug.

### It's C++Builder, not Delphi
The CLAUDE/README repeatedly say "Delphi". The source is **C++Builder (VCL)**
— `.cpp` + `.h` + `.dfm` forms, project files `Ekdosi.cbproj`, uses cxGrid,
JVCL, IBX (`TIBQuery`/`TIBTransaction`), `TNetHTTPClient`. The math idioms
("AsCurrency", "AsFloat") and the IBX dataset events are what to grep for,
not Pascal. Source files are saved as **WIN1253** (ISO-8859-ish to `file`);
to read Greek comments/string literals: `iconv -f WINDOWS-1253 -t UTF-8 X.cpp`.

### The myDATA submit class is NOT in this repo
`FAutoInvoice.cpp` does `#include "CMyData.h"` and calls
`MyData::sendInvoice(invoiceId)` (line 648 and 663). That header — together
with `CEditBox.h`, `CMySpecialForm.h`, `RegAccess`, and the rest of the
project's shared utility classes — is on an external include path
(`OLD_INCLUDES/INCLUDES_UNIQUE.txt` lists them). The XML build + HTTPS POST
+ MARK parsing live in `CMyData.cpp` which we don't have. So:
- We **cannot** port the legacy submit logic line-by-line. Good news: the
  plan is already not to — `firebed/aade-mydata` replaces it wholesale.
- The only XML reference we have is the three string templates in
  `mydataConstants.h` (APY, INVOICE, INV_LINE). They have **hardcoded**
  `<vatCategory>1</vatCategory>` and `<withheldPercentCategory>3</...>` —
  do NOT replicate that hardcode in Laravel; the firebed library expects
  per-line VAT category derived from the line's VAT rate, and
  withholding category derived from the παρακράτηση type.
- `FShowMyData.cpp` uses `https://mydata-dev.azure-api.net/...` (the dev
  sandbox endpoint). Production is `https://mydatapi.aade.gr/myDATA/`.
  Headers seen: `aade-user-id`, `Ocp-Apim-Subscription-Key`. Per-tenant
  creds belong on `companies` as already planned.

### The VAT / discount / rounding math (the part we DO have to port)
Lives in `FAddInvoice.cpp` `showSums()` (line ~269) and the symmetric
`calcPrices()` in `FAddInvoice2.cpp` (line ~245), with mirrors in
`FEditInvoice.cpp`. Algorithm:

```
# Per line:
PRICE       = QTY * PRICE_PER_ITEM
PRICE       = PRICE - PRICE * (DISCOUNT_line / 100)    # line-level discount
PRICEWVAT   = PRICE * (1 + VATPERCENT / 100)

# Per invoice header (recomputed from line sums + invoice-level DISCOUNT %):
priceWOutVat       = SUM(line.PRICE)
priceSumWVat       = SUM(line.PRICEWVAT)
invoice.PRICE      = priceWOutVat - priceWOutVat * (DISCOUNT_inv / 100)
invoice.PRICEWVAT  = priceSumWVat - priceSumWVat * (DISCOUNT_inv / 100)
invoice.VATtotal   = invoice.PRICEWVAT - invoice.PRICE      # derived, not stored

# Gross-edit path (when user types a gross unit price):
PRICE_PER_ITEM = PRICE_PER_ITEM_WVAT / (1 + VATPERCENT / 100)

# Withholding (FAddInvoice.cpp:819):
WITHHOLD_AMOUNT = invoice.PRICE * 0.20    # flat 20%, ΠΚ-3 in mydata
```

Important rounding subtlety: legacy uses Borland `TCurrency` (4-decimal
fixed-point) for the in-form math, but the FB columns are `DECIMAL(14,2)`
— so the persisted values are silently rounded to 2dp **on write**, not
at each intermediate. In PHP, recreate this by doing the math in
high-precision (bcmath or floats are fine here since amounts are small)
and `round($x, 2)` only when assigning to the model attribute, **never**
between intermediate sub-sums. The golden test in the README is the
correct check; expect a handful of off-by-€0.01 rows on import — most
will be invoice-level discount lines where legacy did one round at the
end.

### Numbering details (corrects the "Architectural decisions" block)
- Numbering generator: `INVTYPE.INVCOUNT` (per invoice type, monotonically
  increasing), as already noted. Confirmed by `INVOICE_BI1` (sets
  `NEW.CODE = INVTYPE.INVCOUNT`) and `INVOICE_AI` (bumps `INVCOUNT` by 1
  *after* insert) at schema.sql:953-974.
- `INVCODE` (the human-readable code, unique) is generated by stored
  procedure `GET_INV_CODE(INVTYPE)` — we need to either reproduce that
  format in Laravel or read it out of `CONF_PARAMS` if it's configurable.
  **Open question** — read the SP body in schema.sql before designing the
  Filament invoice-issue page.
- `MARK_AI0` (schema.sql:984): on insert into MARK with action='INSERT',
  it pushes MYDATA_SENT/MYDATA_STATE/MYDATA_MARK/MYDATA_URL back onto
  INVOICE. On 'CANCEL', it clears them. This is the denormalisation
  cache the README mentions — the new code must do the same write inside
  the same DB transaction that creates the `mydata_marks` row.

### Reports — out of scope but documented
`/old/ekdosi-main/reports/` ships FastReport 3 templates (`.fr3`):
`simple_invoice`, `apy` (ΑΠΥ), `tpy` (ΤΠΥ), `sdep` (ΣΔΕΠ), `SDAP`/`SDAP2`
(ΣΔΑΠ), `first`/`second`. Confirms the invoice types currently in use.
We are dropping FR3 entirely; rebuild as Blade→PDF (e.g. `barryvdh/laravel-dompdf`
or `spatie/browsershot`) once the Filament action flow is in place.

### Magic constants in `Constants.h` — do NOT carry over
- `CipherKey = "e9e65b53fdf7df86940eb6192dce923ef7644e29"` — used for the
  ekdosi↔CS-Cart customer-portal sync (`FCSConnect.cpp`). Already out of
  scope; if we ever revive the WHMCS bridge use modern key management.
- `DB_GROUP_ID = 47` — magic number, role unclear; investigate if any
  imported row references it before deleting.
- `SDAP NO`, `CSCART_SYNC NO`, `PROTIMOLOGIO YES`, `SHOW_PDF_TAB YES` —
  compile-time feature flags. Translate to per-tenant settings on
  `companies` (or `conf_params`) only if the flag is currently `YES`.

### Schema columns worth re-checking before finalising migrations
- `INVOICE` has both `INVDATE` (DATE) and `INVTIME` (TIME) — already
  merged to `invoices.issued_at` per README; just don't forget to
  combine them in the ETL.
- `INVOICE.DISCOUNT CURRENCY` — note: stored as currency (an amount) at
  the schema level, but the C++ code treats it as a **percent** (0-100):
  `priceWOutVat * (DISCOUNT/100)`. Confirm what real rows contain — if
  values >100 ever appear, somebody flipped semantics. New schema should
  rename to `discount_percent decimal(5,2)`.
- `INVOICE.CONV_INVOICE_ID` — undocumented self-reference; likely the
  "converted from delivery note → invoice" link. Worth checking.
- `INVOICE.VIES_VAT` and `INVOICE.VAT_NO` both exist — the second seems
  to be a snapshot of the customer's AFM at issue time (good practice
  for legal docs). Keep both as snapshot columns in `invoices`.
- `RETURN_INVOICE_EXTRAS` has no PK in the dump (just `QTY_GIVEN`); ETL
  needs synthetic keys.

### Quick-glance file map of the legacy source
- `FMain.*` — main shell window, menus
- `FAddInvoice.*` / `FAddInvoice2.*` / `FEditInvoice.*` — invoice issue/edit
  forms; canonical home of VAT+discount math
- `FAutoInvoice.*` — overnight job that mails + sends-to-myDATA queued invoices
- `FShowInvoices.*` / `FShowMyData.*` / `FShowMyDataRemainingInvoices.*` —
  list views; the last is "what hasn't been sent to myDATA yet"
- `FShowCustomers.*` / `FaddCustomer.*` / `FUpdateCustomerDetails.*` — customer CRUD
- `FShowProducts.*` / `FAddProduct.*` — product CRUD
- `FManageInvTypes.*` — invoice-type config incl. myDATA mapping
  (`MYDATA_TYPE`, `MYDATA_INCOME_CLASS`, `MYDATA_INCOME_CLASS_CATEGORY`)
- `FCSConnect.*` / `FManageCSUsers.*` / `FManageCSInvoices.*` — CS-Cart
  bridge (the customer-portal staging the README dropped)
- `FMysqlSync.*` — there's also a one-way push to a MySQL mirror; check
  whether myip still relies on it before we kill it
- `FInvoiceReturn.*` — credit notes (returns)
- `FPrint.*` — FR3 print harness
- `mydataConstants.h` — the three XML templates (above)
- `Constants.h` — feature flags + the cipher key
- **Missing from this repo**: `CMyData.{h,cpp}`, `CEditBox.{h,cpp}`,
  `CMySpecialForm.{h,cpp}`, `RegAccess.{h,cpp}`. If we hit a behavioural
  question that lives in one of these, we'll need to ask for the
  external includes folder.

### Open questions surfaced by inspection
- [ ] Does `myip` still use `FMysqlSync` (one-way MySQL mirror)? If yes,
      it overlaps with WHMCS bridge plans — decide which is canonical.
- [ ] Read `GET_INV_CODE` stored procedure body in schema.sql to decide
      whether INVCODE format needs to be carried over or can be regenerated.
- [ ] Get `CMyData.cpp` from the legacy dev box, OR confirm we'll never
      need to reproduce its exact XML byte-for-byte for old MARK audits.
- [ ] Decide: collapse `/ekdosi-migration-kit/` into the repo root (and
      delete the duplicate `README.md` + `MigrateFromFirebird.php` at
      root), or vice versa.
- [ ] Scaffold a Laravel 11 + Filament app at repo root before any of the
      `php artisan` commands documented above become real.
