# CLAUDE.md — ekdosi modernization

Working guide for this repo. Read this first.

> **Full history** (dated inspection notes, per-PR review logs, resolved
> deferred-findings) lives in **`docs/CLAUDE-history.md`** — not auto-loaded,
> consult it when you need the "why" behind a past decision.
> **AADE spec** is committed at the repo root:
> **`myDATA_API_Documentation_v2.0.0_preofficial_erp.md`** (§8 = code tables,
> §7.2 = the 101–280 business-error list).

## What this project is
Porting a legacy **C++Builder (VCL) + Firebird** invoicing app ("ekdosi") to
**Laravel 13 + FilamentPHP 5 + MariaDB**. A homegrown Greek τιμολογιέρα with
**myDATA**, **QR**, and a **WHMCS bridge**, operators-only (internal, no
customer portal), that compiled only on a fragile Windows 7 VM — escaping
that toolchain is the whole point.

Scope: customers, stock/products, services, invoices (παραστατικά),
payments, credit notes, myDATA submission + reconciliation + audit trail,
WHMCS bridge. The old C++Builder/Firebird app becomes a read-only archive
after cutover.

## Goal & end state
- Single multi-tenant MariaDB (`company_id` on every table), one Laravel
  codebase, one Filament panel with tenant switching.
- myDATA via **`firebed/aade-mydata`** (wrapped in `App\Services\MyDataSubmitter`);
  per-tenant credentials on `companies`. We don't re-port the lost legacy
  `CMyData.cpp` — semantic equivalence, not byte-match.
- WHMCS bridge re-implemented **PHP-to-PHP via the WHMCS API** (decision
  locked — not shared-DB), replacing the legacy `AUTO_INVOICE_LOG` polling +
  `FMysqlSync` push.
- **Multi-country from day one**: 3 tenants — 2 Greek (myDATA) + 1 Estonian.
  `companies.einvoice_provider` selects the submitter (`gr-mydata`,
  `ee-peppol`, `none`) behind a common issue flow. Estonian PEPPOL is a stub
  until their e-invoicing deadline forces it.

## Stack (locked)
- **PHP 8.4+**, **Laravel 13**, **MariaDB 11.x** (`utf8mb4_unicode_ci`).
- **FilamentPHP 5** as admin panel AND tenancy driver (Company = tenant; no
  separate multi-tenancy package).
- **myDATA**: `firebed/aade-mydata`.
- **Roles/permissions**: `spatie/laravel-permission` + `bezhanSalleh/filament-shield`
  (default roles per tenant: `admin`, `operator`, `accountant_readonly`).
- **PDF**: `barryvdh/laravel-dompdf`. **Backups**: `spatie/laravel-backup`.
- **Audit log**: `spatie/laravel-activitylog` (installed; not yet wired —
  see latent items).
- **Queue/scheduler**: Laravel built-in (DB driver). **No scheduler wired yet.**
- **Firebird driver on the ETL host**: `pdo_firebird` (only the artisan host
  needs it).

## Repo layout
```
/                         # Laravel 13 app at repo root
  app/Console/Commands/MigrateFromFirebird.php   # re-runnable ETL, one tenant per run
  app/                                           # models, Filament panels, services, actions
  database/migrations/                           # 48 migrations
  whmcs-plugin/ekdosi_bridge/                    # OUR WHMCS-side plugin (deployed to tenant's WHMCS)
  myDATA_API_Documentation_v2.0.0_preofficial_erp.md   # the AADE spec
  docs/CLAUDE-history.md                         # archived full project history
/legacy/                  # read-only reference (do NOT build)
  ekdosi-schema.sql                              # isql -x dump (WIN1253 DB; ASCII DDL is clean)
  ekdosi-main/                                   # C++Builder source — real VAT/rounding math
  ekdosi-main/db_backup/ekdosi.fbk               # gbak for sandboxed ETL dev
  ekdosi-main/reports/                           # FastReport 3 templates (out of scope → Blade PDF)
  whmcs/                                         # the 3 ARCHIVED legacy WHMCS plugins
```

## Architectural decisions (do not re-litigate without reason)
- **Multi-tenant, not per-DB.** Superset: can deploy per-DB later; reverse can't.
- **Surrogate PKs + `legacy_id`.** Legacy integer PKs collide across companies
  (CUST_ID=1 exists in myip AND nixpal). Fresh `id` everywhere; `legacy_id`
  (unique per company) kept for audit + re-runnable ETL. FKs rewired via
  `legacy_id → new_id` maps during import.
