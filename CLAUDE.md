# CLAUDE.md — ekdosi modernization

Working guide for this repo. Read this first.

> **Full history** (dated inspection notes, per-PR review logs, resolved
> deferred-findings) lives in **`docs/CLAUDE-history.md`** — not auto-loaded,
> consult it when you need the "why" behind a past decision.
> **AADE spec** lives under `docs/aade/`:
> **`docs/aade/myDATA_API_Documentation_v2.0.0_preofficial_erp.md`** (§8 = code tables,
> §7.2 = the 101–280 business-error list).
> The **Digital Delivery-Note lifecycle spec** (tracking layer, Jan 2026) is
> alongside it: **`docs/aade/myDATA_API_Documentation_DeliveryNote_v2.0.1_preofficial.md`**
> (§7.1 = InvoiceDeliveryStatus codes, §7.2 = event types, §6.2 = 800–824
> business errors). Feeds Delivery Phase 3/4 — see the Delivery-notes section.

## What this project is
Porting a legacy **C++Builder (VCL) + Firebird** invoicing app ("ekdosi") to
**Laravel 13 + FilamentPHP 5 + MariaDB**. A homegrown Greek τιμολογιέρα with
**myDATA**, **QR**, and a **WHMCS bridge**, operators-only (internal, no
customer portal), that compiled only on a fragile Windows 7 VM — escaping
that toolchain is the whole point.

Scope: customers, stock/products, services, invoices (παραστατικά),
payments, credit notes, quotes (προσφορές), myDATA submission +
reconciliation + audit trail, WHMCS bridge. The old C++Builder/Firebird app becomes a read-only archive
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
  (teams mode; managed roles per tenant: `super_admin`, `company_admin`,
  `operator` — provisioned by `TenantRoleProvisioner`, see latent items).
- **PDF**: `barryvdh/laravel-dompdf`. **Backups**: `spatie/laravel-backup`.
- **Audit log**: `spatie/laravel-activitylog` (wired on invoices/customers/
  payments via `App\Models\Concerns\TracksActivity`; «Ιστορικό» tab per record).
- **Queue/scheduler**: Laravel built-in (DB driver). Scheduler IS wired
  (`routes/console.php`, gated by `config/ekdosi.php`) — needs the OS cron +
  a queue worker to actually run (see Env-prep).
- **Firebird driver on the ETL host**: `pdo_firebird` (only the artisan host
  needs it).

## Repo layout
```
/                         # Laravel 13 app at repo root
  app/Console/Commands/MigrateFromFirebird.php   # re-runnable ETL, one tenant per run
  app/                                           # models, Filament panels, services, actions
  database/migrations/                           # 75 migrations
  whmcs-plugin/ekdosi_bridge/                    # OUR WHMCS-side plugin (deployed to tenant's WHMCS)
  docs/aade/myDATA_API_Documentation_v2.0.0_preofficial_erp.md   # the AADE spec (submission)
  docs/aade/myDATA_API_Documentation_DeliveryNote_v2.0.1_preofficial.md  # ΔΑ lifecycle/tracking spec
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

## Changelog + features discipline (keep these current — we were losing track)
Part of "done", like tests. **Every change updates the right place:**
- **`CHANGELOG.md`** (repo root) — the ekdosi **app** (Laravel/Filament). Add a
  one-liner under `## [Unreleased]` ([Keep a Changelog](https://keepachangelog.com/):
  `Added`/`Changed`/`Fixed`/`Removed`/`Security`).
- **Versioning — SemVer `X.Y.Z`, app semantics** (canonical: `config('app.version')`).
  Cut a release with **`php artisan ekdosi:release {--major|--minor|--patch}`** (rolls
  `[Unreleased]` → dated `[X.Y.Z]`, bumps `config/app.php`, prints the `git tag` command).
  The LEVEL is judgement — the rule of thumb:
  - **major (X.0.0)** = a milestone/epoch (e.g. PEPPOL goes live, a cutover).
  - **minor (x.Y.0)** = the `[Unreleased]` block contains an **`Added`** (a new feature).
  - **patch (x.x.Z)** = only `Fixed`/`Changed`/`Security`/docs since the last tag.
- **`FEATURES.md`** (repo root) — the catalogue of WHAT ekdosi does. **A NEW feature
  (not a fix/tweak) ALSO gets a line/bullet here**, under the right section. This is
  the «μην χανόμαστε» file — keep it the truthful single source of what's built.
  (`docs/BACKLOG.md` is its twin = what's left + new ideas; move an item from BACKLOG
  → FEATURES when it ships.)
