# myDATA sandbox experiment — MYD-2 retry-after-timeout (2026-07-07)

**Question (AUDIT MYD-2, ανοιχτό σκέλος α+γ):** when a `SendInvoices` transport
times out, `MyDataSubmitter::submit()` leaves `mydata_state=null`, so a retry
re-POSTs the **same** `(series, ΑΑ)`. The code ASSUMED AADE dedups a resubmission
server-side via its own `<uid>` (the `AadeInvoiceDocument` "NO `<uid>` — `[273]`"
comment). But `[233]` («Αφορά μόνο τους παρόχους») does **not** document that for
the ERP channel. **Does AADE dedup, or does a blind retry create a second MARK
(= doubly-declared income)?**

## Method

Throwaway sandbox tenant `sbx` (`companies.mydata_mode='sandbox'`, issuer AFM
`800561849` — the AADE sandbox test AFM, reusing `myip`'s sandbox subscription
key). One throwaway invoice, type **2.1** (ΤΠΥ), 1 line, 24% ΦΠΑ, net €100 /
gross €124. Filed against the AADE **dev** endpoint (`mydataapidev.aade.gr`).
Exactly two `SendInvoices`, plus cleanup cancels. The two production tenants
(`myip`, `nexon`) were never touched.

Steps: (1) file → MARK1; (2) simulate the lost response by nulling only the
mirror columns (`mydata_state/mark/url`) while keeping `series/ΑΑ` and the
`mydata_marks` INSERT row; (3) blind-retry the **same** payload → MARK2;
(4) cross-check with `RequestTransmittedDocs`.

## Result — AADE does NOT dedup

| | MARK | invoiceUid | statusCode |
|---|---|---|---|
| Submission 1 | `400001965177931` | `E230F0CFCC82356FE38C9F085A86A6E7F421EAD1` | Success |
| Submission 2 (blind retry) | `400001965177971` | `E230F0CFCC82356FE38C9F085A86A6E7F421EAD1` | Success |

**Two different MARKs, identical `invoiceUid`.** AADE derives the uid
deterministically from `(VAT, date, branch, type, series, ΑΑ)` — it is the SAME
across both submissions — yet AADE accepted BOTH and issued a **second MARK**.

`RequestTransmittedDocs` for `(series=ΤΠΥ, ΑΑ=1, ΑΦΜ=800561849)` returned **two**
`<invoice>` blocks, both with `uid=E230F0…EAD1`, marks `…931` and `…971`.

### Submission 1 — raw response
```xml
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc ...>
  <response>
    <index>1</index>
    <invoiceUid>E230F0CFCC82356FE38C9F085A86A6E7F421EAD1</invoiceUid>
    <invoiceMark>400001965177931</invoiceMark>
    <qrUrl>https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=0APWJ0eDDwhZQgrDk3nkrEwNjmXYIUdgIKQAvqOCqFmZwabYujoBo9mBHL0AJgXGkJMtVUFEAIWyET1eyCz07F9K0wCoZfmtuMy8MQ7nwFM%3d</qrUrl>
    <statusCode>Success</statusCode>
  </response>
</ResponseDoc>
```

### Submission 2 (blind retry) — raw response
```xml
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc ...>
  <response>
    <index>1</index>
    <invoiceUid>E230F0CFCC82356FE38C9F085A86A6E7F421EAD1</invoiceUid>
    <invoiceMark>400001965177971</invoiceMark>
    <qrUrl>https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=0APWJ0eDDwhZQgrDk3nkrPSNi%2ftnsXAz96HxZ4YbWlcTObFOl26yXDQm4C%2fTWT4rWqMDY9f8XOYR7tQQCHAjlzTC5bmBS2gpL9q0uFyCKBw%3d</qrUrl>
    <statusCode>Success</statusCode>
  </response>
</ResponseDoc>
```

**Conclusion:** the code's dedup assumption is **WRONG** for the ERP send path.
A retry after a transport timeout is **dangerous** — it double-declares income.
→ σκέλος (γ) (the in-doubt gate) is **REQUIRED**.

## Second finding — RequestTransmittedDocs indexing lag (found during fix E2E)

While validating the fix end-to-end against the real sandbox, a freshly-filed
MARK was **not yet visible** in `RequestTransmittedDocs` when queried seconds
later. A naive "reconcile finds nothing → resubmit" therefore STILL double-filed
(marks `…665` + `…666`, both cancelled). The feed lags a new document by a
minute or two. **The in-doubt gate must not resubmit within a grace window when
AADE shows nothing** — "nothing" is ambiguous between "never landed" and "not yet
indexed". Only a FOUND mark is unambiguous (→ adopt immediately).

## The fix (σκέλος γ) — "in-doubt" gate

- **New column** `invoices.mydata_pending_since` (nullable timestamp). Mirror
  column, written ONLY by `MyDataSubmitter` (forceFill, not `$fillable`).
  `mydata_state` stays `null`, so every existing "is it filed?" predicate is
  unchanged; only `submit()`'s own pre-check reads the new column.