- **Numbering = continuous counter per invoice type** (`INVTYPE.INVCOUNT`,
  from the triggers — NOT per fiscal year). Copied as-is to
  `invoice_types.invcount`; the new app continues from there (no fiscal-boundary
  cutover). myDATA terms: `invoice_types.code` → series, `invoices.code` → ΑΑ.
  **Increment under `lockForUpdate()` in a transaction** (`InvoiceNumberer`).
- **`mydata_marks` is the source of truth** for myDATA (full request/response
  XML kept, legal audit). `invoices.mydata_*` columns are a denormalised cache
  of the latest state (replaces the legacy `MARK_AI0` trigger; written inside
  the same transaction as the mark row).
- **Two orthogonal statuses.** `invoices.local_status` (`draft`/`active`/
  `cancelled`, business intent) vs `mydata_state` (`null`/`VALID`/`CANCELLED`,
  the AADE truth). Reconciled, never conflated. `App\Support\InvoiceScope::live()`
  is the one predicate (not-cancelled + not-AADE-cancelled) used at all money sites.
- **Money is one service.** `App\Services\InvoiceBalance` is the single source
  for paid/credited/balance/status; cache columns (`paid_total`, `credited_total`,
  `payment_status`) are written ONLY by it (via `forceFill`, not fillable).
- **Charset:** legacy DB is **WIN1253**. ETL connects `charset=UTF8` (FB
  transliterates on read; UTF8 superset → no errors). Fallback: `charset=NONE`
  + `iconv('Windows-1253','UTF-8//IGNORE',...)`.
- Reserved words renamed; INVDATE+INVTIME → `invoices.issued_at`; Firebird
  domains → concrete decimals.

## Conventions
- Deliver **complete, ready-to-drop files**, not diffs.
- Simplicity over cleverness; no premature abstraction / over-engineering.
- snake_case tables (plural), `id` PK, `timestamps`, `softDeletes` where it
  makes sense. utf8mb4 / utf8mb4_unicode_ci.
- Money `decimal(14,2)`, qty `decimal(9,3)`, vat% `decimal(5,2)`.
- Operator-facing UI text is Greek; code identifiers stay English.

## Commands
```bash
php artisan migrate
php artisan shield:generate                              # (re)sync resource permissions after new resources

# ETL — one tenant per legacy DB, re-runnable (needs pdo_firebird on the artisan host)
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" --host=10.23.22.5 --fbuser=EKDOSI --fbpass=ekdosi1234
php artisan invoices:recompute-balances --company=myip   # backfill money cache after import

# myDATA ops (all MANUAL today — no scheduler wired)
php artisan mydata:preflight [--tenant=SLUG]             # READ-ONLY config audit vs AADE code tables
php artisan mydata:set-credentials --tenant=SLUG --test  # set sandbox creds (key = hidden prompt), verify
php artisan mydata:test-submit <invoiceId> [--execute]   # dry-run XML / --execute files to AADE
php artisan mydata:reconcile-sales --tenant=SLUG [--raw] # READ-ONLY local↔AADE cross-check / raw XML dump

# WHMCS
php artisan whmcs:fetch-pending --tenant=SLUG            # stage paid+unfiled WHMCS invoices into the inbox
```

## Deliberately dropped (verified absent in new code — do not resurrect)
- **CS-Cart bridge** (`FCSConnect`/`FManageCS*`, `CUSTCS_LINK`,
  `CUSTOMER_CS_ACCEPTED`, the cipher key) — never actually used.
- `EAFDSS_SCRIPT` (pre-myDATA ΕΑΦΔΣΣ), FastReport `.fr3` (→ Blade PDF).
- `FMysqlSync` MySQL mirror push (→ WHMCS API).
- `GET_COMB_*` procedures — cross-DB `EXECUTE STATEMENT` to a hardcoded
  `.fdb` with SYSDBA/masterkey inline. **Security landmine — never carry the
  creds over.**
- `afm2name` WHMCS-side GSIS plugin — ekdosi does GSIS natively
  (`AadeRegistryLookup`).

---