- **`whmcs-plugin/ekdosi_bridge/CHANGELOG.md`** — the **WHMCS plugin**. A plugin
  change BOTH adds a line here AND bumps `'version'` in `ekdosi_bridge.php`
  (move the `[Unreleased]` items under the new `[vX.Y.Z]` heading).
- Keep entries terse (one line); the deep "why" still goes to
  `docs/CLAUDE-history.md`. Don't backfill old versions — start from now.

## Commands
```bash
php artisan ops:health [--json]                         # one-shot deploy check: queue/scheduler/backup/mail/WHMCS/myDATA/disk
php artisan migrate
php artisan shield:generate                              # (re)sync resource permissions after new resources

# ETL — one tenant per legacy DB, re-runnable (needs pdo_firebird on the artisan host)
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" --host=10.23.22.5 --fbuser=EKDOSI --fbpass=ekdosi1234
php artisan invoices:recompute-balances --company=myip   # backfill money cache after import

# myDATA ops (also run on the scheduler — see routes/console.php; safe to run manually anytime)
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
`docs/mydata-sandbox-validation-2026-05-28.md`.

**Sandbox round 2 — ✅ 2026-06-10 (`sandbox-results.txt`):** the new taxTypes
(χαρτόσημο 3,6% · fees · product-linked per-unit fees), the **4% override → cat 10**,
and the full **ΔΑ lifecycle** (issue/register/confirm) all AADE-accepted (real MARKs).
Two learnings, both fixed/expected:
- **[208]** — withholding **DOES reduce** `totalGrossValue` (and the paymentMethod
  amount): gross = net+vat + fees + stamp + otherTaxes − deductions − withheld,
  EXCEPT the informational §8.4 categories 8/9/10 (via
  `WithheldPercentCategory::affectsTotalGrossValue()`). The earlier «withholding
  doesn't change gross» assumption was WRONG — corrected in `AadeInvoiceDocument`.
- **[801]** — a **Completed** delivery note can't be cancelled (by design, not a bug).

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

**Submitter payload follow-ups — ✅ ALL DONE + sandbox-validated 2026-06-10 (see
«Sandbox round 2» above; the withholding-gross [208] case was the one fix it found):**
- **0% / exempt** → ✅ G4: `vatCategory=7` + `vatExemptionCategory` (§8.3) from the
  tenant's 0%-rate VatCategory.
- **4% ambiguity** (cat 6 island vs 10 ν.5057/2023, 3%→9) → ✅ optional
  `vat_categories.mydata_vat_category` override, scoped to the 3%/4% rates
  (`AadeInvoiceDocument::mydataCategoryOverride`).
- **Conditional per-line `<quantity>`** → ✅ G5 (`invoice_types.mydata_requires_quantity`).
- **`taxesTotals`** for withholding/fees/stamp/otherTaxes/deductions → ✅ G1
  (withholding) + #3c (the other four): amount + §8.x category per type, gross +
  payment adjusted (`AadeInvoiceDocument::addAdditionalTaxes`). The invoice form has
  a **«Τυπικά τέλη/φόροι» quick-fill** (`CommonTaxPresets`) over the raw fields.
- **PaymentMethod → myDATA payment-type map** → ✅ G9 (`payment_methods.mydata_payment_type`).
- **SendInvoices mock-Guzzle integration test** → ✅ (`MyDataSubmitterSafetyTest`,
  full `submit()` round-trip against firebed's success stub).
- **Still open:** auto-calc of percentage amounts (the preset helper does it on pick;
  a live/on-save recompute from net is the next step) + curated-preset expansion.

### Reconciliation
- **Phase 1 — local** (`MyDataReconciliation` page): cross-checks our two
  internal columns; no AADE call. Lists `local_status` × `mydata_state`
  mismatches.
- **Phase 2 — live** (`SalesReconciler`, `MyDataConsole` page,
  `mydata:reconcile-sales`): pulls `RequestTransmittedDocs` (paginated, folds
  cancellations) and diffs vs local into matched / stateMismatch / missingAtAade
  / missingLocally / duplicateLocal. Read-only worklist. **Sandbox-verified
  2026-05-28** (parser needed no changes). Inbound `RequestDocs` (expense side)
  IS built (`ExpenseReconciler` / `MyDataConsoleExpenses` — see `FEATURES.md §4`).
- **Console UX (2026-05-31):** orphans (`missingLocally`) are bucketed by
  economic type — `Codes::transmittedDocBucket()` → income (1/2/5/6/7/8/11) /
  expense (13/14) / other (3/15/16/17 = μισθοδοσία, πάγια…) — so a €5k payroll
  no longer reads as a missed sale. All three consoles (sales / expenses / E3)
  cache the last fetch per tenant (`RemembersLastFetch`, 12h) and rehydrate on
  mount. **MARK detail** (`MyDataMarkDetail` / `MarkDetail`) is direction-aware
  (issuer vs us; «λιανική — δεν δηλώνεται» when myDATA omits the issuer) and
  surfaces the per-line E3 classification (no free-text description exists in
  myDATA). The **E3 overview** splits income vs expense with separate subtotals
  + a ΦΠΑ-τριμήνου box from `VatPictureCache`.

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

## WHMCS bridge (built)
Operator-gated **draft-first inbox** (NOT auto-issuing — invoices are legally
significant): WHMCS push/poll → ekdosi webhook → `pending_whmcs_invoices` → operator
«Δημιουργία Παραστατικού» (editable draft) → issue via the normal lifecycle (→ myDATA)
→ write-back `invoiced=MARK`. The inbox never files straight to AADE; the direct
`file()` path survives only for the γκρινιάρης `whmcs:auto-issue`. Services:
`WhmcsClient` (+factory), `WhmcsInvoiceIngestor`, `WhmcsInbox\WhmcsInvoiceMapper`/
`WhmcsInvoiceFiler`, `WhmcsWritebackService`, `WhmcsBridgeClient`; webhooks in
`routes/webhooks.php`. **`whmcs-plugin/ekdosi_bridge/`** is OUR WHMCS-side plugin.

> The feature list is in **`FEATURES.md §11`**; the plugin-version blow-by-blow in
> `CHANGELOG.md` + `whmcs-plugin/ekdosi_bridge/CHANGELOG.md`; the third-party design
> in `docs/whmcs-legacy-plugin-map.md`; the connectors story in `docs/bridges-connectors.md`.

**Locked decisions (don't re-litigate):**
- **HMAC** (both sides): `X-Webhook-Signature: sha256=<hex>` over the raw body
  (push/write-back) or the `"{slug}:{id}"` canonical string (status). Body is
  `{"whmcs_invoice_id":N}` only — fetch the canonical payload via our API creds
  (never trust full invoice data over the webhook).
- **The MARK lives in our own `mod_ekdosi_invoice_marks`; the bridge NEVER writes
  `tblinvoices.invoiced` at runtime** (it's a legacy SMALLINT {0,1} flag — widening it
  broke the legacy app; activation restores it to SMALLINT).
- **Deterministic links only.** `tblinvoices.invoiced === invoices.legacy_id` is an EXACT
  FK (the legacy auto-invoicer wrote the ekdosi INVOICE_ID there) → `whmcs:backfill-invoice-ids`
  lights up the imported invoices. The heuristic content-matcher was BUILT then REMOVED —
  never guess a legal link; forward-only `whmcs_pending_id` for new ones.
- **Plugin-API is THE path.** ekdosi → WHMCS via the plugin's `resolve.php` (HMAC, logged to
  `mod_ekdosi_bridge_log` → «Bridge logs» tab); `companies.whmcs_fetch_via_bridge` (guarded
  `whmcs:use-bridge`); native `WhmcsClient` only for plugin-less tenants. WHMCS `GetInvoices`
  paginates via `limitstart/limitnum` (NOT limit/offset — silently ignored; THE bug that
  froze the inbox at 16).
- **VAT net-vs-gross is DETECTED** from the payload (`subtotal/tax/taxrate/total`), not guessed;
  `whmcs_amount_includes_tax` is only the no-breakdown fallback.
- **Third-party (timologia v2):** multi-party → block + guided split (never the legacy's silent
  merge). **Bridges/Connectors Phase 0**: a `billing_connections` registry (one row per
  company×system) + `BillingSource`/`BillingSourceRegistry` so a tenant can run several billing
  sources; Phase 1 = a real 2nd source.

---

## Where we stand — status lives elsewhere now
**What's built → `FEATURES.md`** (root, the catalogue). **What's left + new ideas →
`docs/BACKLOG.md`** (incl. the «looks like a gap but isn't» list — read it before
re-opening anything). Per-change detail → `CHANGELOG.md`; the «why» → `docs/CLAUDE-history.md`.

A few **don't-re-port / don't-re-litigate** decisions worth keeping inline:
- **Stock movements & ΣΔΕΠ/cumulative were DEAD CODE in legacy** (`CHECK_PROD_AVAILABILITY`
  empty, `findCumInvoiceDate()` returns 0). Net-new IDEAS, not a port we're behind on — build
  only if the prod `.fbk` proves real usage (`docs/go-live-usage-checks.sql.md`).
- **Delivery notes (Ψηφιακό ΔΑ): firebed already implements the whole v2.0.x tracking API** —
  don't re-port the protocol, only wire it. Issue+cancel → provider; movement lifecycle
  (έναρξη/παράδοση/έλεγχος/history) → direct myDATA for everyone (confirmed by InvoSign).
  Full model: `docs/delivery-provider-split-brain.md`.
- **Credit notes**: legacy `CREATE_RETURN_INVOICE` was an empty stub → `IssueCreditNote` is a
  clean reimplementation, not a risky port.

---

## Known latent items + key tenant-safety behaviors
Open items live in `docs/BACKLOG.md` (tech-debt section); the «why» in `docs/CLAUDE-history.md`.
The behaviors below are how the system actually works — keep them in mind:
- **Global `BelongsToCompany`/`CompanyScope` (no-op mode).** Tenant-owned models carry a
  `CompanyScope` driven by the ambient `CompanyContext` singleton (Filament sets it on `TenantSet`),
  so raw `Invoice::where(...)` in the panel auto-filters. **No-op when no context** (CLI/queue) →
  existing explicit `->where('company_id', …)` paths unchanged. Opt in from CLI/jobs with
  `CompanyContext::actAs($company, fn () => …)`; opt out per query with
  `->withoutGlobalScope(CompanyScope::class)`. (Strict null→throw mode deferred — BACKLOG.)
- **Activity log** (`spatie/laravel-activitylog` via `TracksActivity` on Invoice/Customer/Payment):
  `logOnly(loggedAttributes())` — business columns only, NEVER the money/myDATA CACHE columns;
  `logOnlyDirty()` + `dontLogEmptyChanges()` (a cache recompute or the query-builder ETL never spams
  the trail). v5 stores the diff in **`attribute_changes`** (not `properties`); causer null = «Σύστημα».
  `activity_log.company_id` powers the tenant-wide `ActivityFeed`.
- **Per-tenant roles** (`super_admin`/`company_admin`/`operator`, `TenantRoleProvisioner`):
  `company_admin` = every tenant permission EXCEPT `ADMIN_FORBIDDEN_RESOURCES` (User/Company/Role,
  panel-global); `operator` = explicit `OPERATOR_PERMISSION_MAP`. Role management is super_admin-only
  (`->visible()` AND a hard guard in the action body — `mountAction` doesn't re-check visible()).
  Screens gate on real permissions via `Gate::can` (missing → false, never `PermissionDoesNotExist`).
  **After deploy: `shield:sync-super-admin`.**
- **GuardedDeleteAction** ✅ blocks deleting an in-use lookup (single-record, withTrashed-aware,
  complete maps); bulk/force-delete still unguarded + soft-deleted-FK-blank Selects → BACKLOG.
  `TenantScopedUnique` = non-issue (the DB already has the `unique(company_id,…)` constraints).
- **Gotchas:** ETL re-run re-applies legacy column values to a soft-deleted row (deleted_at stays —
  force-delete to truly drop). Row-lock tests (`InvoiceNumberer`, `InvoiceBalance::recompute`) are
  MariaDB-only (can't run on sqlite/CI).


## Env-prep gotchas (deploy host)
> **Don't re-derive deploy state by hand.** Run **`php artisan ops:health`**
> (`--json` for machine output) — it checks queue worker, scheduler, backups,
> mail, WHMCS, myDATA and disk in one shot (`OperatorHealth`, see
> `docs/operator-health.md`). The full provisioning lives in **`INSTALL.md`**
> (AlmaLinux: php-fpm, MariaDB, the systemd queue unit `ekdosi-queue.service`,
> the scheduler + backup cron lines) and **`README.md` §Deploy notes**. **Deploy
> routine after `git pull`:** `php artisan migrate` → `php artisan queue:restart`
> (worker picks up new code) → `shield:sync-super-admin` when permissions changed.
- **Scheduler + queue worker — PROVISIONED on prod (systemd + cron).** The wired
  schedule (`routes/console.php`) IS live on the production host: a cron line runs
  `php artisan schedule:run` every minute, and a **systemd service** keeps a
  `php artisan queue:work` worker up for the mail + import jobs. So the scheduled
  features (per-company backups + the failure alerting, auto-email, myDATA
  reconcile, WHMCS fetch/auto-issue, VAT picture) DO fire — each still gated by its
  `config/ekdosi.php` env flag (`EKDOSI_SCHEDULE_*`), so flipping a flag is how you
  enable/disable an individual task. `withoutOverlapping` uses the cache-lock store,
  so the cache driver must work (DB driver needs the `cache` tables migrated). After
  editing `config/ekdosi.php` on a deploy that caches config, run
  `php artisan config:clear`/`optimize`. (Local/CI or a fresh box without the cron +
  worker stays inert until both are started — `schedule:run` via cron + a running
  `queue:work`/supervisord/systemd worker.)
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
