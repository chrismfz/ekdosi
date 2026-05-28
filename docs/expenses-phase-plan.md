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
- **sync**: when `RequestDocs` returns a doc whose issuer AFM we don't have,
  auto-create a `supplier` (`source=sync`) — the "αδέσποτος προμηθευτής" case,
  directly analogous to the issuer-side `missingLocally`.
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

## Phased TODO (incremental, each shippable)
- [ ] **E0 — sandbox spike**: call `RequestDocs` + `RequestVatInfo` against the
      AADE sandbox; capture sample XML (like `mydata-sandbox-validation`). Decide
      header-only vs per-line from real payloads.
- [ ] **E1 — data model**: migrations for `suppliers`, `expenses`,
      (`expense_lines`?), `expense_marks`; models; tenant scoping; Shield perms.
- [ ] **E2 — Suppliers resource**: CRUD + "Άντληση από ΑΑΔΕ" (reuse
      `AadeRegistryLookup`); `source` provenance.
- [ ] **E3 — ExpenseReconciler** over `RequestDocs` (mirror `SalesReconciler`;
      reuse pagination/continuationToken handling).
- [ ] **E4 — Κονσόλα myDATA / Έξοδα** page (mirror inbound console; reuse the
      shared table partial) + **import-expense** action (auto-creating the
      supplier on sync).
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
- **Header-only vs per-line** expenses — depends on what `RequestDocs` returns
  for typical supplier docs; per-line is needed only if we classify per-line.
- **Auto-create vs review** suppliers on sync — default to auto-create
  `source=sync` but flag for operator review (mirrors the inbox model; expenses
  affect VAT, so a review gate is the safer default).
- **Classification scope** — do we classify in ekdosi (`SendExpensesClassification`)
  or leave it to the accountant's software? Affects E5 priority.
- **Counterpart privacy** — `RequestDocs` exposes supplier AFMs; confirm tenant
  scoping + the no-global-scope caveat (see `CLAUDE.md` latent items).