## The VAT / discount / rounding math (canonical — we port this exactly)
Lives in legacy `FAddInvoice.cpp::showSums()` (~line 269) + `FAddInvoice2.cpp::calcPrices()`.
```
# Per line:
PRICE     = QTY * PRICE_PER_ITEM
PRICE     = PRICE - PRICE * (DISCOUNT_line / 100)        # line-level discount
PRICEWVAT = PRICE * (1 + VATPERCENT / 100)

# Per invoice header (line sums + invoice-level DISCOUNT %):
priceWOutVat      = SUM(line.PRICE)
priceSumWVat      = SUM(line.PRICEWVAT)
invoice.PRICE     = priceWOutVat - priceWOutVat * (DISCOUNT_inv / 100)
invoice.PRICEWVAT = priceSumWVat - priceSumWVat * (DISCOUNT_inv / 100)
invoice.VATtotal  = invoice.PRICEWVAT - invoice.PRICE    # derived, not stored

# Gross-edit path (operator types a gross unit price):
PRICE_PER_ITEM = PRICE_PER_ITEM_WVAT / (1 + VATPERCENT / 100)   # NOT yet wired in the new form

# Withholding (FAddInvoice.cpp:819): WITHHOLD_AMOUNT = invoice.PRICE * 0.20  (flat 20%, ΠΚ-3)
```
**Rounding subtlety:** legacy used Borland `TCurrency` (4dp) in-form but
`DECIMAL(14,2)` columns — so values rounded to 2dp **on write**, not per
intermediate. In PHP: high-precision math, `round($x,2)` ONLY when assigning
to the model attribute, never between sub-sums. New home of this logic:
`App\Services\RecomputeInvoiceTotals` + `InvoiceVatBreakdown`. Golden-test
after import (README query); expect a few off-by-€0.01 invoice-discount rows.

**Key stored-proc semantics ported:**
- `GET_INV_CODE` = `INVTYPE.code || INVCOUNT` (e.g. `APY423`), no zero-pad.
- `CALCULATE_VAT_FOR_INVOICE` = per-VAT-rate breakdown WITH invoice-level
  discount applied → `InvoiceVatBreakdown` (feeds myDATA per-rate VAT).
- `GET_CUSTOMER_BALANCE` = only `payment_methods.due_days > 0` invoices count
  toward balance (cash-term settled at issue) → `InvoiceBalance` + Καρτέλα.

---

## myDATA (the core integration)

### Library decision
Use `firebed/aade-mydata` (transport + XML + types). We own only the mapping
from our `Invoice`/`InvoiceLine` → its payload. The legacy `CMyData.cpp` is
lost; we do NOT byte-match — semantic equivalence, validated field-by-field.
firebed uses **static credential state** (`MyDataRequest::init()`) — fine for
FPM + sequential queue workers; a contention point under Octane.

### SUBMIT payload shape (validated against AADE sandbox 2026-05-28)
`MyDataSubmitter::buildAadeInvoice` → `SendInvoices`. The proven-accepted shape
(grounded in an imported legacy MARK request; each rule tied to the AADE error
it clears):
- issuer = tenant AFM + `country=GR` + `branch=0` (multi-branch tenants would
  need the real branch — none today).
- counterpart **present** for B2B (1.1/2.1) with GR VAT and **NO name/address**
  (`[219]/[220]` forbid them for GR parties); **omitted** for retail (11.x).
  Foreign counterpart needs name+address+ISO country.
- `invoiceHeader`: series, aa, issueDate (`Y-m-d` in the request body), type,
  `currency=EUR`.
- per-line: `netValue`, `vatCategory`, `vatAmount`, income classification
  (`E3_*` + `categoryN_x`). **NO per-line `<quantity>`** — `[205]` forbids it
  for the service types we file (goods types DO require it → conditional, a
  follow-up).
- `invoiceSummary`: net, vat, the **five zero tax-total fields**
  (`totalWithheldAmount`/`Fees`/`StampDuty`/`OtherTaxes`/`Deductions`) — `[101]`
  requires them between vat and gross — then gross + aggregated income class.
- `paymentMethods`: one detail, `amount = gross`, `type = 3` (cash, hardcoded
  via `paymentMethodTypeFor()` pending a per-PaymentMethod map). `[204]` mandatory.
- **NO `<uid>`** — `[273]` forbids a client uid; AADE derives its own for
  retry-dedup. **NO `<taxesTotals>`** — VAT is NOT a `taxType` (1–5 =
  withholding/fees/otherTaxes/stamp/deductions); it lives per-line + in
  `totalVatAmount`.
