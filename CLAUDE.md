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
  IS built — see the Έξοδα phase (`ExpenseReconciler` / `MyDataConsoleExpenses`).
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

## WHMCS bridge (built — Stages A / B-1 / B-2 / B-3)
Operator-gated **inbox** model (NOT auto-issuing — invoices are legally
significant): WHMCS push/poll → ekdosi webhook → `pending_whmcs_invoices` →
operator reviews in `WhmcsInbox` → **«Δημιουργία Παραστατικού» (editable draft)**
→ fix line text → issue via the normal invoice lifecycle (Οριστικοποίηση →
myDATA submit) → write-back `invoiced=MARK`. The inbox no longer files straight
to AADE (safer — review the real παραστατικό first); the direct `file()` path
survives only for the γκρινιάρης `whmcs:auto-issue` command.
- Services: `Whmcs\WhmcsClient` (+factory), `WhmcsInvoiceIngestor`,
  `WhmcsInbox\WhmcsInvoiceMapper` + `WhmcsInvoiceFiler`, `WhmcsBridgeClient`
  (write-back). Webhook controllers in `routes/webhooks.php`.
- **HMAC** (must match both sides): `X-Webhook-Signature: sha256=<hex>` over
  the raw body (push/write-back) or the `"{slug}:{id}"` canonical string
  (status). Body is `{"whmcs_invoice_id":N}` only — we fetch the canonical
  payload via our API creds (never accept full invoice data over the webhook).
- **`whmcs-plugin/ekdosi_bridge/`** is OUR WHMCS-side plugin (the consolidated
  successor to the 3 archived legacy plugins). Deployed to the tenant's WHMCS.

**Inbox UX + visibility (2026-05-31):**
- **Draft-first inbox** (`WhmcsInvoiceFiler::createDraft`): primary action
  «Δημιουργία Παραστατικού» creates an editable draft (`status='drafted'`, ΑΑ
  allocated, `local_status='draft'`, `whmcs_pending_id` linked) — NO AADE submit;
  mirrors the splitter. Operator edits then issues via the lifecycle.
- **Billing-intent surfacing**: the inbox reads the WHMCS client custom fields
  off the staged payload via `companies.whmcs_custom_field_map` (roles `vatno`,
  `taxoffice`, `occupation`, `griniaris`, **`wantsinvoice`**) →
  `PendingWhmcsInvoice::wantsInvoice()/whmcsAfm()/needsAfm()/whmcsClientName()`.
  Columns «Πελάτης (WHMCS)» + «Πρόθεση» (Τιμολόγιο/Απόδειξη, red «λείπει ΑΦΜ»);
  the Hold action takes a reason (`hold_reason`); the WHMCS # opens a full
  read-only invoice modal. `whmcs_third_party_enabled` is now a Company toggle.
