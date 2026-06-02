# AADE MARK enrich + cross-check

> Status: **LOCAL enrich + QR + compare = BUILT** (after the Epsilon import #161
> merged). The ORPHAN→create-local importer is the remaining deferred piece.

## Built
- `MarkDetail::fromAadeDoc()`/`fromInvoice()` now carry `qrCodeUrl`;
  `TransmittedDocReader` returns it (locked by `TransmittedDocReaderTest`).
- `App\Support\MyData\QrImage` — shared QR PNG renderer (PDF + page reuse it).
- `App\Services\MyData\EnrichInvoiceFromAade` — QR (re)stamped onto
  `invoices.mydata_url` + the **INSERT** `mydata_marks.invoice_url` (skipped when
  the AADE doc is CANCELLED); **fill-blanks of HEADER fields only**
  (`company_name`/`vat_no`/`mydata_type` — NOT `mydata_state`, a critical
  orthogonal status); returns a field-by-field `comparison` (✓/⚠) computed from
  a **pre-fill snapshot** (so a just-filled field isn't a trivial match, and a
  blank field isn't a false ⚠) with whitespace-normalised invcode. **Line / E3
  backfill is NOT done** — the «Πλήθος γραμμών» row is informational only (see
  deferred).
- `MyDataMarkDetail` page: «Άντληση/έλεγχος από ΑΑΔΕ» action (local invoice +
  `View:MyDataConsole`) → live pull by MARK → enrich → toast + on-page
  comparison panel. QR now renders on the page; the invoice PDF carries it.
- Tests: `EnrichInvoiceFromAadeTest` (stamp / fill-blanks / never-overwrite +
  flag diff / money mismatch), `TransmittedDocReaderTest` (qrCodeUrl capture).

## Still deferred — ORPHAN → create-local (`SalesOrphanImporter`)
When the MARK has **no** local record, the page's `import_local` action is still
a placeholder. Build it to create the local invoice from the AADE doc (reuse the
Epsilon `importSales` raw-insert pattern: verbatim filed values,
`mydata_action='INSERT'`, settle-on-import). `MarkDetail::fromAadeDoc()` is
exactly the parse it needs.

**Line / E3 backfill (also deferred).** Enrich currently fills header fields
only. When a local invoice has fewer/zero lines than AADE, the «Πλήθος γραμμών»
comparison row flags it but nothing fixes it. A follow-up could backfill lines
(net/vat/descr/E3) from `$aade['lines']` when local has none — the AADE doc
already carries item code + descr + ΦΠΑ% + E3 classification per line.

---

## Why it's cheap — the infrastructure already exists

## The need
After cutover (Epsilon/legacy closed, ekdosi live) an operator is asked for an
**old invoice** that was imported with a MARK but **without the AADE QR** (Epsilon
exports the UID + MARK, never the QR URL; some legacy rows lack it too). We want
to reprint **our own** PDF carrying **MARK + QR**, and — since the same AADE call
returns the full official record — flag **what's missing/differs locally vs AADE**.

## Why it's cheap — the infrastructure already exists
- **The QR URL is retrievable from AADE**, keyed by MARK. `RequestTransmittedDocs`
  (and `RequestDocs`) responses carry `<qrCodeUrl>https://mydatapi.aade.gr/myDATA/
  TimologioQR/QRInfo?q=…</qrCodeUrl>` per `<invoice>` (see committed
  `requestdocs-sample.xml`). It is the same URL AADE returns on submission as
  `qrUrl`. firebed parses it: **`Invoice::getQrCodeUrl()`**.
- **The live lookup already runs.** `App\Filament\Pages\MyDataMarkDetail` resolves
  a MARK to LOCAL (`invoices.mydata_mark`) or ORPHAN (live AADE via the reconciler
  path) and renders `App\Support\MyData\MarkDetail::fromAadeDoc()` — which already
  extracts type / series / aa / dates / counterpart / issuer / totals / **per-line
  item code + descr + ΦΠΑ% + E3 income classification** (`InvoiceDetails::
  getVatCategory/getVatAmount/getIncomeClassification`) + response XML.
- **The page already has the placeholder hook.** `MyDataMarkDetail` ships an
  `import_local` action that today only shows an explanatory modal ("orphans aren't
  silently importable"); `MarkDetail` comments it as "the parse a future
  **SalesOrphanImporter** would use".
- **The PDF already renders a QR from a URL** (`InvoicePdfRenderer`, gated on
  `invoices.mydata_url`).

**The only data gap:** `MarkDetail::fromAadeDoc()` does **not** yet keep
`$doc->getQrCodeUrl()`. One line.

## Decision (locked)
**Re-import onto an EXISTING local invoice = QR + fill-blanks only.** Always write
`qrCodeUrl`; otherwise fill **only** locally-empty fields/lines. **Never overwrite
an already-populated value** (filed/legally-frozen safe). The "overwrite from AADE"
and "full rebuild" variants were considered and rejected as the default.

## Build plan (3 small pieces, on existing surfaces)
1. **Capture the URL.** Add `'qrCodeUrl' => $doc->getQrCodeUrl()` to
   `MarkDetail::fromAadeDoc()`; in `fromInvoice()` surface `invoices.mydata_url`.
   Also add `qrCodeUrl` to `SalesReconciler`'s `AadeDocSummary` (so bulk flows see it).
2. **Show + print.** Render the QR on the MyDataMarkDetail Blade (from the URL) and
   add a print/PDF header action.
3. **Enrich + cross-check service** (`EnrichInvoiceFromAade` / wire `import_local`):
   - **LOCAL invoice exists** (the common imported case): stamp `qrCodeUrl` →
     `invoices.mydata_url` (+ `mydata_marks.invoice_url`); fill-blanks of HEADER
     fields. **Cross-check report**: list every field where local ≠ AADE (read-only
     worklist; don't auto-change populated values). → our PDF now prints **MARK +
     QR**. (Line/E3 backfill when local has none is a follow-up — see deferred.)
   - **ORPHAN** (no local record): the real `SalesOrphanImporter` — create the local
     invoice from the AADE doc (reuse the Epsilon `importSales` raw-insert pattern:
     verbatim filed values, `mydata_action='INSERT'`, settle-on-import).
   - Works for **every** import path (Epsilon + legacy Firebird).

## Tests to add
- `MarkDetail::fromAadeDoc()` returns `qrCodeUrl` from a fixture response.
- Enrich on a local invoice with null `mydata_url` → stamped; populated fields
  untouched (fill-blanks); cross-check flags a deliberately-divergent line.
- Orphan import → local invoice created (verbatim totals, INSERT mark, settled).