- **Credit notes**: correlate to the original's INSERT MARK via
  `addCorrelatedInvoice` **only for correlated types (5.1)**;
  `Codes::isNonCorrelatedCreditType()` skips it for **5.2** (AADE forbids
  correlation there). `describeResponseErrors()` iterates firebed's `Errors`
  object (a `TypeArray`, NOT an array — `array_map` over it TypeErrors and masks
  the real rejection).

**Sandbox-validated types (zero rejections, no payload changes):** 1.1, 2.1,
11.2, 5.1, + a CANCEL; reconciliation matched all. Report:
`mydata-sandbox-validation-2026-05-28.md`.

**OPEN OPERATOR DECISION:** myip's ΠΙΣ maps to **5.2** (non-correlated) but the
new `IssueCreditNote` flow always issues *from* an original, so **5.1**
(correlated) is the natural choice and is sandbox-proven. Set the ΠΙΣ
`mydata_type` accordingly — the code now handles both.

**Code tables + preflight:** `App\Support\MyData\Codes` bakes the §8 tables
(invoice types, VAT categories + exemptions, payment methods, income
classification types/categories, withholding, quantity) with validation
helpers — the single source to refresh on spec changes.
`php artisan mydata:preflight` audits each tenant's invoice-type / VAT config
against them (read-only) and flags what AADE would reject. Exit 0/1/2.

**Submitter payload follow-ups (deferred; none hit by the 4 validated types):**
- **0% / exempt** → emit `vatCategory=7` + a `vatExemptionCategory` (§8.3,
  1–31); `vatCategoryFor()` currently THROWS on 0% (`[217]`/`[271]`).
- **4% ambiguity** — AADE category 6 (pre-existing island) vs 10 (ν.5057/2023);
  regime-dependent.
- **Conditional per-line `<quantity>`** for goods invoice types.
- **`taxesTotals`** for withholding / fees / stamp-duty invoices.
- **PaymentMethod → myDATA payment-type map** (currently all type 3 cash).
- **SendInvoices mock-Guzzle integration test** — now feasible from the
  captured live success XML.

### Reconciliation
- **Phase 1 — local** (`MyDataReconciliation` page): cross-checks our two
  internal columns; no AADE call. Lists `local_status` × `mydata_state`
  mismatches.
- **Phase 2 — live** (`SalesReconciler`, `MyDataConsole` page,
  `mydata:reconcile-sales`): pulls `RequestTransmittedDocs` (paginated, folds
  cancellations) and diffs vs local into matched / stateMismatch / missingAtAade
  / missingLocally / duplicateLocal. Read-only worklist. **Sandbox-verified
  2026-05-28** (parser needed no changes). Inbound `RequestDocs` (expense side)
  is NOT built — that's the Έξοδα phase.

---

## Money: payments + credit notes
- **Payments** (`Payment` model, `PaymentObserver`, `Payments` resource):
  per-invoice or on-account (invoice_id null). `InvoiceBalance` derives
  `owed = gross − credited`, `balance = owed − paid`, `payment_status`
  (`App\Enums\PaymentStatus`); cash-term (`due_days=0`) = paid at issue.
- **Credit notes** (`App\Actions\IssueCreditNote`): mirrors `CreateInvoice` —
  allocates ΑΑ, creates a credit-type invoice with `credited_invoice_id` +
  POSITIVE lines, writes `return_invoice_extras`, recomputes both. Stored with
  POSITIVE gross (negatives would break `InvoiceVatBreakdown`); the reduction
  is the original's `credited_total`. myDATA filing is **opt-in** (modal toggle,
  default OFF) — issues as a draft that already reduces the balance locally.
- **Cross-surface consistency** is guarded by `MoneyStatusConsistencyTest`
  (dashboard receivables == Σ ledger balances == per-invoice caches). Re-run it
  whenever a money surface or cache-sync path changes.

## Invoice lifecycle (`local_status` × `mydata_state`)
Transitions are `ViewInvoice` actions: Οριστικοποίηση (draft→active),
Επαναφορά σε πρόχειρο, Ακύρωση (→cancelled; detaches payment to on-account
credit; works on filed invoices but then surfaces the ⚠ reconciliation row),
Επαναφορά (blocked when `mydata_state=CANCELLED` — AADE cancel is terminal).
`MyDataSubmitter` syncs `local_status` at its own persist/cancel choke-point
(VALID→active, cancel→cancelled). `EditInvoice` is editable only while draft.

