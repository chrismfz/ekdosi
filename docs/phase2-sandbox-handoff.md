# Phase 2 — myDATA sales reconciliation: local sandbox verification

Hand-off brief for a **local** Claude Code session (the cloud sandbox has
no AADE network or credentials, so this must run on a box that can reach
the AADE **dev** endpoint). Goal: prove that `SalesReconciler` parses real
`RequestTransmittedDocs` responses correctly, and fix the field mapping if
AADE's live XML differs from what our MockHandler tests assume.

## Why this step exists
Everything in Phase 2 is unit-tested via a Guzzle `MockHandler` feeding
canned XML, and every firebed getter was verified against vendor source —
but **AADE's live XML is the ground truth**. The first real call is the
only thing that confirms the wire shape. The `mydata:reconcile-sales` path
is **read-only** (`RequestTransmittedDocs`), so it's safe to run as often
as needed.

## Prerequisites
- A local clone with `composer install` done. (You do NOT need
  `pdo_firebird` or `ext-soap` for this — those are ETL / GSIS only.
  If `composer install` complains about `ext-soap`, add
  `--ignore-platform-req=ext-soap`.)
- A MariaDB (or sqlite) with the migrations run and **at least one
  Company tenant**. Reconcile works even with zero local invoices — that
  case is itself a valid wire-shape test (everything AADE returns lands in
  the `missingLocally` bucket).
- AADE **sandbox/dev** credentials (`aade-user-id` + subscription key).

## Step 1 — Configure a sandbox tenant
Credentials are secrets. They live in the **encrypted** column
`companies.mydata_subscription_key`. Do NOT put them in `.env`, in git, or
in a prompt that gets committed.

```bash
php artisan mydata:set-credentials --tenant=<slug> --test
# prompts for the AADE user id and the subscription key (hidden input),
# sets einvoice_provider=gr-mydata + mydata_mode=sandbox, then verifies
# the creds against the AADE dev endpoint.
```
`--test` calls `MyDataSubmitter::testConnection()` (a tiny
`RequestTransmittedDocs` probe). A green "✓ Credentials accepted" means
auth + connectivity + the dev endpoint are all good.

## Step 2 — First smoke test (read-only)
```bash
php artisan mydata:reconcile-sales --tenant=<slug> --from=2026-01-01 --to=2026-05-31
```
Exit codes: **0** = clean, **2** = discrepancies found, **1** = error.
- If the sandbox account has never filed anything in the window, you'll get
  `AADE total: 0` and a clean result — that exercises the empty-window path
  (which previously crashed; it's guarded now).
- If it has filed docs, you'll see the bucket table (matched / state
  mismatch / missing at AADE / missing locally / duplicate local MARK).

## Step 3 — If something looks wrong, dump the raw XML
```bash
php artisan mydata:reconcile-sales --tenant=<slug> --from=2026-01-01 --to=2026-05-31 --raw
```
This prints the **raw `RequestTransmittedDocs` response XML** per page
(via firebed's `getResponseXML()`), bypassing the diff. Compare the real
element names / nesting against what the parser reads.

## Where the mapping lives (what to fix)
- `app/Services/MyData/SalesReconciler.php`
  - `fetchAadeDocs()` — reads each invoice via firebed getters
    (`getMark`, `getUid`, `getCancelledByMark`, `getInvoiceHeader()->getSeries/getAa/getIssueDate`,
    `getInvoiceSummary()->getTotalGrossValue`, `getCounterpart()->getName/getVatNumber`),
    folds cancellations (inline `cancelledByMark` + the `cancelledInvoicesDoc`
    list), and follows the `continuationToken` pagination.
  - `diff()` — pure bucketing logic (unit-tested; unlikely to need changes).
- `app/Services/MyData/AadeDocSummary.php` — the flattened per-doc shape.
- Tests to keep green / extend: `tests/Feature/SalesReconcilerFetchTest.php`
  (canned XML through MockHandler) and `SalesReconcilerDiffTest.php`.

### Likely "surprises" to check against the raw XML
1. **Element nesting** — confirm `<invoicesDoc><invoice>…`,
   `<cancelledInvoicesDoc><cancelledInvoice>`, `<continuationToken>` with
   `<nextPartitionKey>`/`<nextRowKey>` match. The reader is namespace-agnostic
   (uses `localName`), so default vs prefixed namespaces don't matter.
2. **Empty containers** — AADE may return an empty `<invoicesDoc/>`; firebed
   parses that to a *string* and the typed `getInvoices()` getter throws, so
   the code reads the raw attribute via `Type::get()` + `is_iterable()`.
   Confirm this still holds against the real empty response.
3. **Pagination** — verify the continuation token actually appears when the
   result set is large, and that both keys come together. The loop ORs the
   keys defensively.
4. **`totalGrossValue`** — firebed returns it as a string; we cast to float.
   Confirm the decimal format (`.` vs `,`).
5. **Cancellations** — confirm whether AADE signals a cancelled doc via the
   inline `<cancelledByMark>` on the invoice, the standalone
   `<cancelledInvoicesDoc>` list, or both. We fold both.

## Reporting back
Capture and report: the exit code, the bucket counts, and — if anything
diverged — the raw XML for the divergent doc plus the mapping change made.
If a real mismatch is found, add a regression test to
`SalesReconcilerFetchTest` using the captured XML shape before fixing.

## Safety notes
- `mydata:reconcile-sales` and `--raw` are **read-only** — safe to repeat.
- `mydata:test-submit <invoiceId> --execute` actually **files** to the
  sandbox (creates test MARKs). Useful to create a real "matched" row, but
  be deliberate. Default is dry-run.
- Keep `mydata_mode=sandbox` until the wire shape is proven; only switch a
  tenant to `production` when you mean it (the credentials command and the
  Filament form both gate that switch).
