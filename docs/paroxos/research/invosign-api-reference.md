# InvoSign (iNVOSign / Υ.ΠΑ.Η.Ε.Σ.) REST API — reference

> Source: `https://invosign.gr/site/help_site/` (API guide **v1.0.1**), all 12
> linked subpages, captured 2026-06. **One of several providers** ekdosi will
> support — chosen as the first reference impl because its docs are public.
> Facts below are from the site; anything absent is flagged **NOT documented**.

## 0. The key design takeaway (corrects implementation-plan §12)
InvoSign is **NOT a fully bespoke schema**. The `xml_arxeio` payload is the
**full, unmodified AADE myDATA `InvoicesDoc`** (same namespaces, same element
names, machine codes intact) with InvoSign **additions appended**:
- an invoice-level `<API_InvoiceDetails>` block (issuer/counterpart/additionals —
  human-readable printout fields AADE doesn't carry), and
- per-line `api_*` twins inside each `<invoiceDetails>` (unit price, discount
  amount, VAT **percent**, free-text unit — the printout-friendly versions of
  AADE's coded fields).

⇒ **Reuse, don't rebuild:** firebed's `InvoicesDocWriter` already emits the AADE
core. The InvoSign adapter = that XML **+ an appended extension block** built from
the same `Invoice` model. No separate full serializer. This *strengthens* the
"transport declares its serializer" model: here the serializer is
`AADE-core + InvoSign-extension`, a thin decorator over the shared writer.

## 1. Transport facts (for the `InvoSignTransport` adapter)
- **Per-client `base_url` + `token`** (and parallel `demo_base_url` + `demo_token`
  for sandbox), issued privately by InvoSign. → `ProviderCredentials` =
  `{base_url, token, demo_base_url, demo_token}`. **Actual values NOT documented.**
- **Auth = plain `token` as a form field** (NOT a header / Bearer / OAuth).
- All invoice calls: **`POST application/x-www-form-urlencoded`** with
  `xml_arxeio=<XML>` + `token`. Responses are XML `<ResponseDoc>`.
- **Sandbox: yes** — same script names under `demo_base_url`. Maps to our
  `einvoice_provider_mode=sandbox`.
- **PEPPOL Access Point / B2G: NOT documented** → InvoSign covers the **B2B**
  leg; the public-sector/PEPPOL leg stays separate (plan §7).

### Endpoints (appended to `[base_url]`)
| Operation | Script |
|---|---|
| Issue invoice | `iNVOSign_Api.php` |
| Status check | `invoice_status.php` |
| Cancel | `iNVOSign_CancelDeliveryNote.php` |
| POS payment request (1st) | `iNVOSign_GetPayment.php` |
| POS payment transmit (2nd, deferred) | `iNVOSign_Payment.php` |

## 2. Issue request — the appended extension

### 2a. `API_InvoiceDetails` (invoice-level, required block)
**`API_Issuer`** — `IssuerName`*, `IssuerProfession`*, `IssuerTaxOffice`*,
`IssuerAddressStreet`*, `IssuerAddressPostalCode`*, `IssuerAddressCity`*,
`IssuerPhone`, `IssuerEmail`  (* = required).
**`API_Counterpart`** — `CounterpartName`*, `CounterpartVat`* (⚠ InvoSign name;
AADE uses `vatNumber`), `CounterpartProfession`, `CounterpartTaxOffice`,
`CounterpartAddressStreet`, `CounterpartAddressPostalCode`, `CounterpartAddressCity`,
`CounterpartPhone`, `CounterpartEmail`.
**`API_Additionals`** — `DocumentLabel`*, `DocumentDispatchTo`, `DocumentComments`,
`DocumentPaymentMethodLabel`; example also shows `DocumentMovePursposeLabel` (sic —
reproduce the misspelling) + `DocumentDispatchFrom` (delivery-note only, not in the
field table).

### 2b. Per-line `api_*` (inside `<invoiceDetails>`)
| Field | Type | Req | Meaning |
|---|---|---|---|
| `api_serial` | string | no | product code |
| `api_lineDescription` | string | **yes** | line description (≈ AADE `itemDescr`) |
| `api_NetPriceBeforeDiscount` | decimal(2) | **yes** | unit price before discount |
| `api_UnitPrice` | decimal(2) | **yes** | unit price after discount |
| `api_DiscountValue` | decimal(2) | **yes** | discount amount |
| `api_vatCategoryPercent` | decimal(2) | **yes** | VAT **percent** e.g. `24.00` (AADE sends only the `vatCategory` **code**) |
| `api_quantity` | decimal | **yes** | quantity (twin of AADE `quantity`) |
| `api_mm` | string | **yes** | free-text unit e.g. `Τμχ` (AADE `measurementUnit` is a numeric code) |

### 2c. Sample (extension only — the AADE `InvoicesDoc` core precedes it verbatim)
```xml
<invoiceDetails>
  <!-- ...AADE fields: lineNumber, itemDescr, quantity, measurementUnit,
       netValue, vatCategory, vatAmount, incomeClassification... -->
  <api_serial>0003</api_serial>
  <api_lineDescription>Εμπόρευμα</api_lineDescription>
  <api_NetPriceBeforeDiscount>2.00</api_NetPriceBeforeDiscount>
  <api_UnitPrice>2.00</api_UnitPrice>
  <api_DiscountValue>0.00</api_DiscountValue>
  <api_vatCategoryPercent>24.00</api_vatCategoryPercent>
  <api_quantity>1.0000</api_quantity>
  <api_mm>Τμχ</api_mm>
</invoiceDetails>
<!-- after invoiceSummary, still inside <invoice>: -->
<API_InvoiceDetails>
  <API_Issuer>...</API_Issuer>
  <API_Counterpart><CounterpartName>...</CounterpartName><CounterpartVat>997073525</CounterpartVat>...</API_Counterpart>
  <API_Additionals><DocumentLabel>Τιμολόγιο Δελτίο Αποστολής</DocumentLabel>...</API_Additionals>
</API_InvoiceDetails>
```

## 3. Issue response (`<ResponseDoc><response>`)
**Success** → maps 1:1 onto our `ProviderResult`:
| InvoSign field | ProviderResult | Note |
|---|---|---|
| `invoiceMark` | `mark` | the AADE ΜΑΡΚ |
| `authenticationCode` | `authenticationCode` | AADE auth code (40-hex) |
| `qrUrl` | `qrUrl` | `https://invosign.gr/viewinvoice.php?afm=EL…&uid=…` |
| `invoiceUid` | `uid` | provider UID (40-hex) |
| `index` | — | batch index |
| `statusCode` | — | `Success` |
| `receptionEmails` | (delivery) | recipient emails |
| `remaining_invoices` | (quota) | remaining quota |

**Error** → `statusCode=ValidationError` + `<errors><error><message><code>`.
Only documented code: **238** ("IssueDate is invalid, must equal current date").
Full code list defers to AADE myDATA business-error codes. → our
`describeResponseErrors()` analogue parses `<errors>`.

## 4. Status check (`invoice_status.php`)
POST `token` + `issuer_vatNumber` + `branch` + `invoiceType` + `issueDate` +
`series` + `aa`. **Response identical to the issue response** → so a lost
connection / `TransmissionFailure=2` is recoverable: re-query to fetch the ΜΑΡΚ.
(Confirms plan §4: the rare "pull MARK" path.)

## 5. Cancel (`iNVOSign_CancelDeliveryNote.php`)
POST `mark` + `token` → success `<cancellationMark>` + `statusCode=Success`.
Cancel **error** shape NOT documented (assume mirrors `<errors>`).

**⚠ General ΥΠΑΗΕΣ rule (not InvoSign-specific):** «Για τη διαβίβαση μέσω
Παρόχου Ηλεκτρονικής Τιμολόγησης δεν είναι προς το παρόν εφικτή η ακύρωση
παραστατικών που έχουν λάβει ΜΑΡΚ παρά μόνο η έκδοση Πιστωτικού Τιμολογίου.»
(confirmed against another provider's docs). I.e. a MARKed **invoice** (2.1/11.x…)
can NOT be cancelled via any provider — reverse it with a credit note (5.1, its
own correlated MARK). `CancelDeliveryNote` works ONLY for **9.3 δελτία αποστολής**
(διακίνηση docs, not invoices) — InvoSign returns **[283]** for anything else.
ekdosi gates «Ακύρωση μέσω παρόχου» to 9.3-only on every provider channel
(`ViewInvoice::cancel_at_mydata`); 2.1/11.x → «Έκδοση πιστωτικού».

## 6. POS (out of scope for invoicing, noted)
`iNVOSign_GetPayment.php` (+ deferred `iNVOSign_Payment.php`) — **JSON** bodies
(not XML). Concurrent: get `paymentToken` then submit; deferred: for an invoice
already carrying a ΜΑΡΚ. Only relevant if a tenant takes card payments through
the provider; ignore for the e-invoicing build.

## 7. NOT documented publicly (flagged)
base_url/token/demo values + test AFMs; explicit `Content-Type` header; full
error-code table; cancel-error shape; multi-`<invoice>` batch support (an `index`
field hints at it); any idempotency/retry key beyond `token`.
