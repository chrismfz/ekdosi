# Changelog — ekdosi

Notable changes to the **ekdosi app** (Laravel + Filament). Format:
[Keep a Changelog](https://keepachangelog.com/). The app ships continuously
(no SemVer tag yet), so changes are grouped under `[Unreleased]` and dated as
they merge.

> **The WHMCS-side plugin has its own log:**
> `whmcs-plugin/ekdosi_bridge/CHANGELOG.md`.
> **Deep archive** (dated inspection notes, per-PR review logs):
> `docs/CLAUDE-history.md`. This file starts at 2026-06 — older history lives
> in that archive + git.
>
> **Discipline (from 2026-06 on):** every PR adds a line here under
> `[Unreleased]` (Added / Changed / Fixed / Removed). On a release cut, rename
> `[Unreleased]` to the dated/versioned heading.

## [Unreleased]
### Added
- **Βιβλίο Εσόδων-Εξόδων (απλογραφικά / Β' κατηγορίας)** — new read-only page
  «Βιβλίο Εσόδων-Εξόδων»: a chronological book of the tenant's invoices (έσοδα)
  + expenses (έξοδα), classified by the myDATA category we already store
  (`category1_x`/`category2_x` → Greek label via `Codes::e3CategoryLabel`),
  with period/book/category filters, per-category subtotals and the period
  totals (έσοδα, έξοδα, ΦΠΑ εκροών−εισροών). Pure read-model
  (`App\Services\Accounting\LedgerBook` → `LedgerBookResult`/`LedgerRow`) over
  the existing tables — no new persistence, no money/myDATA path change. Live
  scoping follows `InvoiceScope::live()` (sales) + "not AADE-cancelled"
  (expenses); credit notes are listed with NEGATIVE amounts so period sums are
  net. Admin-gated on `View:LedgerBook` (run `shield:generate` +
  `shield:sync-super-admin` after deploy). Full double-entry (γενική λογιστική)
  stays out — exports feed the accountant's software. `LedgerBookTest` covers
  signed credit notes / scoping / filters / totals.
- **Βιβλίο Εσόδων-Εξόδων — εξαγωγές (CSV / Excel / JSON)**: header «Εξαγωγή»
  group on the page renders the current (filtered) period in three formats via
  `App\Services\Accounting\LedgerBookExporter` — CSV (UTF-8 BOM + ';' + comma
  decimal, el-GR-Excel-friendly) and JSON are dependency-free; the **.xlsx**
  uses the already-present `openspout/openspout` (bold header, raw numeric
  amounts so Excel sums/sorts) — no PhpSpreadsheet/maatwebsite needed. All three
  emit the same table + a totals trailer (έσοδα/έξοδα/ΦΠΑ balance).
  `LedgerBookExporterTest` covers CSV/JSON shape + that the xlsx is a real
  workbook. (The accountant's Union import format — Phase C — still TBD.)
- **myDATA — «Άντληση/έλεγχος από ΑΑΔΕ» on ΜΑΡΚ detail**: for a local invoice
  imported with a MARK but no AADE QR (Epsilon/legacy), a live pull by MARK
  (`RequestTransmittedDocs`) now stamps the QR (`qrCodeUrl`) onto
  `invoices.mydata_url` (+ `mydata_marks.invoice_url`) so our reprinted PDF
  shows MARK **and** QR. The same call drives a field-by-field **comparison**
  popup/panel — what agrees (✓) and what differs (⚠) vs AADE. Policy: QR always
  (re)written, everything else **fill-blanks only** (never overwrites a
  populated value on a filed doc), differences reported not auto-applied
  (`App\Services\MyData\EnrichInvoiceFromAade`, `App\Support\MyData\QrImage`,
  `MarkDetail`/`TransmittedDocReader` now carry `qrCodeUrl`). Gated on
  `View:MyDataConsole` (live AADE call). ORPHAN→create-local still deferred.
- **Data Import — Epsilon Smart Sales → invoices** (Phase 2): the «Epsilon
  Smart (JSON)» tab gains a Πωλήσεις (`DataExport-Sales.json`) slot. Each Epsilon
  sale lands as a historical, already-filed invoice — `active` + `mydata_state=
  VALID` + the MARK (leading apostrophe stripped) + a `mydata_marks` audit row
  (`mydata_action=INSERT`, so cancel/credit-note correlation finds it). Lines,
  invoice header and the MARK are written via raw query-builder inserts (mirrors
  the Firebird ETL) so the FILED net/gross values are kept verbatim — the
  `InvoiceLine` recompute hook is bypassed (it would drift by rounding and throw
  on zero-qty lines). Counterpart resolved by ΑΦΜ (resolve-or-create), product
  matched by name, the Epsilon DocNum kept as the ΑΑ, the invoice-type counter
  bumped so new ekdosi invoices continue. **Settled on import:** credit-term
  («Επί Πιστώσει») sales get a full settlement Payment dated at issue so the
  already-paid historical docs aren't phantom receivables; cash-term settle at
  issue via `InvoiceBalance`. Re-runnable by `(company_id, invcode)`; a re-run
  refreshes the header and replaces lines + mark + the settlement payment
  (`EpsilonImporter::importSales`). Known limitations: no AADE QR on the PDF
  (Epsilon exports the UID but not the QR URL); per-line E3 classification not
  stored (derived at submit, as elsewhere); non-GR counterpart defaults to GR.
### Added
- **Data Import — Epsilon Smart (JSON)** (Phase 1): the «Firebird Import» screen
  is renamed «Data Import» and gains a 2nd tab. The Firebird flow is unchanged
  (its own tab); the new «Epsilon Smart (JSON)» tab imports the Τιμολόγηση
  exports — **Customers** (match by ΑΦΜ) and **Items/Services → products** (VAT
  from the Epsilon class, unit, category; WhosalePrice as the net sell price).
  Re-runnable upsert by natural key; resolves against the standard AADE lookups
  the seeder installs (`App\Services\Etl\EpsilonImporter`). Runs synchronously
  (the exports are tiny). Sales→invoices is a planned Phase 2.

## 2026-06-02
### Added
- **Fresh-install seeding** (PR #152): a new Greek/myDATA tenant auto-installs
  the standard AADE lookups — VAT categories (§8.2) + invoice types pre-classified
  by-the-book (mydata_type + E3 income class + category) + payment methods (§8.12),
  distribution aims, metric units, delivery methods, product categories. Idempotent
  «Εισαγωγή τυπικών» buttons on each Setup list; runs on UI Create-Company +
  `db:seed`.
- **Tags** (PR #150): tenant-scoped tags on customers / suppliers / products /
  invoices / expenses — multi-select filter + Έξοδα-style fast-filter tabs (count
  badges) + bulk attach; managed via a `TagResource`.
- **Favourites-first pickers** (PR #150): `is_favorite` ⭐ on invoice types /
  customers / products; the invoice + quote pickers show favourites then most-used
  on open. Inline product create, Είδος→dimensions auto-fill, «Νέο Παραστατικό»
  from the customer Καρτέλα, full Greek labels on the invoice form.
### Changed
- ETL `copyLookup` now ADOPTS a pre-seeded lookup row by its natural key (stamps
  its legacy_id) so a re-import converges on one row instead of duplicating the
  seeded «Μετρητά» / «24%» / «ΤΕΜ» (PR #152).
### Removed
- Inert legacy `mailed` / `printed` / `email_sent` flags on invoices (PR #151).

## History
Earlier changes (tenancy/auth, customers + Καρτέλα, invoices + VAT math + QR/PDF,
myDATA submit/cancel/reconcile, payments + credit notes, Έξοδα/Ε3, Quotes, VIES,
roles, activitylog, the WHMCS bridge A–B3 + timologia v2) predate this file —
see `docs/CLAUDE-history.md`, `docs/Comparison.md`, and git history.
