# Έξοδα / Expenses phase — design note & TODO

> Status: **planned, not started.** Blueprint only — no code yet.
> Companion to the `🆕 NEW phases` entry in `CLAUDE.md`.

## Why this phase exists
Today ekdosi only knows the **sales / issuer** side: invoices we issue
(`RequestTransmittedDocs`), output VAT (ΦΠΑ εκροών). To answer *"πόσο ΦΠΑ θα
χρωστάμε αυτόν τον μήνα/τρίμηνο;"* we need the other half — the **expenses /
purchases** side: invoices suppliers issued **to us**, and the input VAT
(ΦΠΑ εισροών) we can deduct. Net VAT = **εκροών − εισροών**. Same for the Ε3
income/expense picture.

The goal is a mirror of the sales architecture: **sync expense docs from myDATA →
suppliers → an Expenses console (the twin of the issuer-side "Αδέσποτα από
myDATA") → a ΦΠΑ εκροών−εισροών report**, so an operator always sees where they
stand for the period.

User's mental model (confirmed correct):
`συγχρονισμός → παραστατικά εξόδων → προμηθευτές (sync/import/manual; manual ⇒
fetch στοιχεία από ΑΑΔΕ) → τρόπος να τα βλέπουμε + ΦΠΑ`.

## Legacy check — nothing to port (greenfield)
Verified against `legacy/ekdosi-schema.sql` + `legacy/ekdosi-main/`:
- **No** SUPPLIER / VENDOR / EXPENSE / PURCHASE tables. Only sales-side tables
  (CUSTOMER, INVOICE, INVLINES, MARK, PAYMENT, PRODUCT) + `VAT_CATEGORY` (rate
  lookup, already ported) + `REPORTS`/`REPORT_INPUT_DATA` (FastReport params).
- **No** inbound myDATA, no `RequestDocs`/`RequestVatInfo`, no input-VAT report.
  `FShowMyDataRemainingInvoices` is the *sales* submission queue only.
- The lost `CMyData.cpp` was issuer-only too.

⇒ The whole phase is **net-new**. We don't port — we build by mirroring the
sales services we already have.

## AADE / myDATA toolbox (firebed already ships all of it)
All are read-GETs except the classification POST. Confirmed present in
`vendor/firebed/aade-mydata/src/Http/` + `…/Models/`, so transport is done — we
only wrap + map, exactly like `MyDataSubmitter`/`SalesReconciler` do today.

| Method (spec §) | What it returns | Why / how we use it |
|---|---|---|
| **`RequestDocs`** (§4.2.6) | Παραστατικά/χαρακτηρισμοί/ακυρώσεις **που υπέβαλαν ΑΛΛΟΙ και μας αφορούν** (supplier→us). Filters: `dateFrom/dateTo/counterVatNumber/invType`, paginated via `nextPartitionKey/nextRowKey`. | The core expense feed. Exact reverse of `RequestTransmittedDocs`. Build `ExpenseReconciler` mirroring `SalesReconciler` (same pagination, same diff buckets). |
| **`RequestMyExpenses`** (§4.2.9) | Aggregated expense info for a period (twin of `RequestMyIncome`). | Quick period totals / sanity cross-check vs the per-doc sum. |
| **`RequestVatInfo`** (§4.2.10) | **ΦΠΑ εισροών–εκροών**, per-invoice or `GroupedPerDay`. | **The direct lever** for "ΦΠΑ ανά μήνα/τρίμηνο". Compute locally from our docs, then reconcile against this authoritative figure. |
| **`RequestE3Info`** (§4.2.11) | Ε3 figures per period (per-doc or per-day). | Feeds an Ε3 overview; cross-check our classification. |
| **`SendExpensesClassification`** (§4.2.3) | POST — classify expense docs (`postPerInvoice` = per-document vs per-line). | Needed to "close" each expense at AADE (assign E3/VAT category). Mirror of `SendIncomeClassification`. |

## Sample findings — real `RequestDocs` payload (nexon, 2026-01…03)
Captured via `php artisan mydata:fetch-docs --tenant=nexon … --raw` (E0 done).
These lock several open questions:

- **`RequestDocs` returns the FULL document**, not a summary: `<issuer>`,
  `<counterpart>`, `<invoiceHeader>` (series/aa/issueDate/invoiceType/currency
  + delivery-note fields), **one or more `<invoiceDetails>` lines**, and
  `<invoiceSummary>` totals — plus `<uid>`, `<mark>`, `<authenticationCode>`,
  `<qrCodeUrl>`, sometimes `<downloadingInvoiceUrl>` (external PDF).
  ⇒ **DECISION: go per-line** (`expense_lines`) — the data is there and we'll
  need it for classification. Not header-only.
- **firebed parses all of it** with typed accessors — no hand-parsing:
  `RequestedDoc->getInvoices()` → `Invoice` with `getIssuer()/getCounterpart()/
  getInvoiceHeader()/getInvoiceDetails(): array/getInvoiceSummary()/getMark()/
  getUid()`; line fields `getLineNumber/getNetValue/getVatCategory/getVatAmount/
  getQuantity/getItemCode/getItemDescr`; summary `getTotalNetValue/
  getTotalVatAmount/getTotalGrossValue`.
- **Issuer (supplier) name/address is INCONSISTENT**: some docs carry full
  `<name>`+`<address>` (ΑΛΦΑΝΕΤ, Electrosystems, MG MANAGER), others give **only
  the AFM** (e.g. `099357493`, `802438394`). ⇒ confirms the supplier `name` must
  be nullable **and** GSIS-enriched — exactly the E2 design. When the doc carries
  a name, prefer it; otherwise fall back to `AadeRegistryLookup`.
- **Same supplier AFM recurs** across docs, sometimes with name, sometimes
  without (`998482379`×2, `802438394`×2). ⇒ the sync MUST **upsert the supplier on
  `(company_id, afm)`**, not create one per document.
- **`<counterpart>` is always us** (`801280908` = nexon). ⇒ sanity check: a doc
  whose counterpart ≠ our AFM is not our expense — skip/flag.
- **Per-line fields seen**: `lineNumber`, optional `itemCode`/`itemDescr`,
  `quantity`, `measurementUnit`, `netValue`, `vatCategory` (1 = 24%), `vatAmount`,
  `discountOption`. Multi-line docs exist (mark `400012434052750` has 2 lines).
- **Invoice types seen**: 1.1 (πώλησης / ΔΑΤ-ΤΔΑ delivery notes), 2.1 (παροχή
  υπηρεσιών). Delivery-note docs carry `isDeliveryNote`/`movePurpose`/
  `otherDeliveryNoteHeader` and sometimes `otherCorrelatedEntities` (a third
  party, e.g. ΚΙΦΑ) — keep the header fields we need, ignore the rest for v1.
- **Cancellations**: the response also has a `<cancelledInvoicesDoc>` branch
  (firebed `getCancelledInvoices()`) — fold it like the sales reconciler folds
  cancellations.

### Larger sample (full 24-doc dump, committed `requestdocs-sample.xml`)
- **Invoice types**: 1.1 ×17, 2.1 ×6, **9.3 ×1** (self-accounting entry; still
  carries our counterpart AFM). All docs in this window have a counterpart.
- **🔑 Zero-VAT comes in TWO distinct shapes — both must be accepted as-is:**
  - **vatCategory 7 + `vatExemptionCategory` (4 lines; reasons 7 and 16).**
    Reason **16 = reverse-charge άρθ. 39α** (κινητά/tablets/laptops/κονσόλες —
    this is the phone/tablet 0% case): netValue normal, `vatAmount=0`, exemption
    reason present.
  - **vatCategory 8 with `netValue=0` + `vatAmount=0` and NO exemption** (1 line;
    a zero-value replacement UPS). Legit on the expense side.
  ⇒ the expense parser must store `vatCategory` + optional `vatExemptionCategory`
  verbatim and treat input-VAT as 0 for both — do NOT reuse the sales-side rule
  (`vatCategoryFor(0)` throws without a reason, G4).
- **No `<continuationToken>`** in this window → single page (confirms last-page
  shape; pagination still needed for big windows).
- **No embedded income/expense classifications** → docs arrive UN-classified;
  classifying them is our job (E5 `SendExpensesClassification`).
- **No cancellations** in this window (branch exists; empty here).

## Proposed data model (mirror the sales side)
- **`suppliers`** (προμηθευτές) — twin of `customers`. `company_id`, `afm`,
  `name`, `tax_office`, address, `legacy_id` n/a (net-new). Sources:
  `sync` (auto-created from a `RequestDocs` issuer AFM), `import`, `manual`.
  A `source` enum column records provenance.
  - **Manual-created ⇒ enrich from ΑΑΔΕ**: reuse the existing
    `AadeRegistryLookup` (GSIS, already built for customers) to fill
    name/ΔΟΥ/address from the AFM. Same "Διασταύρωση ΑΦΜ" UX as the customer
    Καρτέλα.
- **`expenses`** — twin of `invoices` (the doc header): `company_id`,
  `supplier_id`, `mydata_mark`, `issue_date`, `invoice_type`, `net_total`,
  `vat_total`, `gross_total`, `mydata_state`, `classification_state`,
  `source` (sync/manual). Money decimals as per house rules.
- **`expense_lines`** — twin of `invoice_lines` (net/vat/category per line),
  if we go per-line; may start header-only from `RequestDocs` summaries.
- **`expense_marks`** — twin of `mydata_marks`: full request/response XML of
  each `RequestDocs`/classification call (legal audit, source of truth).
- Reuse `VatCategory` + the §8 `Codes` tables for expense classification
  (E3_* codes, VAT categories, expense classification categories §8.x).

## Suppliers — sync / import / manual
- **sync**: ✅ **DONE** (ahead of E3, standalone). `App\Services\MyData\
  SupplierSyncFromMyData` scans `RequestDocs` over a window, extracts the
  UNIQUE issuer AFMs, and upserts a `supplier` (`source=sync`) for each one we
  don't have — the "αδέσποτος προμηθευτής" case. Name resolution: doc name →
  GSIS (`AadeRegistryLookup`, GR only) → AFM-only. Skips our own AFM
  (self-billing/9.3) and never resurrects a soft-deleted supplier. Tenant-
  scoped explicitly by `company_id` (no Filament context on the CLI path).
  Triggers: `php artisan suppliers:sync --tenant= [--from --to --no-enrich]`
  AND a "Συγχρονισμός από myDATA" header action on the Suppliers list
  (GR-tenant-only). Mirrors `SalesReconciler`'s pagination + empty-window
  TypeError guard; covered by `SupplierSyncFromMyDataTest` (MockHandler).
  Reusable building block for E3.
- **import**: bulk (ETL / CSV) — later, low priority.
- **manual**: operator adds a supplier by AFM → one click "Άντληση από ΑΑΔΕ"
  fills the rest via `AadeRegistryLookup`. (Already-built service; just point it
  at the supplier form.)

## ExpenseReconciler + "Κονσόλα myDATA — Έξοδα" page
- **`ExpenseReconciler`** (service) — copy `SalesReconciler` but over
  `RequestDocs`. Same buckets, mirrored meaning:
  - `matched` — expense doc at AADE linked to a local `expense`.
  - `missingLocally` — **at AADE, not in ekdosi** (a supplier filed against us
    and we haven't recorded it) → the actionable "import this expense".
  - `missingAtAade` — local expense with a MARK AADE doesn't return.
  - `stateMismatch` / `duplicateLocal` — as in sales.
- **`MyDataConsoleExpenses`** Filament page — twin of the issuer console, reuse
  the shared `partials/reconciliation-table.blade.php`. Same two-direction
  framing the sales console now has. An action to **import** a `missingLocally`
  expense into a local `expense` row (create from the AADE doc) is the expense
  analog of issuing — operator-gated (legally significant).

## ΦΠΑ report + Ε3 (the "where do we stand" view)
- **`VatPeriodReport`** — per month + per quarter:
  `output VAT (εκροών, from our invoices) − input VAT (εισροών, from expenses)
  = net VAT payable`. Compute locally (we already have output side via
  `InvoiceVatBreakdown`; add the input side from `expenses`).
- **Cross-check** the local figure against **`RequestVatInfo`** (authoritative).
  Surface drift like the reconciliation worklists do — never silently trust one
  side.
- **Ε3 overview** from `RequestE3Info` + our classification.

### 🆕 Dashboard widget — «Εικόνα από myDATA» (operator-requested, E6/E7)
A glanceable panel on the main dashboard so the operator ALWAYS knows their
running VAT position toward the εφορία — no need to open a report:
- **Έσοδα** (gross) + **ΦΠΑ εκροών** — from our issued invoices (output side,
  `InvoiceVatBreakdown`, live-scope only).
- **Έξοδα** (gross) + **ΦΠΑ εισροών** — from local `expenses` (input side).
- **Καθαρό ΦΠΑ = εκροών − εισροών** = "τι έχω να δώσω στο κράτος" — the
  headline number, shown **per current quarter** AND **per month**.
- Framing the operator asked for: «έκοψα τόσα παραστατικά / τόσο ΦΠΑ, μου
  έκοψαν τόσα, γλίτωσα τόσο ΦΠΑ (εισροών), σύνολο οφειλή τόσα».
- Period selector (τρέχων μήνας / τρίμηνο), with the AADE cross-check
  (`RequestVatInfo`) surfaced as a drift badge once E6 lands — never present a
  single number as gospel.
- Built on `VatPeriodReport` (E6); the widget is the dashboard face of it.
  Gate to gr-mydata tenants. **Do not forget — explicit operator ask.**

## Phased TODO (incremental, each shippable)
- [x] **E0 — sandbox spike**: ✅ done. Command shipped + run on `nexon`; real
      `RequestDocs` XML captured. Outcome: **per-line** model (see Sample findings).
- [x] **E1 — data model**: ✅ `suppliers` table + `Supplier` model (+ `SupplierSource`
      enum). ✅ `expenses` + **`expense_lines`** + `expense_marks` tables + models
      (`Expense`/`ExpenseLine`/`ExpenseMark`) + `ExpenseSource` enum. Mirrors the
      sales-side `invoices`/`invoice_lines`/`mydata_marks` (house decimals, myDATA
      state cache vs. byte-exact mark audit, soft-deletes, `(company_id, mydata_mark)`
      unique with NULLs allowed). 0%/exempt line shapes (§8.2 vat_category + §8.3
      vat_exemption_category) stored VERBATIM — not re-derived. Covered by
      `ExpenseModelTest` (migrate, relations/casts, cascade, unique). Shield perms
      land with the Filament resource (E4).
- [x] **E2 — Suppliers resource**: ✅ CRUD + "Άντληση από ΑΑΔΕ" (reuses
      `AadeRegistryLookup`) + `source` provenance + tenant-scoped list/table.
- [x] **E2.5 — Supplier sync from myDATA**: ✅ `SupplierSyncFromMyData` +
      `suppliers:sync` command + "Συγχρονισμός από myDATA" list action.
      Scans `RequestDocs` → unique issuer AFMs → upsert `source=sync`
      suppliers (doc name / GSIS / AFM-only). The bulk twin of E2's per-AFM
      button; reusable by E3. Tested via MockHandler.
- [x] **E3 — ExpenseReconciler** over `RequestDocs`: ✅ `App\Services\MyData\
      ExpenseReconciler` mirrors `SalesReconciler` (same pagination /
      continuationToken / empty-window TypeError guard / cancellation folding),
      diffs AADE expense docs vs local `expenses` into the five buckets
      (`ExpenseReconciliationResult`): matched / stateMismatch / missingAtAade /
      **missingLocally** (the actionable "καταχώριση εξόδου") / duplicateLocal.
      The relevant party is the ISSUER (supplier) — folded into the shared
      `AadeDocSummary`/`ReconciliationRow` counterpart* fields; `ReconciliationRow`
      gained an optional `expenseId` so the one worklist row/partial serves both
      sides. Pure `diff()` + a `rawDocs()` diagnostic, like the sales side.
      Covered by `ExpenseReconcilerTest` (MockHandler: pagination, issuer map,
      empty window, all five buckets). The E4 console page + import-expense
      action consume this.
- [x] **E4 — Κονσόλα myDATA / Έξοδα**: ✅ `MyDataConsoleExpenses` page mirrors
      `MyDataConsole` (two directions, reuses the shared `reconciliation-table`
      partial — gained a `supplier` column). **Import**: `ExpenseImporter`
      re-fetches the FULL `RequestDocs` docs (the reconciler only keeps
      summaries) and records each αδέσποτο as a local `Expense` + `expense_lines`
      (§8.2/§8.3 codes verbatim) + audit `expense_mark`, linking/creating the
      `Supplier` by issuer AFM. Idempotent (skips/never-resurrects an existing
      MARK), one transaction per doc, tenant-scoped, operator-gated (a confirmed
      "Καταχώριση αδέσποτων εξόδων" header action over the queried window). A
      read-only `ExpenseResource` (list + view + lines) gives imported expenses a
      home and powers the worklist "Άνοιγμα" link (`rowToArray` handles
      `expenseId`). Tests: `ExpenseImporterTest` (lines/supplier/exemption/audit/
      idempotency/only-mark) + `MyDataConsoleExpensesTest` (directions, orphan
      render, import gating, canAccess). Full suite 422.
- [ ] **E5 — Expense classification** via `SendExpensesClassification`
      (mirror `SendIncomeClassification`; §8 code tables).
- [ ] **E6 — ΦΠΑ εκροών−εισροών report** (month/quarter) + cross-check vs
      `RequestVatInfo`.
- [ ] **E7 — Ε3 overview** via `RequestE3Info`.

## Reuse map (don't reinvent)
| Need | Existing thing to mirror/reuse |
|---|---|
| myDATA transport | `firebed/aade-mydata` (all expense classes present) |
| Submit/map pattern | `App\Services\MyDataSubmitter` |
| Live reconciliation | `App\Services\MyData\SalesReconciler` + `…\ReconciliationRow` + `…\SalesReconciliationResult` |
| Console page + table | `App\Filament\Pages\MyDataConsole` + `partials/reconciliation-table.blade.php` |
| AFM → identity | `App\Services\AadeRegistryLookup` (GSIS, already live) |
| VAT breakdown (output) | `App\Services\InvoiceVatBreakdown` |
| Code tables (§8) | `App\Support\MyData\Codes` |

## Open decisions (resolve during E0/E1)
- **Header-only vs per-line** expenses — ✅ RESOLVED (E0): **per-line**.
  `RequestDocs` returns full `<invoiceDetails>` lines (multi-line docs exist),
  so `expense_lines` is built from real data, not guessed.
- **Auto-create vs review** suppliers on sync — default to auto-create
  `source=sync` but flag for operator review (mirrors the inbox model; expenses
  affect VAT, so a review gate is the safer default).
- **Classification scope** — do we classify in ekdosi (`SendExpensesClassification`)
  or leave it to the accountant's software? Affects E5 priority.
- **Counterpart privacy** — `RequestDocs` exposes supplier AFMs; confirm tenant
  scoping + the no-global-scope caveat (see `CLAUDE.md` latent items).