- **On a transport failure** (`MyDataTimeoutException|MyDataConnectionException`)
  `submit()` sets `mydata_pending_since = now()` before re-throwing.
- **On the next `submit()` of an in-doubt invoice**, BEFORE any resubmission:
  reconcile `(series, ΑΑ)` via `RequestTransmittedDocs` (reusing
  `SalesReconciler::fetchAadeDocs`):
  - a **live (non-cancelled) MARK found** → **adopt** it (write the INSERT
    mark-row + flip the mirror to VALID, no second POST — MYD-7-style self-heal);
  - **nothing found, still within the grace window** (`config
    einvoice.in_doubt_grace_minutes`, default 10, `EKDOSI_MYDATA_INDOUBT_GRACE_MINUTES`)
    → **refuse** to resubmit (feed may just be lagging) — operator waits + retries;
  - **nothing found, grace elapsed** → the POST is deemed lost → **file normally**.
  - reconcile lookup itself unreachable → refuse (never resubmit blindly).
- A successful filing / adoption **clears** `mydata_pending_since`.
- The daily `mydata:reconcile-sales` remains the backstop for anything that
  slips through (and surfaces the multi-MARK case, which the adopt path also
  logs as a warning).

**Tests:** `tests/Feature/MyDataSubmitInDoubtTest.php` (mock) — adopt-without-
resubmit, within-grace-refuse, past-grace-file — plus a real sandbox E2E of the
same three branches (file once → immediate retry refuses → wait for indexing →
retry adopts, no second filing).

## Provider channel (InvoSign) — the SAME experiment, opposite result

Providers (ΥΠΑΗΕΣ) become mandatory from October, so the same retry question was
re-run through the **provider** path (InvoSign demo account, `demo.invosign.gr`,
issuer AFM `800561849`, type 2.1). The provider transport (`InvoSignTransport`)
was driven directly, mirroring `GrProviderSubmitter`'s send.

| | MARK | invoiceUid | statusCode |
|---|---|---|---|
| Provider send 1 | `400001965179246` | `F8CF78B590FB881AD08D2E0B477B9D93C785FCA3` | Success |
| Provider send 2 (blind retry, same series+ΑΑ) | `400001965179246` | `F8CF78B590FB881AD08D2E0B477B9D93C785FCA3` | Success |

**The provider DEDUPS.** A blind resubmission of the identical `(series, ΑΑ)`
returned the **SAME MARK** — no second filing. This is the exact opposite of the
direct ERP channel, and matches the spec's `[233]` note («η uid dedup αφορά μόνο
τους παρόχους»). Two further provider observations:

- **`invoice_status.php` is real-time**: a status-check by coordinates returned
  MARK `…246` immediately (no `RequestTransmittedDocs`-style lag). So
  `GrProviderSubmitter`'s §14.4 inline status-check recovery is reliable — it does
  NOT suffer the indexing-lag race the direct channel does.
- **A provider-filed 2.1 invoice cannot be cancelled at all.** Direct AADE cancel
  → `[249] cannot be cancelled because of being posted by provider`; the provider's
  own `CancelDeliveryNote` → `[283]` (that endpoint cancels **only** 9.3 δελτία
  αποστολής). Per `docs/paroxos/research/invosign-api-reference.md`, a provider
  2.1/11.x is reversed with a **credit note (5.1)** — ekdosi already gates
  «Ακύρωση μέσω παρόχου» to 9.3-only. So MARK `…246` is a permanent InvoSign-**demo**
  test invoice (sandbox test data, metered quota) that cannot be cancelled; it is
  left as-is by design.

**Conclusion for the provider path:** because the provider **dedups** AND exposes
a **real-time** status endpoint (both already leveraged by `GrProviderSubmitter`:
dedup makes even a blind retry safe, and the status-check adopts on an ambiguous
failure), the provider submitter needs **no in-doubt gate** — the gate added here
is a **direct-myDATA-only** requirement. No change to `GrProviderSubmitter`.

## Cleanup

All sandbox MARKs created were cancelled:

| MARK | cancellationMark |
|---|---|
| `400001965177931` (dedup experiment #1) | `400001965178280` |
| `400001965177971` (dedup experiment #2) | `400001965178281` |
| `400001965178665` (fix-E2E accidental double #1) | `400001965178699` |
| `400001965178666` (fix-E2E accidental double #2) | `400001965178700` |
| `400001965178764` (buggy-E2E leftover) | `400001965178869` |
| `400001965178909` (grace-aware E2E, adopted) | `400001965178998` |

All **direct-channel** MARKs above were cancelled — no orphan declared income
remains on the direct AADE sandbox. The throwaway `sbx` tenant is deleted after
the run.

**Provider demo residue (uncancellable by design):** MARK `400001965179246`
(InvoSign demo, type 2.1) — a provider-filed 2.1 cannot be cancelled by any
channel (only reversed with a 5.1 credit note; see the provider section). It is
left as-is in the InvoSign **demo** sandbox (test data / metered quota). The
provider dedup meant both provider sends returned this ONE mark, so no second
provider orphan was created.
