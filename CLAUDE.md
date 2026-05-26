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

### WHMCS bridge — preparation notes (what to read when the bridge PR starts)

Inventory of the legacy WHMCS surface so the bridge PR doesn't start
from a blank page. Captured here before we lose track; the actual
bridge work lands after PR #25 (MyDataSubmitter).

**Legacy MySQL credentials live in the Windows Registry**, NOT hardcoded:
- `FDBParams.cpp:80-86` reads `hostname / path / username / password`
  via the Registry helper class.
- `FDBParams.cpp:236-239` writes them back to keys named
  `MySQLHostname / MySQLDbName / MySQLUsername / MySQLPassword`.
- `FMysqlSync.cpp:38-43` constructs the connection from those keys at
  runtime.
- For the new bridge we move to per-tenant credentials on `companies`
  (mirroring the AADE / GSIS pattern from PR #22) — new columns
  `whmcs_api_url`, `whmcs_api_identifier`, `whmcs_api_secret`
  (encrypted), `whmcs_db_*` only if we genuinely need direct DB
  access (the CLAUDE.md decision was API-only, see line 22-25).

**WHMCS-side plugins** are already in `legacy/whmcs/`:
- `legacy/whmcs/afm2name/` — AFM → name lookup via SOAP to GSIS (we
  already have this functionality natively in PR #22's
  `AadeRegistryLookup`; this plugin is for WHMCS-side use only).
- `legacy/whmcs/prepare_for_ekdosi/` — WHMCS addon that flips
  `tblinvoices.invoiced` after ekdosi has filed the invoice. The new
  bridge does the equivalent via the WHMCS API
  (`UpdateInvoice` with custom field).
- `legacy/whmcs/timologia/` — third-party-invoices addon; creates
  `mod_timologia` + `mod_timologia_servicetypes` tables. Lets a WHMCS
  client say "issue this invoice to another company" (e.g. employer
  reimbursement). Bridge needs to consume this data.

**Legacy SQL the bridge replaces**:
- `FAutoInvoice.dfm:QueryInvoices` runs a 100-line SQL JOIN against
  `tblinvoices`, `tblclients`, `tblcustomfieldsvalues`,
  `mod_timologia*`. The "ready to file" condition is
  `WHERE mi.status='Paid' AND mi.invoiced = 0 AND gkriniaris = 'on'`.
  See `legacy/ekdosi-main/FAutoInvoice.dfm` for the full query.
- `FAutoInvoice.cpp:307` updates back via
  `UPDATE tblinvoices SET invoiced = :mark WHERE id = :id` — the new
  bridge does this via the WHMCS API instead of direct SQL.

**Hardcoded magic in the legacy bridge** — these will rot if WHMCS
config drifts; capture them in `companies.whmcs_custom_field_map`
(JSON column) so each tenant maps their own WHMCS instance:
- `fieldid = 12` → "toinvoice" (the company name to bill)
- `fieldid = 13` → "vatno" (customer AFM)
- `fieldid = 14` → "taxoffice" (ΔΟΥ)
- `fieldid = 15` → "occupation" (Δραστηριότητα — see legacy PDF
  example uploaded earlier)
- `fieldid = 338` → `gkriniaris` flag — the "issue immediately on
  payment" toggle (already tracked in CLAUDE.md as
  `customers.needs_immediate_invoice`)
- "Φυσικό" / "ΗΝ" Greek literals in `FAutoInvoice.cpp:322,464-466`
  classify individual vs business customer — locale-dependent, must
  not be hardcoded in the new bridge.

**Decision points for the bridge PR**:
- WHMCS API auth: identifier + secret (modern) vs username + password
  (legacy). Modern is the right call; encrypt the secret.
- Polling vs webhook: legacy polls. WHMCS has hooks (`InvoicePaid`)
  that can push to us. Hook is cheaper but adds an inbound surface.
- Custom field ID mapping: per-tenant JSON column vs a `whmcs_field_
  mappings` table. JSON simpler unless the WHMCS bridge becomes
  per-tenant complex (which it might given the timologia addon).
- `mod_timologia_servicetypes` rows — do we mirror them locally or
  hit WHMCS API per issuance?

