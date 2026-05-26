# CLAUDE.md — ekdosi modernization

Context for working in this repo. Read this first.

## What this project is
Porting a legacy **C++Builder (VCL) + Firebird** invoicing app ("ekdosi") to a
modern **Laravel 13 + FilamentPHP + MariaDB** stack. The legacy app is a
homegrown τιμολογιέρα, updated over the years to support **myDATA**, **QR**,
and a **WHMCS bridge**. It is operators-only (internal, no customer-facing
frontend) and compiles only on a fragile Windows 7 VM — escaping that toolchain
is the whole point.

Scope: customers, stock/products, services, invoices (παραστατικά), payments,
myDATA submission + audit trail, WHMCS bridge. No customer portal.

## Goal & end state
- Single multi-tenant MariaDB (`company_id` on every table), one Laravel codebase,
  one Filament panel with tenant switching.
- Legacy myDATA logic NOT re-ported by hand — use `firebed/aade-mydata`
  (framework-agnostic; wrap it ourselves in `App\Services\MyDataSubmitter`).
  Per-tenant credentials live on `companies`.
- WHMCS bridge **stays in scope** (myip relies on it). Re-implement
  PHP-to-PHP via the **WHMCS API** (decision locked — not shared-DB
  read), replacing the legacy `AUTO_INVOICE_LOG` polling + `FMysqlSync`
  push.
- **Multi-country from day one**: 3 tenants today, 2 Greek (myDATA) + 1
  Estonian. The Estonian tenant doesn't submit myDATA — but Estonia is
  moving to mandatory **e-invoicing (RIK / PEPPOL-based)**: B2G is
  already mandated, broader B2B is on the roadmap. Either way that's a
  new integration we need. Design `companies` so each tenant has a
  **country profile / e-invoice provider** (`gr-mydata`, `ee-peppol`,
  `none`) that selects the right submitter behind a common
  `IssueInvoice` action. Scaffold the Estonian PEPPOL submitter as a
  stub now (real implementation when the Estonian deadline forces it).
- Old C++Builder/Firebird app stays read-only/archived for history after cutover.

## Stack (proposed — adjust before locking in)
Pinning the picks so we don't churn on this. These are defaults; flag any you
want to change.

- **PHP 8.4+** (Laravel 13 requires it).
- **Laravel 13** (current latest at 2026-05).
- **MariaDB 11.x** with `utf8mb4` / `utf8mb4_unicode_ci`.
- **FilamentPHP 5** as the admin panel — and as the **tenancy driver**. Each
  Filament panel resolves a Company tenant; no separate multi-tenancy
  package on top. (Reason: Filament tenancy is built for this exact shape
  and saves us a layer.)
- **myDATA**: `firebed/aade-mydata` (framework-agnostic; we wrap it in
  `App\Services\MyDataSubmitter`). Per-tenant credentials on `companies`.
  (See "myDATA: library vs. custom" below for why — short version: the
  legacy `CMyData.cpp` isn't even in this repo, the AADE spec evolves,
  and the library handles transport/types/errors so we only own the
  mapping from our `Invoice` model to their payload.)
- **Roles & permissions**: `spatie/laravel-permission` +
  `bezhanSalleh/filament-shield`. Shield auto-generates per-resource
  permissions and gives us a UI to manage roles. Default roles per
  tenant: `admin`, `operator`, `accountant_readonly`.
- **Audit log**: `spatie/laravel-activitylog` on `invoices`, `customers`,
  `mydata_marks` — useful for "who changed this and when" and for the
  parallel-run period.
- **PDF rendering** (replacing the FR3 reports): default to
  `barryvdh/laravel-dompdf` for invoices; only escalate to
  `spatie/browsershot` if we need CSS that DomPDF chokes on.
- **Backups**: `spatie/laravel-backup` against S3-compatible storage.
- **Queue / scheduler**: Laravel's built-in queue (database driver for now;
  Redis if the WHMCS pull / myDATA-resend backlog warrants it).
- **Firebird driver on the ETL host**: `pdo_firebird` PHP extension. Only
  the artisan host needs it; the main app box doesn't.

## Getting started (status: scaffold done; build phase next)

**Done** (see git log on the scaffold branch):
- ✅ Laravel 13 scaffolded at the repo root.
- ✅ `.env` points at the local MariaDB instance (`ekdosi` / `ekdosi` /
  `ekdosi-dev`, socket `/var/run/mysqld/mysqld.sock`).