- **`ekdosi_bridge` plugin v0.5.0 — WHMCS-side visibility** (PR #101 branch):
  per-invoice admin badge with the real MARK, an invoice-LIST badge (footer-JS
  + read-only `marks` JSON action), a CSRF «Αποστολή στο Ekdosi» button, and a
  per-client **3-way map** (WHMCS#→ΤΠΥ→ΜΑΡΚ, drafts incl.) via
  `GET /webhooks/whmcs/{slug}/invoice-map/{whmcs_userid}`
  (`WhmcsClientInvoiceMapController`, HMAC `"{slug}:map:{userid}"`).

**Live-deploy hardening + UX (2026-05-31, plugin v0.12.0 — PRs #112–#120,
verified against the restored prod WHMCS):**
- **AFM-keyed visibility** (no stored WHMCS↔ekdosi link survived the import —
  verified `customers.whmcs_client_id` NULL 100%, `whmcs_invoice_log` empty):
  `POST /webhooks/whmcs/{slug}/invoices-by-afm` (per-client card, ΑΦΜ set) +
  `POST .../invoice-states` (batch ΤΠΥ/ΜΑΡΚ for the addon invoice list). The
  per-invoice `invoice-status` now also returns `ekdosi_invcode`. **The legacy
  historical WHMCS#→ΤΠΥ matcher was BUILT then REMOVED** — content-matching is
  heuristic and wrong on a legal link; forward-only deterministic
  (`whmcs_pending_id`) is the policy.
- **Inbox paging — THE bug that hid invoices:** `getPendingInvoices` used
  `limit`/`offset`, which WHMCS GetInvoices SILENTLY IGNORES (it paginates via
  **`limitstart`/`limitnum`**) → every page identical → "stuck at 16 / 5-min
  freeze". Fixed: correct params, advance cursor by ACTUAL returned count, stop
  on short/empty page, id-repeat loop guard. Prod 16→146.
- **VAT net-vs-gross from the payload, not a guess:** `WhmcsInvoiceMapper`
  reads the invoice's own `subtotal/tax/taxrate/total` — subtotal+tax==total →
  amounts are NET (add VAT); subtotal==total → GROSS (back out). Falls back to
  `whmcs_amount_includes_tax` only when no breakdown. (Closed the "ekdosi
  πετσοκόβει την τιμή" €19 vs €23.56 bug.)
- **Third-party recipient at file time:** the «Δημιουργία Παραστατικού» modal
  shows the routed beneficiaries (read-only) + lets the operator pick ANY
  customer as recipient (name+ΑΦΜ search — covers "pick the third party"); the
  inbox «Τρίτος» column (now right after «Πελάτης (ekdosi)») shows the
  beneficiary NAME, not just «Σε τρίτο». Changing the recipient while a draft
  works via EditInvoice (`customer_id` editable until a MARK is issued).
- **AADE as source of truth on Customer/Supplier forms:** «Άντληση από ΑΑΔΕ»
  (fill-empty) **+** «Διόρθωση από ΑΑΔΕ» (overwrite, confirm) — when the
  customer typed wrong, the GSIS registry wins. Shared rule in
  `AadeFormFill::assign($get,$set,$field,$value,$overwrite)` (never blanks on
  empty AADE value).
- **Addon invoice list** (plugin): consolidated WHMCS→ekdosi list with date
  window (μήνας/τρίμηνο/όλα), «Είδος» (τιμολόγιο/απόδειξη), «Τρίτος» resolved
  LOCALLY from `mod_ekdosi_routing` (`ThirdPartyStore::bucketsForInvoices`,
  shows the beneficiary name) — works for every invoice, no ekdosi/inbox
  dependency. Admin third-party routing is now EDITABLE
  (`ThirdPartyStore::setRouteForUser`, CS-side re-route; takes effect next
  invoice since `resolve.php` reads routing live).
- **Admin-only `whmcs_third_party_enabled`** gates the per-invoice resolve.php
  call (fills «Τρίτος»); independent from the client-area `show_client_v2`
  visibility switch. `whmcs:fetch-pending` re-resolves third parties per ingest.

**WHMCS gaps (real):**
- **Write-back on lifecycle-filed drafts (tracked):** when a draft is issued via
  the normal lifecycle (not the direct `file()`), `invoiced=MARK` + pending→filed
  sync don't fire yet. Wire it off `MyDataSubmitter`'s VALID persist for invoices
  carrying `whmcs_pending_id` — fixes the split-draft write-back gap too.
- **`mod_timologia` third-party invoicing — ✅ DONE (T-1 + T-2, merged).** The
  bridge resolves each line's routing (own `mod_ekdosi_*` tables, synced from
  legacy), ekdosi bills the end customer for single-party invoices and stages
  multi-party ones for a guided operator split; a hideable v2 client page lets
  resellers manage contacts + routing. Full story:
  `docs/whmcs-legacy-plugin-map.md`. Remaining: per-group invoice-type at file
  time relies on the operator; WHMCS write-back of split invoices (one MARK col).
- **`whmcs_amount_includes_tax`: ✅ SUPERSEDED (2026-05-31).** The mapper now
  DETECTS net-vs-gross from the invoice payload's `subtotal/tax/taxrate/total`;
  the toggle is only the fallback when no breakdown is present. A tax-exclusive
  tenant is handled automatically.
- **FUTURE IDEA — "all of a client's third parties" 2nd dropdown** in the
  create-draft modal: pre-fill a recipient picker with EVERY third party the
  client routes to (not just this invoice's). The data lives in WHMCS
  `mod_ekdosi_contacts` (ekdosi can't read it → would need a new
  `contacts-by-userid` bridge endpoint). DEFERRED: the existing ΑΦΜ search on
  the recipient dropdown already covers picking any third party; this is a
  speed/convenience nicety, not a gap.
- **griniaris** (field 338 immediate-invoicing) — ✅ DONE (G8, two phases):
  phase 1 = an "Άμεσο" badge on inbox rows whose customer is `needs_immediate_invoice`;
  phase 2 = the `whmcs:auto-issue` command auto-files those rows at AADE (see G8 below).
- The plugin under-reacts to ekdosi's `audit_preserved` / distinct 409/502
  responses; still `v0.1.0`.

---

## Where we stand — Legacy vs New + roadmap

**✅ DONE (built + unit-tested):** tenancy/auth (Shield); customers + GSIS
lookup + **Καρτέλα** ledger (+ YoY KPI/charts); products/tiers; 7 lookup tables;
invoices (numbering, VAT math, QR, PDF); lifecycle + local & live
reconciliation; **myDATA submit/cancel/dry-run** (sandbox-validated for myip's 4
types); payments + credit notes; WHMCS bridge (A–B3) + `ekdosi_bridge` plugin;
**timologia v2 / third-party invoicing — T-1 + T-2 DONE & merged** (resolution,
single-party billing, multi-party split, hideable client page; see the WHMCS
section + `docs/whmcs-legacy-plugin-map.md`); PDF + per-tenant email + send-log;
dashboard + widgets; ETL + import UI; **scheduler wired** (`routes/console.php`).

**🚧 PARTIAL:** auto-email — both issue paths now covered: the **myDATA-VALID**
path (`MyDataSubmitter::dispatchAutoEmailIfEnabled`, gated by
`auto_email_on_mydata_accept`, with audit BCC) and the **non-myDATA finalize
path** (G6 ✅ — `companies.auto_email_on_issue` fires on draft→active for
`none`/Estonian/mode-off tenants, guarded against double-send for myDATA
tenants). Both honour a per-customer opt-out (`customers.auto_email_invoices`,
default on); the manual "Resend email" action ignores both toggles. The
remaining gap is a **batch mail sweep** (re-send failures / bulk). One
adaptive PDF template vs 8 legacy FastReport designs (G10); `ekdosi_bridge`
error-handling.

### Gap analysis — legacy vs new (verified 2026-05-28, by code scan)
Two scans cross-checked legacy source + Firebird schema against the actual new
code. **Corrections to earlier roadmap claims** (these SHRINK the backlog):
- **Stock / inventory movements were NEVER implemented in legacy.**
  `CHECK_PROD_AVAILABILITY` is an empty-body stub (`ekdosi-schema.sql`); no stock
  table; `PRODUCT.RESERVE*` are fractional factors, not quantities; no decrement
  code in any form. So this is a *net-new idea*, not a port we're "behind" on. LOW.
- **ΣΔΕΠ / cumulative invoices are DEAD CODE in legacy.**
  `findCumInvoiceDate()` starts with `return(0)`; `CREATE_RETURN_INVOICE` is an
  empty stub. Build only if the prod `.fbk` shows real `CONV_INVOICE_ID` rows. LOW.
- **Credit notes**: legacy `CREATE_RETURN_INVOICE` was an empty stub → our
  `IssueCreditNote` is a clean reimplementation, not a risky port.

**Real remaining gaps (myDATA-filing correctness — the ones that matter):**
- **G1 — Withholding (παρακράτηση): ✅ DONE.** `MyDataSubmitter` now emits a
  `taxesTotals[taxType=1]` block (category + amount) when `withhold_amount > 0`,
  matching the summary's `totalWithheldAmount`. New `invoices.withhold_category`
  (§8.4, 1–18) + a required-when-amount form select; throws if amount set
  without a valid category. (Auto-calc of 20%×net from the customer flag is a
  separate UX follow-up — the *transmission* gap is closed.)
- **G4 — 0% / VAT-exempt lines: ✅ DONE.** `vatCategoryFor(0)` returns 7 and the
  line carries `vatExemptionCategory` (§8.3) resolved from the tenant's 0%-rate
  VatCategory's new `vat_exemption_category` field; throws if unconfigured or
  ambiguous. WHMCS filer pre-flight aligned. Set the reason on the 0%-rate VAT
  category (Setup → VAT Categories).
- **G3 — net/gross handling: ✅ DONE + HARDENED (2026-05-31).** `WhmcsInvoiceMapper`
  now DETECTS net-vs-gross from the invoice payload (`subtotal/tax/taxrate/total`:
  subtotal+tax==total → net, add VAT; subtotal==total → gross, back out net),
  so it's correct without per-tenant config. `companies.whmcs_amount_includes_tax`
  (default true) is only the fallback when the payload carries no breakdown.
- **G9 — PaymentMethod→myDATA type: ✅ DONE.** `payment_methods.mydata_payment_type`
  (§8.12, 1–8); `MyDataSubmitter::paymentMethodTypeFor` reads it, falls back to
  3 (cash) when unmapped/invalid. PaymentMethod-form select.
- **G5 — per-line `<quantity>`: ✅ DONE.** `invoice_types.mydata_requires_quantity`
  (default false = the validated service path, no quantity); goods types opt in
  and the submitter emits `setQuantity(qty)`. `measurementUnit` omitted
  (optional per spec) — a follow-up if a goods tenant needs §8.13 units.
  InvoiceType-form toggle. (Enable + sandbox-verify per the goods tenant.)
- **G7 — gross-price line edit: ✅ DONE.** The `InvoiceForm` lines repeater
  adds a VAT-inclusive "Unit price (incl. VAT)" input that back-computes net
  (`price_per_item = wvat / (1+vat/100)`, `InvoiceForm::netFromGross`); two-way
  synced with the net field + re-derived on VAT/product change. Net stays the
  stored source of truth (`dehydrated(false)`; `InvoiceLine::saving` is
  authoritative). Conversion math unit-tested (`GrossPriceConversionTest`).
  UX parity.
- **G6 — auto-email on the non-myDATA issue path: ✅ DONE.** Two-level gate:
  `companies.auto_email_on_issue` (global, default OFF — kill-switch for
  testing) fires `SendInvoiceEmail` on the `finalize` action (draft→active)
  for tenants NOT filing via myDATA (those get it on VALID — `Invoice::
  shouldAutoEmailOnFinalize()` guards the double-send); `customers.
  auto_email_invoices` (per-customer, default ON) opts a customer out of BOTH
  auto paths (`Invoice::customerAcceptsAutoEmail()`); manual "Resend email"
  ignores both. Gate predicates unit-tested (`AutoEmailGateTest`). Remaining:
  a batch mail sweep (bulk / failure re-send).
- **G8 — griniaris immediate-invoicing: ✅ DONE (two phases).** Phase 1: an
  "Άμεσο" badge (`heroicon-o-bolt`) on WHMCS-inbox rows whose matched customer
  is `needs_immediate_invoice` — pure prioritisation hint. Phase 2: the
  `whmcs:auto-issue` command (scheduled, gated by `EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE`)
  auto-FILES paid inbox rows at AADE for those customers, **two-key armed** (that
  scheduler flag AND per-tenant `companies.whmcs_auto_issue_immediate`, both
  default OFF). Reuses `WhmcsInvoiceFiler::file()` (all guards intact) and only
  touches the unambiguous set — `status=pending_review`, `invoice_id` null,
  `third_party_state ∈ {null,none,single}`; anything `held`/`multi`/unmatched is
  left in the inbox for a human. Requires a per-tenant default invoice type
  (`companies.whmcs_default_invoice_type_id`) — never guesses; skips the tenant
  if unset. Tenant-safe (explicit `company_id` scope, no `BelongsToTenant` in
  CLI). Loud audit: `Log::info` per filing + `Αυτόματη έκδοση (γκρινιάρης)` in
  the row notes (filer gained an optional `$auditNote`). `WhmcsAutoIssueCommandTest`
  covers the gate. **Inert until the scheduler + queue worker run.**

**❌ NOT YET (lower / confirm-usage-first):** stock movements & ΣΔΕΠ (dead in
legacy — see corrections); `invoiced=-333/-1000` WHMCS sentinel states;
customer manual reorder UI; `conf_params` imported-but-unread; live VIES/AFM.

**✅ Έξοδα / Expenses phase — FIRST COMPLETE PASS (E0–E7, built + reviewed).**
The supplier/inbound mirror of the sales side, end-to-end:
- **Suppliers** (`Supplier` + `SupplierSource`): the προμηθευτές entity. CRUD +
  "Άντληση από ΑΑΔΕ" (GSIS) + bulk `SupplierSyncFromMyData` (`suppliers:sync`
  command + list action) that scans `RequestDocs` for unique issuer AFMs.
- **Data model**: `expenses` + `expense_lines` + `expense_marks` (twins of
  invoices/invoice_lines/mydata_marks; §8.2/§8.3 codes stored verbatim).
- **`ExpenseReconciler`** over `RequestDocs` — the exact reverse of
  `SalesReconciler` (same pagination/folding/buckets), `missingLocally` =
  αδέσποτα έξοδα.
- **`MyDataConsoleExpenses`** page + **`ExpenseImporter`** — one-click import of
  αδέσποτα into local `expenses` (+ lines + supplier + audit mark, idempotent),
  and a read-only `ExpenseResource` (list/view/lines).
- **`Expense` classification (E5, LOCAL)** — per-document E3 type + category2_x
  via the ViewExpense action; `Codes::expenseClass*` delegate to firebed's §8
  enums. **AADE submit (`SendExpensesClassification`) deferred.**
- **`VatPeriodReport` + `MyDataPictureStats` widget** — ΦΠΑ εκροών−εισροών per
  month/quarter ("πόσο ΦΠΑ χρωστάω"). **`RequestVatInfo` cross-check deferred.**
- **`E3Reporter` + `MyDataE3Overview`** — Ε3 figures from `RequestE3Info`.
**Full story + remaining-polish list: `docs/expenses-phase-plan.md`.** Deferred:
expense-classification AADE submit, RequestVatInfo/E3 cross-checks,
`RequestMyExpenses`, manual expense entry, per-row import, supplier CSV import.

Also still open: Estonian PEPPOL submitter; myDATA console one-click fixes;
cross-model activitylog (do once). **E-invoicing-via-provider (GR ΥΠΑΗΕΣ) +
PEPPOL blueprint: `docs/einvoice-provider-bridge.md`** (deferred; the
`EInvoiceSubmitter` factory already has the slot — a `gr-provider`/`PeppolSubmitter`
drops in. Provider schema reference: `docs/reference/aade-provider-invoicesDoc-v0.6.1.xsd`).

**Suggested order:** (1)✅ sandbox myDATA. (2)✅ scheduler. (3)✅ timologia v2
(T-1+T-2). (4)✅ **G1 withholding + G4 exempt** (merged, PR #68). (5)✅ **G3
tax-inclusive + G9 payment-type + G5 goods-quantity** (filing correctness).
(6)✅ **G7 gross-edit + G6 issue-email + G8 griniaris** — the UX tail (done).
(7)✅ **Έξοδα / Expenses (E0–E7)** — first complete pass, see above.
(8) PEPPOL. Defer stock/ΣΔΕΠ/-333 unless the `.fbk` proves
real usage. `.fbk` usage probes: `docs/go-live-usage-checks.sql.md`.

---

## Known latent items / tech debt (still open — full context in the history doc)
- **Global `BelongsToCompany` scope — ✅ LANDED (no-op mode).** All 22
  tenant-owned models (`Invoice`/`InvoiceLine`/`MyDataMark`/`Product`/
  `PaymentMethod`/`Customer`/`Supplier`/`Expense*`/… — `App\Models\Concerns\
  BelongsToCompany`) carry a `CompanyScope` global scope driven by the ambient
  `App\Support\Tenancy\CompanyContext` (a singleton). Filament sets it on
  `TenantSet`, so raw `Invoice::where(...)` inside the panel (actions/pages/
  widgets) is now auto-filtered — not just Filament's resource queries.
  **Design = no-op when no context:** CLI/queue with no ambient tenant fall
  through to a no-op, so the existing explicit `->where('company_id', ...)`
  paths are unchanged (non-breaking). Opt in to auto-scoping from CLI/jobs with
  `CompanyContext::actAs($company, fn () => ...)`; opt out per query with
  `->withoutGlobalScope(CompanyScope::class)`. **Remaining (deferred):** flip
  the null-context case to THROW (strict mode) once every CLI/queue path uses
  `actAs` — the read scope is read-only (no `company_id` auto-fill on create).
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
  the data layer; no per-tenant role picker yet). **TODO (deferred, larger):**
  a proper UI to pick a user's role PER company (e.g. super_admin in A,
  accountant_readonly in B). Until then: `super_admin` is auto-provisioned per
  tenant (`CompanyObserver` + `App\Services\TenantRoleProvisioner`) and
  back-fillable via `php artisan shield:sync-super-admin` — see the
  "new-tenant super_admin" fix. The picker is the real solution when operators
  need differentiated per-tenant permissions, not just blanket super_admin.
- **ETL re-run preserves soft-delete but refreshes columns** — a row soft-
  deleted in ekdosi gets its legacy values re-applied on re-import (deleted_at
  stays). To truly drop a row across re-imports, force-delete it.
- **Concurrency / row-lock tests** can't run on sqlite (CI); the locks
  (`InvoiceNumberer`, `InvoiceBalance::recompute`) are real on MariaDB only.

## Env-prep gotchas (deploy host)
- **Scheduler + queue worker** — the wired schedule (`routes/console.php`) is
  inert until BOTH are live: one cron line `* * * * * cd /path && php artisan
  schedule:run >> /dev/null 2>&1`, and a running worker
  (`php artisan queue:work` / Horizon / supervisord) for the mail + import
  jobs. Toggle individual tasks via `config/ekdosi.php` env flags
  (`EKDOSI_SCHEDULE_*`). `withoutOverlapping` uses the cache-lock store, so
  the cache driver must work (DB driver needs the `cache` tables migrated).
  After editing `config/ekdosi.php` on a deploy that caches config, run
  `php artisan config:clear`/`optimize`.
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