**Trigger PR**: after the IssueInvoice action lands (PR #26). The
bridge needs the EInvoiceSubmitter to exist + a working issue flow
to call. Until then, document gaps here.

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

### Schema-drift check (2026-05-27)

Live production schema pulled from rosso's `/opt/Data/ekdosi-myip.fdb`
(see INSTALL.md §12e for the `isql -x` recipe) and committed at
`legacy/ekdosi-myip-schema-2026-05-26.sql`. Diff vs.
`legacy/ekdosi-schema.sql`:

- ✅ **Zero structural drift.** Identical CREATE TABLE / DOMAIN /
  GENERATOR / PROCEDURE / TRIGGER statements, identical per-column
  type/null/default. The snapshot in this repo IS current as of today.
- Only differences: a handful of `COMMENT ON DOMAIN ... IS 'Greek
  description'` / `COMMENT ON COLUMN ...` lines added in production
  (purely cosmetic — isql metadata documentation, no behavioural
  effect, no impact on ETL).

Implication: every column the production legacy app reads/writes
maps to a column we already migrate to MariaDB. No silent drops will
happen at cutover. Re-run this check before the actual cutover day
just to be safe; the recipe is in INSTALL.md §12e.

### Still open
- [ ] Estonian PEPPOL submitter: which library? Candidates include
      `nikolajlovenhardt/laravel-peppol`, `digitalcz/peppol-php`, or
      direct integration with Estonia's RIK e-arveldaja. Defer until we
      know the actual deadline for the Estonian tenant.
- [ ] `pdo_firebird` PHP extension to unblock ETL testing. Either get
      `ondrej/php` PPA allowlisted in the env's network policy, or
      build `pdo_firebird` from source against `firebird-dev` headers.

---

## Second-pass inspection (2026-05-27)

After scaffolding Company + Shield, did a deeper re-scan of
`/legacy/ekdosi-main/` looking for things the first-pass missed.
Sorted by what *blocks cutover* vs. what's a nice-to-have.

### Cutover blockers (must port before parallel-run)

1. **INVCODE generation + INVCOUNT increment** — legacy triggers
   `INVOICE_BI1` (sets `NEW.INVCODE` from `GET_INV_CODE(NEW.INVTYPE)`)
   and `INVOICE_AI` (bumps `INVTYPE.INVCOUNT` by 1) are NOT yet
   reproduced in Laravel. The new `IssueInvoice` action will need to:
   ```php
   DB::transaction(function () use ($invoice) {
       $type = InvoiceType::where('company_id', $invoice->company_id)
           ->where('code', $invoice->invoice_type_code)
           ->lockForUpdate()
           ->firstOrFail();
       $invoice->invcode = $type->code . $type->invcount;   // e.g. "APY423"
       $invoice->code    = $type->invcount;                  // ΑΑ
       $invoice->save();
       $type->increment('invcount');
   });
   ```
   The `lockForUpdate()` is essential — without it, two concurrent
   issues for the same type would assign the same ΑΑ.

2. **`MARK_AI0` trigger replacement** — legacy mirrors `MARK` rows back
   onto `INVOICE` (`mydata_sent`, `mydata_state`, `mydata_mark`,
   `mydata_url`). The new `App\Services\MyDataSubmitter` must do this
   inside the **same DB transaction** that creates the `mydata_marks`
   row:
   ```php
   DB::transaction(function () use ($invoice, $response) {
       $mark = MyDataMark::create([...]);
       $invoice->update([
           'mydata_sent'  => true,
           'mydata_state' => 'VALID',
           'mydata_mark'  => $response->mark,
           'mydata_url'   => $response->qrUrl,
       ]);
   });
   ```

3. **`CALCULATE_VAT_FOR_INVOICE` port** — myDATA's `taxesTotals` needs
   per-VAT-rate breakdown (line VAT amounts grouped by rate, with the
   invoice-level discount applied). The SP at schema.sql:490 is:
   ```sql
   SELECT VATPERCENT,
          SUM((PRICEWVAT - PRICE)
             - (PRICEWVAT - PRICE) * (INVOICE.DISCOUNT / 100))
   FROM INVLINES JOIN INVOICE ...
   GROUP BY VATPERCENT
   ```
   Port as an Invoice model method or InvoiceVatBreakdown value-object.

### Schema fixes worth doing BEFORE more domain resources

These are easier to fix now, while no real data depends on them, than
mid-cutover.

1. ✅ **`invoices.header_discount` → `invoices.header_discount_percent`**
   (decimal(5,2)) — landed in PR for the schema-fixes branch. The
   legacy column INVOICE.DISCOUNT was declared as `CURRENCY
   (DECIMAL(14,2))` but every code path treats it as a percent 0-100;
   the original new-schema migration carried the misleading type
   forward. Now correctly typed; ETL writes the renamed column.

2. ~~`return_invoice_extras` synthetic PK~~ — turned out to be
   already-fixed. The legacy table has no PK (just `QTY_GIVEN`), but
   our `create_return_invoice_extras_table` migration adds `$t->id();`
   so we were already ahead of this item. The note was based on the
   LEGACY DDL dump, not our migration.

3. ✅ **`mydata_marks.mark_time` TIMESTAMP → TIME** — landed in the
   same PR. Legacy MARK.TIME is TIME-only; new column type now
   matches, so the first production `.fbk` with real MARK rows won't
   throw "Incorrect datetime value" on STRICT_TRANS_TABLES.

4. **AFM default-from-CUST_ID** — legacy trigger sets `CUSTOMER.AFM =
   CAST(CUST_ID AS VARCHAR)` if null on insert. Our new schema allows
   null AFM with no default. Real customers often have AFM, but cash
   customers don't. **Recommendation**: keep null nullable; let the
   IssueInvoice action validate `afm IS NOT NULL` per the AADE rule
   that B2B invoices need it. Don't replicate the AFM=CUST_ID hack
   (it's nonsense data that AADE rejects anyway).

### Workflows we hadn't planned for

Surfaced by re-reading the forms:

- **`FAutoInvoice` has FOUR sub-workflows**, not one. The form is a
  scheduler that drains four kinds of pending work:
  1. **myDATA submission queue** — invoices where `MYDATA_SENT = 0`.
     Covered by the planned `App\Services\MyDataSubmitter` + queue
     worker.
  2. **WHMCS pull** (currently labeled "3rd-invoices") — pulls invoices
     from upstream billing system, creates ekdosi invoice + sends to
     myDATA. Covered by the planned WHMCS bridge.
  3. **"Assigned invoices" (status = -333)** — purpose unclear; appears
     to be a manual-routing flag set by operators. **Open question**:
     does myip actually use this? Need to grep `INVOICE` table for
     `status = -333` rows in the production `.fbk` to know.
  4. **"Griniaris" workflow** — Greek for "fast/quick"; appears to be a
     simplified bulk-issue flow. **Open question**: also unclear if
     myip uses it. Check `.fbk` content.

- **Cumulative invoice ("ΣΔΕΠ") handling in FAddInvoice** — when a
  ΣΔΕΠ exists for the running date, new invoices get attached to it
  via `CONV_INVOICE_ID`. This is the "delivery note → invoice"
  conversion path. We have the column but no code that uses it.

- **Reserve check (FAddInvoice.cpp:298)** — before issuing, the form
  checks `RegAccess->getAppParameterInt("Reserve")` to decide whether
  the cumulative invoice's stock-reserve takes precedence over the
  current invoice's. Translates to a per-tenant `conf_params`
  setting in the new app.

- **Gross-edit path on invoice lines** — operator can type the gross
  unit price; form back-computes net via
  `PRICE_PER_ITEM = PRICE_PER_ITEM_WVAT / (1 + VATPERCENT/100)`.
  Filament's invoice form needs both inputs with mutual back-fill.

### Tables not migrated (status confirmed)

Cross-checked the legacy tables against migrations:

- ✅ **Mapped + ETL**: all 12 entity tables, MARK, CONF_PARAMS,
  AUTO_INVOICE_LOG (with table-exists guard for older `.fbk` snapshots)
- 🚫 **Deliberately dropped**:
  - `CUSTCS_LINK` (CS-Cart bridge — fully out of scope)
  - `CUSTOMER_CS_ACCEPTED` (CS-Cart staging)
  - `REPORTS` + `REPORT_INPUT_DATA` (FastReport 3 templates; rebuild
    as Blade → PDF)
  - `EAFDSS_SCRIPT` (pre-myDATA receipt signing)
- ⚠️ **Unaccounted for**: `VARTEXT` — appears in schema with a generator
  but no triggers and isn't referenced by any C++Builder source the
  agent could find. Likely dead. **Action**: confirm zero rows in
  myip's `.fbk`; if zero, drop without porting.

### ETL hygiene re-check

The current ETL is actually in good shape after the `fld()` refactor.
Re-confirmed:

- ✅ Every string column read goes through `$this->fld($r, 'COL')`
  which tolerates missing columns in older `.fbk` snapshots.
- ✅ FK remapping via `$this->legacyId('table', $legacyId)` consistently.
- ✅ `fbTableExists()` guards around MARK / CONF_PARAMS /
  AUTO_INVOICE_LOG so pre-myDATA / pre-WHMCS-bridge `.fbk`s import
  cleanly.

Remaining concerns from the original code review (still latent):

- WHMCS `CS_INVID` mapped to `legacyId('invoices', ...)` — wrong by
  definition; CS_INVID is WHMCS's id, not ekdosi's INVOICE_ID. Fixes
  when WHMCS bridge work begins.
- `clean()` uses `iconv //IGNORE` on MARK.REQUEST/RESPONSE XML —
  could silently drop bytes from the legal-audit XML. Fix by routing
  MARK XML fields around `clean()` (passthrough).
- `mark_time` schema is `timestamp` but ETL feeds Firebird TIME — fires
  on the first `.fbk` that actually has MARK rows. Schema-side fix:
  change `mark_time` to `time` type, or merge mark_date + mark_time
  into one `datetime`.

### Re-prioritised roadmap (after this scan)

Updating the order in light of what we learned:

1. **UserResource + ProfileResource** — biggest UX gap right now;
   admin can't add operators without tinker. Next PR.
2. ✅ **Schema-fix PR** — rename
   `invoices.header_discount` → `invoices.header_discount_percent`
   (decimal(5,2)) + change `mydata_marks.mark_time` to TIME. Landed
   on the `claude/schema-fixes` branch. The third item (PK on
   `return_invoice_extras`) was already in our migration.
3. **CustomerResource** — smallest end-to-end slice with real data.
4. **Invoice numbering port** — the `IssueInvoice` action stub with
   `lockForUpdate()` counter increment. Doesn't need a UI yet; just
   the service class + a unit test that hammers it concurrently.
5. **ProductResource**.
6. **InvoiceResource (read-only first)** — list + view. No issue
   action yet.
7. **`App\Services\MyDataSubmitter`** + **InvoiceVatBreakdown** value
   object — port `CALCULATE_VAT_FOR_INVOICE` semantics. Wired but
   not callable from UI yet.
8. **IssueInvoice action** in InvoiceResource — combines #4, #6, #7.
   First real end-to-end myDATA submission. Button shape (locked in
   after operator discussion):
     - Form ALWAYS shows a **"Save"** button → invoice persisted as
       draft, no AADE call, regardless of mode.
     - Form ALSO shows a **"Save and Submit to myDATA"** button when
       `mydata_mode != Off` (sandbox or production). One click =
       persist + submit + receive MARK + mirror columns updated.
     - For `mydata_mode = Off` tenants, ONLY "Save" is rendered —
       the submit button would route to NullSubmitter and confuse
       operators.
     - On the view page (post-save), drafts get a separate "Submit
       to myDATA" action button so an operator who chose Save-only
       can submit later after reviewing the PDF.
     - VALID invoices get a "Cancel via myDATA" action; CANCELLED
       are display-only.
   This shape gives the operator THREE paths:
     - Save → review PDF → Submit (safe + slow)
     - Save and Submit (fast + confident)
     - Off-mode: just Save (PDF only, no AADE at all — bridge
       testing / training tenant / breakglass)
9. **WHMCS bridge** — pull job + bridge of WHMCS invoices through the
   same IssueInvoice action.
10. **PEPPOL submitter** — for the Estonian tenant.

### Still-open questions for the operator

- ~~Does myip use the **"assigned invoices" (status=-333)** workflow?~~
  → **Defer**. Operator doesn't recall the rule offhand; not blocking
  current work. Deep-dive when we reach the invoice workflow PR
  (roadmap step 4/6/8) and grep `FAutoInvoice.cpp` for `-333` literals.
- ~~Does myip use the **"griniaris"** bulk-issue workflow?~~
  → **Resolved**. "Γκρινιάρης" (Greek for "grumpy") is an
  **immediate-invoicing** flag on the customer, NOT a bulk-issue
  workflow as the form's wording suggested. It's a checkbox on the
  WHMCS client profile that means "this customer wants their invoice
  the moment they pay, don't wait for the weekly batch". Implications:
  - The new `customers` table needs a boolean column for this
    (suggested: `needs_immediate_invoice`, default `false`).
  - The WHMCS bridge job syncs this flag from WHMCS's
    `clients.<custom-field-for-grumpy>` into `customers.needs_immediate_invoice`.
  - The IssueInvoice scheduled command (replacement of FAutoInvoice)
    checks the flag — `true` → issue + send to myDATA on the same
    tick as the payment row landed; `false` → roll into the next
    weekly batch.
  - Schema TODO: add the column in the same migration as the WHMCS
    bridge work, NOT now (no consumer for it yet).
- ~~Are there any **`VARTEXT`** rows in the production `.fbk`?~~
  → **Resolved for ETL**. The ETL doesn't touch VARTEXT at all —
  it's not referenced in `MigrateFromFirebird.php`. So dropping the
  table from the new schema is safe regardless of legacy content;
  re-running migrate:firebird against any `.fbk` (with or without
  VARTEXT rows) won't fail. If a future workflow turns out to need
  the data, we'd discover that gap during feature work, not at
  cutover.
- What's the per-tenant **AADE user ID + subscription key** for myip
  and nixpal (we need these to actually test myDATA submission once
  the submitter is built)?

### Deferred code-review findings (do not lose track)

Items surfaced by `/ultrareview --effort high` on prior PRs that were
**deliberately deferred** rather than fixed in their original PR.
Re-check each one when the listed trigger PR lands.

**From PR #16 (Customer resource) — surfaced while reviewing schema FK
shapes around the resource:**
- **DB-level cross-tenant self-FK guard** — `invoice_types` /
  `payment_methods` etc. allow self-FK rows (e.g.
  `invoice_types.payment_method_id`) where the parent is in a different
  tenant. Eloquent global scopes prevent it at app level, raw SQL or a
  misbehaving import does not. **Trigger PR**: any future ETL extension
  or raw-import path. Fix shape: composite FK `(payment_method_id,
  company_id) → payment_methods(id, company_id)`, requires composite
  unique on the parent side.
- **Self-FK + cascade on company delete** — deleting a Company cascades
  payment_methods, but invoice_types self-references payment_method_id
  WITHOUT `ON DELETE SET NULL`, so the cascade order can fail. **Trigger
  PR**: when we add a real "delete tenant" admin flow (not soon — currently
  unreachable from UI).
- **PaymentMethod has no global tenant scope** — relies on Filament's
  `BelongsToTenant`. Code that touches PaymentMethod outside a Filament
  request (artisan, queue jobs, the WHMCS bridge) sees all tenants.
  **Trigger PR**: WHMCS bridge / scheduled IssueInvoice command. Fix:
  add `BelongsToCompany` global scope to the model.
- **`payment_method_id` Select doesn't preserve current value on edit
  if FK now points at a soft-deleted row** — operator would silently
  lose the link. **Trigger PR**: when we add invoice editing UI that
  also exposes payment_method_id (currently only InvoiceType resource
  uses it).
- **`is_active=true` default filter on PaymentMethod table might hide
  rows from a future invoice picker** — non-issue today (no picker
  exists), latent. **Trigger PR**: InvoiceResource step in the
  roadmap.
- **`recordTitleAttribute = 'name'` ambiguous for duplicates** —
  Filament global search shows multiple rows with identical labels.
  Cosmetic. **Trigger PR**: whenever global search starts being used in
  anger.

**From PR #17 (InvoiceNumberer service):**
- **PHPUnit suite can't catch a dropped `lockForUpdate()`** — sqlite
  ignores row locks, so a regression that removes the lock passes the
  Feature test. The artisan concurrent hammer covers it but only when
  the operator remembers to run it. **Trigger PR**: when we set up CI
  against a real MariaDB, add a meta-test that asserts the SQL string
  for the SELECT contains `FOR UPDATE`, OR run the concurrent command
  as a CI step.
- **Concurrent probe doesn't test rollback semantics** — current probe
  only verifies a successful 1..N allocation. It doesn't verify that
  a thrown exception INSIDE the transaction rolls back the counter
  bump (invariant A in the InvoiceNumberer docblock). **Trigger PR**:
  IssueInvoice action — once we have a failure mode (myDATA reject,
  VAT calc throw), add a "throw mid-allocate; assert invcount is
  unchanged" test.

---

## Audit findings — 2026-05-26 re-audit

A multi-agent re-scan of legacy vs. current code (schema gaps,
business-logic gaps, ETL + Filament resource coverage) surfaced this
list. Items already covered by earlier sections of this file are not
repeated. Each item below is either fixed in PR (a) (this PR), tied to
a future trigger PR, or queued for the operator to confirm against
production data before cutover.

### Fixed in this PR (claude/etl-hardening)
- **Multi-default VAT at import time**: `VAT_CATEGORY_AU0` is a Firebird
  AFTER UPDATE trigger that demotes every other VAT row when one
  becomes default (legacy schema:1103). The trigger never fired on
  INSERT, so legacy production data CAN contain >1 default per
  company. We have `is_default` as a plain boolean with no MariaDB-side
  enforcement. `MigrateFromFirebird::demoteDuplicateVatDefaults()` now
  fixes this at import time (keeps lowest surrogate id, warns the
  operator). The full enforcement — a model observer — ships with the
  VatCategory model in the lookup-resources PR.

### False alarms from the audit (recorded so we don't re-litigate)
- **"ETL doesn't seed AUTO_INCREMENT from MAX(legacy_id)"** — not a
  bug. The ETL uses `insertGetId()` everywhere (never explicit-id
  inserts) and `wipeCompany()` uses `DELETE` not `TRUNCATE`. MariaDB
  tracks the high-water mark on its own through both paths (verified
  experimentally on the dev box). Re-runs and Filament-created rows
  cannot collide on PK.

### Deferred to the lookup-resources PR (claude/lookup-resources)
- **`App\Models\VatCategory` + observer enforcing single `is_default`**
  per company. The ETL guard above is a one-shot import-time fix; the
  observer covers the steady-state UI path. Same applies to
  `App\Models\MetricUnit`, `App\Models\ProductCategory`,
  `App\Models\DeliveryMethod`, `App\Models\DistributionAim` — all
  needed as models before their respective Filament resources can
  render.
- **Filament resources for the seven lookup tables** that are currently
  unreachable from the panel: `payment_methods`, `delivery_methods`,
  `distribution_aims`, `metric_units`, `vat_categories`,
  `product_categories`, `invoice_types`. Without these, the
  ProductResource (and later InvoiceResource) form pickers point at
  models the operator has no way to populate. The seven resources are
  intentionally small (1-3 column tables for most) — one PR can land
  them all.

### Deferred — needs operator decision before cutover
- **`INVOICE.NOTES_OLD` (BLOB, legacy schema:159)** — silently dropped
  on ETL. Distinct from `NOTES`. Confirm against production myip
  `.fbk` whether any rows have non-null `NOTES_OLD`:
  ```sql
  -- in isql against the restored myip .fdb:
  SELECT COUNT(*) FROM INVOICE WHERE NOTES_OLD IS NOT NULL;
  ```
  If non-zero, add `invoices.notes_old TEXT` migration + ETL mapping
  before the final cutover run. If zero across all tenants, the drop
  is safe.
- **`INVTYPE.FRM_FILENAME` / `PRINTER_NAME` / `PRINTER_NO`**
  (schema:184-189) — silently dropped. FRM_FILENAME points at a
  FastReport 3 template path (we're replacing FR3 entirely with Blade
  → PDF, so the value is meaningless going forward), and the two
  PRINTER_* columns are Windows printer device names from the legacy
  desktop client. Confirm with operator: does any tenant rely on
  per-invoice-type printer routing in the new app? Probably not (new
  flow is "render PDF, email or download"), but check before cutover:
  ```sql
  SELECT INVTYPE_ID, FRM_FILENAME, PRINTER_NAME, PRINTER_NO
    FROM INVTYPE
   WHERE COALESCE(FRM_FILENAME, PRINTER_NAME) IS NOT NULL
      OR PRINTER_NO IS NOT NULL;
  ```
  If non-empty and the operator wants the routing preserved, add a
  per-type `pdf_template` / `default_print_target` columns (new
  semantics, not 1:1 legacy carryover).

### Deferred from PR #19 (lookup resources) code review
- **Soft-deleted referenced rows render blank in Filament Selects** — affects InvoiceTypeForm's `payment_method_id` / `delivery_method_id` / `distribution_aim_id` / `default_customer_id` AND CustomerForm's `payment_method_id` / `referred_by_customer_id`. The `pluck()` and `getOptionLabelUsing()` patterns don't include trashed rows, so once a lookup row is soft-deleted the dependent Select displays empty even though the FK still points at the row (the nullOnDelete only triggers on hard delete). On save, an unresolved Select can silently submit null. **Trigger PR**: application-wide form-pattern fix — single PR that updates every Select pulling from a SoftDeletes model to use `withTrashed()` for the label lookup, AND adds a "deleted" badge to the option text. Don't fix piecemeal; do it once for the whole panel.
- **`Rule::unique(...)->where('company_id', Filament::getTenant()?->getKey())` degrades to `WHERE company_id IS NULL` outside panel context** — affects InvoiceTypeForm (introduced PR #19) and CustomerForm (introduced earlier). If a queue job or artisan command revalidates a model with these rules and the tenant facade isn't bound, duplicates within a real tenant pass validation. Hypothetical for now (no such caller exists), becomes real with WHMCS bridge. **Trigger PR**: WHMCS bridge job. Fix shape: a `TenantScopedUnique` rule helper that throws explicitly when tenant context is missing, instead of silently degrading.
- **Soft-delete + reuse-same-code on `invoice_types` is technically blocked by the DB unique** — refuted as a real bug in review (soft-deleting an invoice type isn't a realistic workflow given invcount + MARK history), but noted here so the next time this constraint comes up we don't re-litigate.

### Deferred from PR #20 (ProductResource)
- **ETL doesn't preserve Filament-only product columns across re-runs** — same gap that `snapshotManualEdits()` solves for customers, but products now have five Filament-only columns of their own (`is_active`, `internal_notes`, `sku`, `whmcs_product_id`, `supplier`). The gap only bites once all three are true: ProductResource has been live, operators have edited those columns, and someone re-runs `migrate:firebird` against the same tenant. **Trigger PR**: first time someone re-runs the ETL with manual product edits in place (or proactively, alongside the next ETL touch). Fix shape: mirror the `manualCustomerEdits` snapshot/restore around `copyProducts()`.
- **`markup` is a Filament-only live-compute field** — sourced from `product_categories.markup` on category-change. NOT persisted on `products`. Matches legacy (Windows Registry app setting). Documented in ProductForm.php docblock + Product.php docblock so a future maintainer doesn't try to "fix" it by adding a column.
- **Price tiers are reference data only, not auto-applied** — at parity with legacy (FAddInvoice.cpp:188-197 reads PRODUCT.SELL_PRICE directly, ignores PROD_PRICE_QTY). **Trigger PR**: IssueInvoice action. Decide then whether to surface tiers as a hint in the line form, or actively suggest the tier price. Don't silently auto-apply (would change behaviour from legacy).
- **Soft-deleted product with same `sku` or `barcode` blocks re-create at DB level** — same shape as the tracked `invoice_types` case. Form-level `Rule::unique` carves out `whereNull('deleted_at')`, but the migration's DB unique on `(company_id, sku)` / `(company_id, barcode)` doesn't, so submission passes validation then crashes on duplicate-key. Workaround: use the new `is_active` toggle to hide instead of soft-delete (preserves SKU). Force-delete required to genuinely reuse a soft-deleted SKU. Acceptable for the same reason as InvoiceType — true SKU recycling is not a real workflow.
- **`Rule::unique(...)->where('company_id', Filament::getTenant()?->getKey())` degrades to `WHERE company_id IS NULL` outside panel context** — ProductForm now has TWO new instances (sku, barcode). Same tracked pattern as CustomerForm + InvoiceTypeForm. **Trigger PR**: WHMCS bridge (first non-panel caller of the validator).
- **PriceTier `company_id` is stamped from `Filament::getTenant()?->getKey()` with no null guard** — if invoked outside panel context, insert fails on the NOT NULL FK with a SQL error instead of a friendly validation message. Failure is loud, not silent. Defer until/unless a non-panel caller appears.
- **`VatCategory::is_default=true` row could be soft-deleted** — leaves the ProductForm with no VAT default, every new product save fails the `required()` rule. Observer (from PR #19) enforces single-default but doesn't enforce default-not-trashed. **Trigger PR**: next VatCategory touch — add a `restored`/`deleting` observer hook.
- **`Product` model has no global tenant scope** — same deferred item from PR #19 for PaymentMethod et al. WHMCS-pull or scheduled IssueInvoice job outside panel context can see all tenants' products. **Trigger PR**: WHMCS bridge.

### Fixed during PR #20 code review (locked in by tests)
- **Category-change no longer cascades a sell_price recompute** — ProductForm's category `afterStateUpdated` updates only `markup_display`. Cascading would silently clobber a hand-tuned sell_price when operators reclassify a product. To apply the new markup, operator explicitly re-types buy_price or markup_display.
- **EditProduct hydrates `markup_display` from the historical (buy, sell) pair**, not from the category's current markup. Preserves the price relationship across category-markup edits.
- **PriceTier requires at least one of `value` / `discount_percent`** via `requiredWithout` — helperText no longer lies.
- **`(product_id, qty)` is unique on `product_price_tiers`** (new migration `_000006`) so the future IssueInvoice tier-hint lookup is deterministic.

### Deferred from PR #22 (AADE registry lookup) — second-sweep review
- **Cache invalidation on credential rotation** — When `companies.gsis_username` or `gsis_password` changes, the 24h cache continues serving lookups fetched under the old credentials. Today's mitigation: the `test_gsis` action calls `Cache::forget` for the AFM being tested. **Trigger PR**: when the audit story matters (e.g. when activitylog wraps the AADE lookups). Fix shape: model observer on `Company::saving()` that detects `isDirty('gsis_*')` and either (a) increments a `gsis_cache_version` column included in the cache key, or (b) flushes via `Cache::tags(['aade-tenant-'.$company->id])` if Redis/Memcached is wired up. Option (a) is broader-compatible.
- **Multi-tenant boundary assertion in CustomerForm fetch action** — The action resolves `$tenant = Filament::getTenant()` without asserting the edited customer belongs to that tenant. Not exploitable today (Filament middleware enforces it at the route layer), becomes a concern when a non-Filament caller (queue job, future API) invokes the same code path. **Trigger PR**: WHMCS bridge or any non-panel customer flow. Fix shape: assert `$customer->company_id === $tenant->id` before the service call, or derive the tenant from `$record->company` instead of the facade.
- **`text` → `binary` collation on encrypted credential columns** — `companies.gsis_password` and `companies.mydata_subscription_key` are declared `text` and inherit MariaDB's `utf8mb4_unicode_ci` default. Ciphertext is opaque bytes; a UTF-8 collation could in theory normalise something during a dump/restore through a misconfigured tool. Not currently exploited (Laravel's `encrypted` cast round-trips fine through MariaDB native), defensive at best. **Trigger PR**: next time we touch encrypted columns. Fix shape: `$t->binary(...)` or `$t->text(...)->collation('utf8mb4_bin')` migration.
- **Filament closure-binding fragility on form actions** — The form `Action::action(function (callable $get, callable $set, ?\App\Models\Company $record) { ... })` signatures rely on Filament 5's parameter-name + type resolution. Stable today; if Filament's resolution heuristic changes in a minor (e.g. they introduce a `Record` interface or split create vs edit contexts), the `$record` injection could regress silently. **Trigger PR**: next Filament minor upgrade. Fix shape: switch to `$livewire->getRecord()` resolution; less magic, more explicit.
- **ext-soap CI gap** — Tests gated on `extension_loaded('soap')` skip cleanly when the sandbox PHP lacks the extension. Production `composer.json` declares `ext-soap: "*"` as a hard requirement so deploys fail without it, but if a future CI image silently omits ext-soap (Alpine variants do this), CI would green-check while production breaks. **Trigger PR**: when CI / Docker image gets formalised. Fix shape: a meta-test that asserts every composer-declared extension is actually loaded.

### Deferred from PR #24 (myDATA submitter foundation) — second-sweep review
- **Real confirm modal for production-mode transitions on Company save** — currently the form's mode Select has a helperText warning about the safety implications, but there's no Filament confirm modal blocking the save when `mydata_mode` transitions involve Production. The previous attempt (persistent toast on `afterStateUpdated`) was misleading (fired on every form-state change, including immediate undos, training operators to ignore the warnings). Right shape: override the EditCompany page's save action with `requiresConfirmation()` gated on `$record->isDirty('mydata_mode')` and a transition that touches Production. Defer because it requires custom page logic, not just form-schema config. **Trigger PR**: first time a tenant accidentally goes Live (or proactively when a second operator account exists).
- **NullSubmitter's SKIPPED rows show as "pending" in InvoiceInfolist** — the Infolist reads `$record->mydata_state` (the cache column), which NullSubmitter intentionally leaves null. Operators on Off-mode tenants see "pending" badges on every invoice, indistinguishable from "we haven't tried to file yet". Fix shape: the Infolist's myDATA section reads `$record->latestMydataMark?->mydata_action` and shows "Skipped (not filed)" as a distinct gray badge when the latest mark is SKIPPED. PR #25 reworks this column logic anyway when the real submitter wires up; bundle the fix then.
- **CompaniesTable badge color for Production = `danger` (red)** — alarming-by-default on an all-Greek tenant dashboard. Reserves `danger` for genuine fault states. Switch to `success` (green) for production, `warning` (yellow) for sandbox, `gray` for off. Cosmetic, defer.
- **English-only Select labels and badge text** — `MyDataMode::label()` and the badge `formatStateUsing` arms hard-code English. Will need i18n when the Greek locale fully ships. Tracked in CLAUDE.md's broader i18n plan; not blocking.
- **Test naming hardcodes "_pr24"** — `test_factory_returns_null_submitter_for_sandbox_in_pr24` becomes stale documentation the moment PR #25 lands and flips the expectation. Rename in PR #25 to something stable, OR auto-skip via `markTestSkipped` if `class_exists(MyDataSubmitter::class)`.
- **EE / none tenants don't see the mode Select** — the myDATA submission tab is hidden when `einvoice_provider != 'gr-mydata'`, so an Estonian or PDF-only tenant has no UI to change its `mydata_mode` (defaulted to 'off' by the migration, which is correct). If they ever DO want sandbox testing without flipping provider, they can't. Edge case — defer until a real workflow needs it.

### Deferred from PR #25 (real MyDataSubmitter)
- **firebed library uses STATIC state for credentials** — `MyDataRequest::init()` sets `self::$user_id` / `self::$subscription_key`. Safe for FPM/Apache (one request per process) and for sequential queue workers. Becomes a contention point under Octane / Roadrunner / parallel queue runners where the same PHP process handles multiple tenants concurrently. **Trigger PR**: if/when we move to Octane. Fix shape: either wrap MyDataRequest in a request-scoped binding that re-initialises on every operation (we already do this defensively, but the firebed lib still uses globals internally), or contribute a non-static credential API upstream.
- **MyDataSubmitter dry-run UI is on the View page only** — operators can preview a draft invoice's XML, but on the create/edit flow (PR #26 IssueInvoice) they'll want a preview button on the form itself before clicking the real "Save and Submit". **Trigger PR**: PR #26. Fix shape: a "Preview XML" button alongside Save / Save and Submit that opens a modal with the would-be XML.
- **myDATA Console page (RequestDocs + RequestTransmittedDocs reconciliation)** — operator-facing dashboard for what AADE has vs what we've filed locally, plus invoices filed AGAINST us (expense side, VAT recovery). Substantial feature; deferred to PR #26b or later. **Trigger PR**: when operators ask for AADE-side reconciliation OR when the parallel-run cutover gate needs golden-test comparison.
- **VAT category rate → AADE VatCategory enum mapping is hardcoded** — `MyDataSubmitter::vatCategoryFor()` maps known rates (24/13/6/17/9/4/0%) to AADE's 1..7 enum. If AADE adds a new rate or category, we throw. **Trigger PR**: when AADE publishes a spec update OR when a Greek operator needs an island-rate (17/9/4%) we haven't validated against. Fix shape: store the enum value alongside the rate on `vat_categories` (new column) so it's operator-configurable per tenant.
- **MyDataSubmitter test coverage stops at the factory + dry-run path** — the actual SendInvoices Guzzle-level integration test isn't written (firebed MockHandler setup is non-trivial for the dry-run case we already cover). **Trigger PR**: when a regression in the submitter is caught in production. Fix shape: write the mock-Guzzle integration test then, with the actual fault as the regression test.
- **`mydata_type` snapshot column is filled only by MyDataSubmitter on successful submit** — ETL-imported invoices don't get it (no submitter ran). For ETL data the field is correctly null, which the read-only InvoiceResource handles. **Trigger PR**: if/when we want to backfill mydata_type from `invoice_type.mydata_type` for historical invoices. Fix shape: a one-shot artisan command, not the regular ETL path.

### Deferred from PR #25 (real MyDataSubmitter) — second-sweep review (validation + fresh-eyes)
- **VAT exemption category mechanism** — 0% VAT lines currently THROW rather than file (the submitter's `vatCategoryFor()` refuses 0% explicitly). Real-world 0% lines need a `vatExemptionCategory` field (intra-community supply vs domestic exempt vs reverse-charge vs out-of-scope). **Trigger PR**: first time an operator hits the 0% throw (which they'll see for any VIES intra-community sale). Fix shape: add a `vat_exemption_category` field on `vat_categories` (operator-configurable per rate) + an override on InvoiceLine for the per-line case + a heuristic when invoice type ∈ {1.2, 2.2} (intra-community) auto-suggests the right category.
- **Orphan MARK recovery for AADE-success / local-DB-fail edge case** — `persistResponse()` runs the AADE call OUTSIDE the DB transaction and the local writes INSIDE. UID idempotency (now added) makes a retry safe (AADE returns the same MARK), but a `failed_mydata_writes` table + an artisan reconciliation command would close the gap fully. Currently: structured log entry + UID dedup is the safety net. **Trigger PR**: if/when a real production deploy hits this. Fix shape: a small staging table written BEFORE the AADE call, updated on success, surfaced via a Filament page that lets an operator reconcile against `RequestTransmittedDocs`.
- **`mydata_marks.request/response` column size** — declared `mediumText` in the original migration (16MB ceiling), which is plenty. But large cumulative invoices (200+ lines) generate ~70KB XMLs and the audit-modal renders them via a Filament Textarea default value (Livewire payload weight). Currently fine; revisit if/when ΣΔΕΠ aggregations land.
- **Reflection in `MyDataTestSubmit --print-only`** — uses `ReflectionMethod::setAccessible(true)` to call private `buildAadeInvoice`/`payloadToXml`. Brittle to renames. **Trigger PR**: next time those methods are refactored. Fix shape: promote them to public (they're pure builders, not security-sensitive) OR expose via a dedicated `MyDataSubmitter::previewXmlString(Invoice): string` returning just the XML string without persisting.
- **Filament Submit button** (when PR #26's IssueInvoice action lands) should call `->disabled(fn ($record) => $record->mydata_state === 'VALID')` as defense-in-depth on top of the server-side guard the submitter now enforces. Belongs in PR #26 since the Submit button doesn't exist yet.
- **Per-tenant branch_id for issuer** — currently hardcoded to 0 in `MyDataSubmitter::buildAadeInvoice`. For multi-branch tenants (none currently — myip, nixpal both single-branch) this would need to come from tenant config. **Trigger PR**: first multi-branch tenant.

### Deferred from PR #25 (real MyDataSubmitter) — third-sweep INDEPENDENT review (the consequential one)
The third blind review found 2 CRITICAL bugs that both prior agent reviews missed (they trusted my code; only the third reviewer actually grepped vendor source to verify firebed method existence). All fixed in the same commit:
- `ResponseDoc::getResponses()` doesn't exist — must use `->first()` or iterate
- `(string) $response` fails (no `__toString`) — must use `$action->getResponseXML()` from the HasResponseDom trait on the action instance
- `RequestTransmittedDocs` dates need `d/m/Y` format, not `Y-m-d`
- Non-GR Counterpart requires `name` + `address`; country must be ISO-3166-1 alpha-2
- `(int)` cast on MARK before CancelInvoice would truncate 15+ digit values on 32-bit hosts

**Lesson for future PRs**: when wrapping a third-party library, the agent reviews tend to trust that my method-call names are correct. The blind reviewer is the one who actually verifies API existence against vendor source. Worth running an independent third sweep on every PR that depends on a non-trivial external library.

Items still deferred from this third pass:
- **Mock-Guzzle integration test for the SendInvoices end-to-end path** — would have caught the `getResponses()` / `__toString` bugs at test time. Setting up firebed's MockHandler for a real-shape AADE success response is non-trivial; defer until the first real submission proves the path works, then capture the payload and write the test from it.
- **`(invoice_id, mark, mydata_action='INSERT')` unique constraint** — current `persistResponse()` has an in-memory idempotency check (looks for existing row before INSERT) but a DB-level unique would close the race window between two concurrent retries. **Trigger PR**: when activitylog wraps `mydata_marks` and duplicate rows become more user-visible.
- **`normaliseCountryCode()` country list** — seeded with GR / EE / CY / DE (current tenant scope). Extend the match arms as new tenant/customer countries appear. Operators see a clear error pointing at the helper if an unrecognised country shows up.
- **vatExemptionCategory mechanism for 0% lines** — still throws (the right safe default). When intra-community customers need filing, add a `vat_exemption_category` column on `vat_categories` + per-line override + heuristic for invoice type ∈ {1.2, 2.2} → auto-suggest the right category. **Trigger PR**: first time an operator hits the 0% throw.

### Deferred — application-wide patterns
- **FK-aware delete guards (`GuardedDeleteAction`)** — operators currently hit one of two confusing modes when deleting a row that has dependents: (a) the default soft-delete succeeds silently and the dependent invoice / line / customer ends up referencing a trashed lookup row that's now invisible in the panel; (b) ForceDelete crashes with a cryptic SQL error from `restrictOnDelete`. Proposed shape: a reusable `GuardedDeleteAction` (extends Filament's DeleteAction) that counts referencing rows on `->before()`, blocks with a friendly notification listing exactly what depends on the row, and offers "Deactivate" (set `is_active=false`) where the model supports it. Complementary `BeforeDeleteObserver` enforces the same check from artisan/queue/API paths. **Trigger PR**: after InvoiceResource lands — that's when the full reference graph is real (invoices touch every lookup we have). Applies across Product, ProductCategory, VatCategory, MetricUnit, PaymentMethod, DeliveryMethod, DistributionAim, InvoiceType, Customer.

### Deferred — tied to specific future PRs
- **PDF generation on issue + auto-mail with audit-BCC** — legacy
  `FAutoInvoice.cpp:655` generates a PDF on every successful myDATA
  submission via the FR3 print harness; `FMailInvoices.cpp:106` then
  emails the PDF to the customer and BCCs `invoice@myip.gr` for the
  audit trail. Roadmap step 8 (IssueInvoice action) implicitly needs
  to call out to a PDF renderer + queue a mail job; the BCC target is
  a per-tenant setting on `companies` (call it `invoice_audit_bcc`,
  add when the column is needed). **Trigger PR**: IssueInvoice action.
- **0-100 range validation on discount fields** — legacy `FaddCustomer.cpp:156`
  enforces it at the form. Without it, an unbounded value cascades
  into negative VAT in the totals math. **Trigger PR**: when
  InvoiceResource lands (and a quick add to the existing CustomerForm
  if we touch it anyway).
- **AFM-already-exists soft warning on customer save** — legacy
  `FaddCustomer.cpp:79` pops a confirmation when the typed AFM matches
  an existing customer (allows save on confirm). Filament currently
  silently allows duplicates. **Trigger PR**: next time CustomerForm
  is open for edits.
- **VAT_VIES validation against AADE** — legacy shows a grey/red icon
  next to the AFM input as the operator types, via a real-time VAT-id
  check. Forward-looking nice-to-have (myDATA-specific). **Trigger
  PR**: post-IssueInvoice; a Filament rule + queued background check.
- **Per-tenant role assignment UI in UserResource** — Shield's teams
  mode handles the role-per-company at the data layer, but the
  current UserResource doesn't expose role pickers per tenant in the
  form. The `CompaniesRelationManager` shows tenant membership only.
  Not blocking until a second human operator exists who needs roles
  scoped per tenant. **Trigger PR**: when the second real operator
  account is added.