- ✅ Migration kit flattened: `database/migrations/` holds all 19 ekdosi
  migrations alongside Laravel's defaults; `app/Console/Commands/`
  holds `MigrateFromFirebird.php`. The `/ekdosi-migration-kit/` subdir
  is gone.
- ✅ Legacy reference tree moved from `/old/` to `/legacy/`.
- ✅ `php artisan migrate:fresh` runs cleanly: 3 Laravel defaults + 19
  ekdosi tables (= 22 tables total) created against MariaDB 10.11.
- ✅ Stack installed: Filament 5.6.5, Shield 4.2.0, firebed/aade-mydata
  5.10, spatie/laravel-permission 7.4, spatie/laravel-activitylog 5.0,
  barryvdh/laravel-dompdf 3.1, spatie/laravel-backup 10.2. Permission
  and activity-log migrations applied (24 tables total now).
- ✅ Admin Panel provider scaffolded at
  `app/Providers/Filament/AdminPanelProvider.php` (default Filament
  panel; tenancy/Company model not wired yet).

**Next steps**:
1. **Build the Company tenant model + Filament panel tenancy**, then run
   `php artisan shield:install --tenant=Company` to generate the
   Resource-level permissions. Default roles: `admin`, `operator`,
   `accountant_readonly`.
2. **Sandbox-test the ETL** against the restored `gbak`:
   ```bash
   gbak -r /home/user/ekdosi/legacy/ekdosi-main/db_backup/ekdosi.fbk \
       /tmp/ekdosi-sandbox.fdb -user SYSDBA -password masterkey
   php artisan migrate:firebird --company="Sandbox" --slug=sandbox \
       --fdb=/tmp/ekdosi-sandbox.fdb --host=127.0.0.1 \
       --fbuser=SYSDBA --fbpass=masterkey
   ```
   Blocked on `pdo_firebird` extension — see the env-prep note below.