## WHMCS bridge (built — Stages A / B-1 / B-2 / B-3)
Operator-gated **inbox** model (NOT auto-issuing — invoices are legally
significant): WHMCS push/poll → ekdosi webhook → `pending_whmcs_invoices` →
operator reviews in `WhmcsInbox` → File at AADE → write back `invoiced=MARK`.
- Services: `Whmcs\WhmcsClient` (+factory), `WhmcsInvoiceIngestor`,
  `WhmcsInbox\WhmcsInvoiceMapper` + `WhmcsInvoiceFiler`, `WhmcsBridgeClient`
  (write-back). Webhook controllers in `routes/webhooks.php`.
- **HMAC** (must match both sides): `X-Webhook-Signature: sha256=<hex>` over
  the raw body (push/write-back) or the `"{slug}:{id}"` canonical string
  (status). Body is `{"whmcs_invoice_id":N}` only — we fetch the canonical
  payload via our API creds (never accept full invoice data over the webhook).
- **`whmcs-plugin/ekdosi_bridge/`** is OUR WHMCS-side plugin (the consolidated
  successor to the 3 archived legacy plugins). Deployed to the tenant's WHMCS.

**WHMCS gaps (real):**
- **`mod_timologia` third-party invoicing — MISSING (HIGH for myip).** Lets a
  client route a service's invoice to an alternate billing identity
  (employer/parent). `WhmcsCustomerMatcher` only documents it; nothing reads
  `mod_timologia*`. `WhmcsInvoiceMapper` always bills the resolved client.
- **`whmcs_amount_includes_tax`** documented but unimplemented — the mapper
  assumes GROSS line amounts; a tax-exclusive WHMCS tenant gets wrong VAT.
- **griniaris** (field 338 immediate-invoicing) — `needs_immediate_invoice`
  scaffolded, unwired (tied to the missing scheduler).
- The plugin under-reacts to ekdosi's `audit_preserved` / distinct 409/502
  responses; still `v0.1.0`.

---

## Where we stand — Legacy vs New + roadmap