3. **Build the Customer Filament resource** as the smallest end-to-end
   slice. Verify the tenant scoping actually scopes (CUST_ID=1 must
   show only the current tenant's row).
4. Then Products, then Invoices (read-only view first), then the
   issue-invoice flow (which is the first thing that touches the
   `firebed/aade-mydata` library and the VAT/rounding math).
5. WHMCS bridge last — once the manual-issue path is proven.

This order keeps the highest-risk pieces (VAT math, myDATA submit) gated
behind a working tenancy + CRUD foundation, so when they break we know
it's not infrastructure.

**Env-prep blocker**: `pdo_firebird` (the PHP extension the ETL needs to
talk to the legacy `.fdb`) lives only in the `ondrej/php` PPA, which is
403-blocked in the current Claude Code on the web sandbox. Either
whitelist that PPA in the environment's network policy, or build the
extension from source against `firebird-dev`. Not blocking for steps 1
and 3-5; only step 2 (sandbox-test the ETL).

## Repo layout
```
/                              # Laravel 13 app at repo root
  app/Console/Commands/MigrateFromFirebird.php   # re-runnable ETL, one tenant per run
  app/                                           # Laravel app code (models, panels, services)
  database/migrations/                           # 22 migrations: 3 Laravel defaults + 19 ekdosi
  config/                                        # Laravel config
  ...                                            # standard Laravel layout
/legacy/                       # legacy reference material (do not build)
  ekdosi-schema.sql                              # isql -x dump (WIN1253 DB; ASCII DDL is clean)
  ekdosi-main/                                   # C++Builder source (.cpp/.h/.dfm) — real VAT/rounding lives here
  ekdosi-main/db_backup/                         # gbak of the Firebird DB; restore for sandboxed ETL dev
  ekdosi-main/reports/                           # FastReport 3 (.fr3) templates — out of scope, rebuild as PDF
CLAUDE.md                      # you are here
README.md                      # top-level overview (also tracks migration-kit history)
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
   VAT/rounding port bugs (hiding in the C++Builder code) surface.
4. Planned cutover: ETL one last time, C++Builder app → read-only archive, kill the
   Win7 VM.

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
- [ ] Port + golden-test the line/total/VAT/rounding math from the legacy
      source (`FAddInvoice.cpp:showSums()` and `FAddInvoice2.cpp:calcPrices()`).
- [ ] Verify `CONF_PARAMS` keys still needed; migrate app config into Laravel config/env.

## Source of truth note
The schema is settled; the *behaviour* (VAT, rounding, discounts, myDATA payload shape)
lives in the legacy source under `/legacy/ekdosi-main/` and in stored values. When in doubt,
trust the legacy stored results and reproduce them — don't reinvent the math.

## Reading the legacy source
- It's **C++Builder (VCL)** — `.cpp` + `.h` + `.dfm` forms, `Ekdosi.cbproj`,
  uses cxGrid, JVCL, IBX (`TIBQuery`/`TIBTransaction`), `TNetHTTPClient`. Grep
  for `AsCurrency` / `AsFloat` and IBX dataset events (`*BeforePost`,
  `*AfterPost`), not Pascal idioms.
- Source files are saved as **WIN1253**. To read Greek comments and string
  literals: `iconv -f WINDOWS-1253 -t UTF-8 FAddInvoice.cpp | less`.
- **Some shared utility headers are NOT in this repo** — `CMyData.h`,
  `CEditBox.h`, `CMySpecialForm.h`, `RegAccess.h`. They live on an external
  include path on the legacy dev box. If we ever need byte-exact reproduction
  of legacy myDATA XML (e.g. to validate old MARK audits) we'll need those;
  for forward issuing we don't — `firebed/aade-mydata` replaces all of it.

## Sandbox the legacy DB before touching prod
`/legacy/ekdosi-main/db_backup/ekdosi.fbk` is a `gbak` of the Firebird DB.
Restore with:
```bash
gbak -r ekdosi.fbk fresh.fdb -user SYSDBA -password masterkey
```
…and point the ETL at the restored `.fdb` while iterating.

---

## Notes from inspection (2026-05-26)

### myDATA: library vs. custom port (decision: use the library)
**Decision: use `firebed/aade-mydata` (framework-agnostic; we wrap it
ourselves in `App\Services\MyDataSubmitter`). Do not port the legacy
implementation.**

Reasons:
1. The legacy myDATA class (`CMyData.cpp`) is **not in this repo** —
   `FAutoInvoice.cpp` `#include`s `CMyData.h`, which lives on an external
   include path on the legacy dev box. We can't copy-paste even if we
   wanted to.
2. The only legacy myDATA artefacts we *do* have are the three XML
   templates in `mydataConstants.h`, and they have **hardcoded** stub
   values (`<vatCategory>1</vatCategory>`,
   `<withheldPercentCategory>3</withheldPercentCategory>`) that are not
   correct for general use. Carrying them forward verbatim would ship
   bugs.
3. The AADE myDATA spec moves — invoice types, expense classifications,
   error envelopes, sandbox endpoints. An actively-maintained library
   tracks those; our own implementation would silently rot.
4. The library covers transport (HTTP + auth headers), XML build, response
   parsing, MARK extraction, error mapping, type catalogues. None of
   that is domain-specific to ekdosi.

What we **do** own (and what the library can't):
- Mapping our `Invoice` + `InvoiceLine` Eloquent models → the library's
  payload objects, including the correct per-line `vatCategory` from
  the VAT rate, and `withheldPercentCategory` from the παρακράτηση type.
- Choosing per-invoice-type `mydata_type` / `income_class` /
  `income_class_category` (from `invoice_types` config — already in the
  schema).
- Persisting the response: full request/response XML into
  `mydata_marks` (legal audit), then mirroring MARK / URL / state onto
  `invoices.mydata_*` inside the same DB transaction
  (replaces the legacy `MARK_AI0` trigger).
- Cancel flow + resubmit handling.

Implementation shape: a thin `App\Services\MyDataSubmitter` service
that takes an `Invoice` and returns a saved `MyDataMark`. All call sites
(Filament action, WHMCS-triggered job, retry command) go through it.
That isolates the library so we can swap it later if needed.

How the legacy artefacts stay useful:
- `CMyData.cpp` is **lost** — not in this repo, can't be located on the
  legacy dev box either. We will **not** byte-match legacy MARK XMLs.
  Acceptable: the AADE-side MARK is the source of truth for filed
  invoices, and our new submissions only need to be *semantically*
  equivalent (same line totals, same VAT category, same income
  classification, same MARK on the response). Spec compliance, not
  string compliance.
- During parallel-run, the stored legacy request/response XMLs (in
  `mark.response` from the legacy DB, imported into `mydata_marks`)
  are reference data: build our payload for the same invoice, diff
  against the stored legacy XML at the **field level** (totals,
  categories, classifications), and investigate every semantic
  divergence. Differences in whitespace / element order / element
  serialisation are expected and fine.

### Legacy myDATA — pointers (for archaeology only)
Concrete file references in case we need to dig:
- `FAutoInvoice.cpp:648,663` — `MyData::sendInvoice(invoiceId)` call sites.
- `CMyData.{h,cpp}`, `CEditBox.*`, `CMySpecialForm.*`, `RegAccess.*` — on
  the legacy dev box's external include path (see
  `OLD_INCLUDES/INCLUDES_UNIQUE.txt`); not in this repo.
- `mydataConstants.h` — the three XML templates (APY, INVOICE, INV_LINE).
  Useful only as a reference for which fields the legacy app populated;
  do not reuse the hardcoded `vatCategory`/`withheldPercentCategory`
  stubs (see decision section above).
- `FShowMyData.cpp:64` — uses
  `https://mydata-dev.azure-api.net/RequestTransmittedDocs?mark=0` (dev
  sandbox endpoint). Production is `https://mydatapi.aade.gr/myDATA/`.
  Auth headers seen: `aade-user-id`, `Ocp-Apim-Subscription-Key`. The
  firebed library handles all of this; we only need to populate per-tenant
  credentials on `companies`.

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
`/legacy/ekdosi-main/reports/` ships FastReport 3 templates (`.fr3`):
`simple_invoice`, `apy` (ΑΠΥ), `tpy` (ΤΠΥ), `sdep` (ΣΔΕΠ), `SDAP`/`SDAP2`
(ΣΔΑΠ), `first`/`second`. Confirms the invoice types currently in use.
We are dropping FR3 entirely; rebuild as Blade→PDF (e.g. `barryvdh/laravel-dompdf`
or `spatie/browsershot`) once the Filament action flow is in place.

### Magic constants in `Constants.h` — do NOT carry over verbatim
- `CipherKey = "e9e65b53fdf7df86940eb6192dce923ef7644e29"` — used for the
  ekdosi↔**CS-Cart** customer-portal sync (`FCSConnect.cpp`). **CS-Cart
  bridge is dropped entirely** — the code exists in the legacy tree but
  was never actually needed. Don't carry over the key, the forms, or the
  staging table.
- Note: CS-Cart and WHMCS are two different bridges. **WHMCS stays in
  scope** (see the "Billing-system bridges" section below); CS-Cart does
  not.
- `DB_GROUP_ID = 47` — magic number, role unclear; investigate if any
  imported row references it before deleting.
- `SDAP NO`, `CSCART_SYNC NO`, `PROTIMOLOGIO YES`, `SHOW_PDF_TAB YES` —
  compile-time feature flags. Translate to per-tenant settings on
  `companies` (or `conf_params`) only if the flag is currently `YES`.

### Billing-system bridges — WHMCS now, possibly Blesta later
**WHMCS is in scope and needed by myip.** Blesta is a likely future addition
(same niche as WHMCS). Don't over-abstract day one, but leave room: the
"issue an invoice from an upstream billing system" code path should not be
WHMCS-named end-to-end — a thin provider interface (`pullPendingInvoices()`,
`mapToInvoice()`) makes adding Blesta a new implementation, not a refactor.

Schema today vs. tomorrow:
- Current migrations name things `whmcs_client_id` and `whmcs_invoice_log`
  (mirroring legacy). If/when Blesta arrives, options are:
  - **(a) Keep per-provider columns/tables** — add `blesta_client_id`,
    `blesta_invoice_log`. Simple, no rename, dead-easy ETL. Recommended
    while only WHMCS is real.
  - **(b) Generalise to `billing_provider` + `external_client_id` +
    `external_billing_log`.** Only worth doing once Blesta is committed.
- **Open decision** — defer until Blesta is real. Don't pre-generalise.

Legacy design (what to replace, not what to reproduce):
- `AUTO_INVOICE_LOG` — staging table the legacy app polled to pick up
  WHMCS-originated invoice intents. Mapped to `whmcs_invoice_log` in the new
  schema.
- `CUSTOMER_CS_ACCEPTED` — **NOT** WHMCS; it's CS-Cart staging. Dropped
  entirely (see above).
- Customer↔WHMCS link: was a join through bridge tables; now flat on
  `customers.whmcs_client_id`.
- `FAutoInvoice.cpp` is the legacy job that drained the queue + sent to
  myDATA + emailed PDFs. The new equivalent is a Laravel scheduled
  command (or queue worker) that:
  1. Pulls WHMCS invoices via the **WHMCS API** (decision locked: API,
     not shared-DB read). Decoupled from WHMCS's internal schema,
     survives WHMCS upgrades, and works even when WHMCS lives on a
     different host.
  2. Maps to `customers` (by `whmcs_client_id`) and creates an `invoice`
     + `invoice_lines`.
  3. Issues through the normal myDATA action (so MARK is recorded the
     same way as manually-issued invoices — single code path).
  4. Records the WHMCS↔ekdosi linkage in `whmcs_invoice_log` for audit
     and idempotency.
- **Legacy `FMysqlSync` was the "bridge"**: it pushed ekdosi data into the
  same MySQL that WHMCS reads. The new design replaces that with the API
  pull above — we **do not** keep writing to that mirror. During the
  parallel-run window (legacy + new app live at once), keep the legacy
  push enabled so WHMCS continues to see invoices; at cutover, switch
  WHMCS to read from / be queried by the new Laravel app and turn the
  mirror off.

### Data migration — how the cutover actually happens
This is what `MigrateFromFirebird.php` exists for; spelling out the story:

- **One-shot Firebird → MariaDB ETL per legacy DB**, re-runnable. Each
  `.fdb` becomes one tenant (`companies` row + `company_id` stamped on
  every imported row). Command:
  ```bash
  php artisan migrate:firebird --company="MyIP" --slug=myip \
      --fdb=/opt/Data/ekdosi-myip.fdb --host=10.23.22.5 \
      --fbuser=EKDOSI --fbpass=ekdosi1234
  ```
- **Re-runnable** because every legacy row keeps its `legacy_id`
  (unique per company). Re-running upserts on `(company_id, legacy_id)`
  — so we can do dry runs, fix bugs, re-run, fix more, re-run, then do
  one final pass at cutover with the legacy app stopped.
- **Sandbox first**: restore `/legacy/ekdosi-main/db_backup/ekdosi.fbk`
  into a throwaway `.fdb` and point the ETL there until it's green.
- **Charset**: connect with `charset=UTF8` (Firebird transliterates
  from the WIN1253 source on read). Fallback path documented in this
  file's Charset section.
- **What the ETL must touch** (so we don't forget anything mid-cutover):
  - Reference tables first: `payment_methods`, `delivery_methods`,
    `distribution_aims`, `metric_units`, `vat_categories`,
    `product_categories`, `invoice_types` (including `invcount`!).
  - Then: `customers`, `products`, `product_price_tiers`.
  - Then: `invoices` (`INVDATE`+`INVTIME` → `issued_at`),
    `invoice_lines`, `return_invoice_extras`, `payments`.
  - myDATA audit: `mark` → `mydata_marks` (preserve original request/
    response XML, MARK, URL, timestamps).
  - `conf_params`, `whmcs_invoice_log` (legacy `AUTO_INVOICE_LOG`).
- **What needs migrating from outside Firebird too**:
  - **WHMCS data**: existing WHMCS↔customer linkages need to land on
    `customers.whmcs_client_id` during the ETL. If those mappings aren't
    in the Firebird DB (likely partly in WHMCS, partly in `FMysqlSync`'s
    mirror), the ETL needs a second source — pulling the WHMCS client
    list and matching on AFM / email / name.
  - **Sequence continuation**: `invoice_types.invcount` is the running ΑΑ
    per type; the ETL copies it as-is so the new app continues from the
    same number. No fiscal-boundary cutover required.
- **Golden-test gate** (see README): after each ETL run, recompute
  line/invoice totals from imported inputs and compare to the
  imported `PRICE`/`PRICEWVAT`. Any divergence is a port bug in our VAT
  math, not a data issue.
- **Cutover sequence** (write this up as a runbook before the actual day):
  1. Freeze legacy app (read-only / users out).
  2. Final `gbak` of each `.fdb`.
  3. Run `migrate:firebird` against the final dumps.
  4. Run golden tests; fail-stop if anything diverges.
  5. Snapshot the MariaDB; switch DNS / app pointers.
  6. Archive the Firebird DBs + the C++Builder source for legal-retention.

### Stored procedure inventory (real domain logic we need to port)
The schema dump uses `isql -x`'s two-pass output: line ~315 has stub
declarations (`BEGIN SUSPEND; END`), real bodies follow at line ~479
under `ALTER PROCEDURE`. The ones with actual logic:

- **`GET_INV_CODE(XINVTYPE)`** (schema.sql:668) — INVCODE format is just
  `INVTYPE || INVCOUNT` (no zero-pad, no fiscal year). e.g. `APY423`.
  Reproduce as `$invoice->code = $type->code . $type->invcount;` in the
  issue flow.
- **`CALCULATE_INVOICE_VALUES(INVOICE_ID)`** (schema.sql:479) —
  `INVOICE.PRICE = SUM(INVLINES.PRICE)`, `INVOICE.PRICEWVAT = SUM(INVLINES.PRICEWVAT)`.
  Pure roll-up, no invoice-level discount applied here. The
  invoice-level discount math lives in the C++ code (see VAT section
  above), not in this SP.
- **`CALCULATE_VAT_FOR_INVOICE(INVOICE_ID)`** (schema.sql:490) —
  returns per-VAT-rate breakdown for the invoice:
  `vat = SUM(PRICEWVAT - PRICE) - SUM(PRICEWVAT - PRICE) * (INVOICE.DISCOUNT / 100)`
  GROUP BY VATPERCENT. **Invoice-level discount IS applied here.** This
  is what we need for myDATA's `taxesTotals` / per-rate VAT amounts —
  port carefully.
- **`GET_CUSTOMER_BALANCE(CUST_ID)`** (schema.sql:652) —
  `balance = SUM(INVOICE.PRICEWVAT WHERE DUE_DAYS > 0) - SUM(PAYMENT.VALUE)`.
  Cash-term invoices (DUE_DAYS = 0) **don't count** toward balance. One-liner
  Eloquent scope.
- **`MYDATA_EXTRACT_URL`** (schema.sql:723) — substring-parses `<qrUrl>`
  out of the AADE response XML into `MARK.INVOICE_URL`. The firebed
  library exposes the QR URL directly; we don't port this.
- **`FILL_PRDESCR_INVLINES`** (schema.sql:517) — one-time backfill that
  copies `PRODUCT.DESCRIPTION_SHORT` and metric unit name onto invoice
  lines. Equivalent in the new app: do this denormalisation at line-add
  time (not via a maintenance proc).
- **`LZ_PAD`** — generic left-zero-pad; drop, use `str_pad` in PHP.
- **`GET_COMB_*`** — already dropped (cross-DB credential landmine).
- **`CREATE_RETURN_INVOICE`** (schema.sql:510) — **empty body in the dump**.
  Credit-note creation logic lives in `FInvoiceReturn.cpp`, not in SQL.
- **`SHOW_CUMULATIVE_INVOICE`, `YIELD_RETINV_STATS`, `INSPECT_CUST_ORDER`,
  `SWAP_CUST_ORDER`, `CHECK_PROD_AVAILABILITY`** — reporting / maintenance
  helpers. Reimplement as needed; not blocking for the core port.

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
- `FMysqlSync.*` — one-way push to a MySQL mirror. Likely the legacy
  shortcut that fed WHMCS / the customer-portal. Inspect before deciding
  whether the new WHMCS bridge needs to keep writing into that mirror
  during the parallel-run window.
- `FInvoiceReturn.*` — credit notes (returns)
- `FPrint.*` — FR3 print harness
- `mydataConstants.h` — the three XML templates (above)
- `Constants.h` — feature flags + the cipher key
- **Missing from this repo**: `CMyData.{h,cpp}`, `CEditBox.{h,cpp}`,
  `CMySpecialForm.{h,cpp}`, `RegAccess.{h,cpp}`. If we hit a behavioural
  question that lives in one of these, we'll need to ask for the
  external includes folder.

### Resolved decisions (2026-05-26)
- WHMCS pull: **API** (not shared-DB).
- `FMysqlSync` target MySQL = the WHMCS DB ("the bridge"). Legacy push
  stays on during parallel-run; killed at cutover when WHMCS reads from
  the new app instead.
- `CMyData.cpp`: **lost**. No byte-match of legacy XMLs; semantic
  equivalence only, validated field-by-field during parallel-run.
- Target stack: **Laravel 13 + Filament 5 + PHP 8.4 + MariaDB 11**.
- Auth/authz: **`spatie/laravel-permission` + `bezhanSalleh/filament-shield`**.

### Still open
- [ ] Estonian PEPPOL submitter: which library? Candidates include
      `nikolajlovenhardt/laravel-peppol`, `digitalcz/peppol-php`, or
      direct integration with Estonia's RIK e-arveldaja. Defer until we
      know the actual deadline for the Estonian tenant.
- [ ] `pdo_firebird` PHP extension to unblock ETL testing. Either get
      `ondrej/php` PPA allowlisted in the env's network policy, or
      build `pdo_firebird` from source against `firebird-dev` headers.