**✅ DONE (built + unit-tested):** tenancy/auth (Shield); customers + GSIS
lookup + **Καρτέλα** ledger; products/tiers; 7 lookup tables; invoices
(numbering, VAT math, QR, PDF); lifecycle + local & live reconciliation;
**myDATA submit/cancel/dry-run** (sandbox-validated for myip's 4 types);
payments + credit notes; WHMCS bridge (A–B3) + `ekdosi_bridge` plugin; PDF +
per-tenant email + send-log; dashboard + widgets; ETL + import UI.

**🚧 PARTIAL:** auto-email on issue + audit BCC (email is a manual action, not
auto on filing); one adaptive PDF template vs the 8 legacy FastReport designs;
`ekdosi_bridge` error-handling (see WHMCS gaps).

**❌ NOT YET** (confirm real usage against the production `.fbk` before building
the usage-dependent ones):
- **No scheduler / cron at all** — `whmcs:fetch-pending`,
  `mydata:reconcile-sales`, `invoices:recompute-balances` are manual. The
  legacy overnight `FAutoInvoice` batch has no replacement. *Biggest
  "runs-itself" gap.*
- **`mod_timologia`** third-party invoicing (see WHMCS gaps) — HIGH.
- **Stock / inventory movements** — legacy decrements stock/reserve on issue +
  `CHECK_PROD_AVAILABILITY`; `products.reserve*` imported but no movement logic.
- **ΣΔΕΠ / cumulative invoices** — `conv_invoice_id` + self-relation exist; no
  attach-to-running-ΣΔΕΠ / delivery-note→invoice / Reserve check.
- **griniaris** routing; **"assigned invoices" `invoiced=-333`** (purpose
  unconfirmed); **gross-price-edit** on lines; live VIES/AFM validation.

**🆕 NEW phases discussed:** **Έξοδα / Expenses** (inbound `RequestDocs` +
suppliers + ΦΠΑ εκροών−εισροών report — largest net-new); Estonian PEPPOL
submitter; myDATA console one-click fixes; cross-model activitylog (do once).

**Suggested order:** (1) ✅ sandbox-verify myDATA — done. (2) Wire the
**scheduler** (unblocks griniaris). (3) **`mod_timologia`** (the HIGH WHMCS
gap). (4) Confirm-then-build stock / ΣΔΕΠ / -333 by grepping the `.fbk`.
(5) Auto-email on issue; gross-price-edit. (6) Έξοδα phase. (7) PDF per-type
fidelity. (8) PEPPOL.

---

## Known latent items / tech debt (still open — full context in the history doc)
- **No global `BelongsToCompany` scope** on Invoice/InvoiceLine/MyDataMark/
  Product/PaymentMethod — they rely on Filament's `BelongsToTenant`. Code
  outside a Filament request (queue jobs, the WHMCS bridge, scheduled commands)
  can see all tenants. Add scopes / assert tenant at service entry points.
- **Soft-deleted FK rows render blank** in Filament Selects app-wide (a deleted
  lookup row's dependents show empty). Fix once with `withTrashed()` label
  lookups + a "deleted" badge.
- **`Rule::unique(...)->where('company_id', Filament::getTenant()?->getKey())`**
  degrades to `WHERE company_id IS NULL` outside panel context — duplicates can
  pass in a queue/CLI path. A `TenantScopedUnique` helper should throw when
  tenant context is missing.
- **FK-aware delete guards** (`GuardedDeleteAction`) — deleting a referenced
  lookup either silently soft-deletes (orphaning dependents) or crashes on
  `restrictOnDelete`. Add a friendly count-and-block + "Deactivate".
- **activitylog not wired** on invoices/customers/payments (installed). Do all
  three at once; mind that re-imports bump `updated_at` and would spam it.
- **Per-tenant role-assignment UI** in UserResource (Shield teams mode handles
  the data layer; no per-tenant role picker yet).
- **ETL re-run preserves soft-delete but refreshes columns** — a row soft-
  deleted in ekdosi gets its legacy values re-applied on re-import (deleted_at
  stays). To truly drop a row across re-imports, force-delete it.
- **Concurrency / row-lock tests** can't run on sqlite (CI); the locks
  (`InvoiceNumberer`, `InvoiceBalance::recompute`) are real on MariaDB only.

## Env-prep gotchas (deploy host)
- **`pdo_firebird`** — only the ETL/artisan host needs it (lives in the
  `ondrej/php` PPA, or build from `firebird-dev`). Blocks `migrate:firebird`.
- **`ext-soap`** — needed for the GSIS lookup (`AadeRegistryLookup`) only; if
  `composer install` complains in a sandbox, `--ignore-platform-req=ext-soap`.
- **`gbak`** in PATH (firebird utils) — only for `.fbk` restores; `.fdb`
  uploads skip it.
- **PHP upload limits** for the import UI (both fpm AND cli php.ini):
  `upload_max_filesize`/`post_max_size`/`memory_limit` well above the largest
  `.fbk`; ensure `TMPDIR` is disk-backed (not RAM tmpfs) for big restores.

## Reading the legacy source
C++Builder (VCL): `.cpp`/`.h`/`.dfm`, IBX (`TIBQuery`/`TIBTransaction`),
cxGrid, JVCL. Grep for `AsCurrency`/`AsFloat` and `*BeforePost`/`*AfterPost`,
not Pascal idioms. Files are **WIN1253** — read Greek with
`iconv -f WINDOWS-1253 -t UTF-8 FAddInvoice.cpp`. Some shared headers
(`CMyData.h`, `CEditBox.h`, `CMySpecialForm.h`, `RegAccess.h`) are NOT in this
repo (external include path on the legacy box) — `firebed` replaces all of
`CMyData`. Quick file map: `FAddInvoice*`/`FEditInvoice` = issue/edit + VAT
math; `FAutoInvoice` = overnight batch (myDATA queue / WHMCS pull / -333 /
griniaris); `FInvoiceReturn` = credit notes; `FMysqlSync` = the dropped mirror;
`FShow*` = list views; `FManage*` = lookup CRUD.

## Sandbox the legacy DB before touching prod
`/legacy/ekdosi-main/db_backup/ekdosi.fbk` is a `gbak`. Restore:
`gbak -r ekdosi.fbk fresh.fdb -user SYSDBA -password masterkey` and point the
ETL at the restored `.fdb` while iterating. Re-runs upsert on
`(company_id, legacy_id)`; Filament-created rows (`legacy_id` null) are never
touched; deleted-from-source rows are left alone (never auto-deleted).
