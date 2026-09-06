# myDATA SUBMIT payload shape (reference)

> Extracted from `CLAUDE.md` (2026-09-06) to keep the root guide lean. This is the
> field-by-field detail of the accepted `SendInvoices` payload. The rule of thumb in
> `CLAUDE.md`: semantic equivalence with the lost `CMyData.cpp`, validated field-by-field.
> The §-error references are to `docs/aade/myDATA_API_Documentation_v2.0.0_preofficial_erp.md`.

## The proven-accepted shape (validated against AADE sandbox 2026-05-28)

`MyDataSubmitter::buildAadeInvoice` → `SendInvoices`. Grounded in an imported legacy MARK
request; each rule tied to the AADE error it clears:

- issuer = tenant AFM + `country=GR` + `branch=0` (multi-branch tenants would need the real
  branch — none today).
- counterpart **present** for B2B (1.1/2.1) with GR VAT and **NO name/address** (`[219]/[220]`
  forbid them for GR parties); **omitted** for retail (11.x). Foreign counterpart needs
  name+address+ISO country.
- `invoiceHeader`: series, aa, issueDate (`Y-m-d` in the request body), type, `currency=EUR`.
- per-line: `netValue`, `vatCategory`, `vatAmount`, income classification (`E3_*` +
  `categoryN_x`). **NO per-line `<quantity>`** — `[205]` forbids it for the service types we
  file (goods types DO require it → conditional `invoice_types.mydata_requires_quantity`).
- `invoiceSummary`: net, vat, the **five zero tax-total fields**
  (`totalWithheldAmount`/`Fees`/`StampDuty`/`OtherTaxes`/`Deductions`) — `[101]` requires them
  between vat and gross — then gross + aggregated income class.
- `paymentMethods`: one detail, `amount = gross`, `type = 3` (cash, via `paymentMethodTypeFor()`;
  per-PaymentMethod map is `payment_methods.mydata_payment_type`). `[204]` mandatory.
- **NO `<uid>`** — `[273]` forbids a client uid; AADE derives its own for retry-dedup. **NO
  `<taxesTotals>`** — VAT is NOT a `taxType` (1–5 = withholding/fees/otherTaxes/stamp/deductions);
  it lives per-line + in `totalVatAmount`.
- **Credit notes**: correlate to the original's INSERT MARK via `addCorrelatedInvoice` **only for
  correlated types (5.1)**; `Codes::isNonCorrelatedCreditType()` skips it for **5.2** (AADE forbids
  correlation there). `describeResponseErrors()` iterates firebed's `Errors` object (a `TypeArray`,
  NOT an array — `array_map` over it TypeErrors and masks the real rejection).
- **Additional-taxes → gross rule (`[208]`, standing):** withholding/fees/stamp/otherTaxes/
  deductions, WHEN present, DO adjust `totalGrossValue` + the paymentMethod amount — gross =
  net+vat + fees + stamp + otherTaxes − deductions − withheld — EXCEPT the informational §8.4
  withholding cats 8/9/10 (`WithheldPercentCategory::affectsTotalGrossValue()`; built in
  `AadeInvoiceDocument`).

## Sandbox-validated (history)

1.1/2.1/11.2/5.1 + CANCEL (2026-05-28) and round-2 taxTypes / 4%-override→cat10 / full ΔΑ
lifecycle (2026-06-10) all AADE-accepted with zero payload changes; reconciliation matched.
Also learned: a **Completed** ΔΑ can't be cancelled (`[801]`, by design). Full reports →
`docs/archive/mydata-sandbox-validation-2026-05-28.md`.

## Submitter payload follow-ups — ✅ ALL DONE (sandbox-validated)

0%/exempt (`vatCategory=7` + exemption §8.3) · 4% cat-6-vs-10 override
(`vat_categories.mydata_vat_category`) · conditional per-line `<quantity>`
(`invoice_types.mydata_requires_quantity`) · `taxesTotals` for withholding/fees/stamp/
otherTaxes/deductions (`AadeInvoiceDocument::addAdditionalTaxes` + «Τυπικά τέλη/φόροι»
quick-fill/`CommonTaxPresets`) · PaymentMethod→payment-type map
(`payment_methods.mydata_payment_type`) · auto-calc of % amounts (`RecomputeInvoiceTaxes`) ·
`MyDataSubmitterSafetyTest` round-trip. **Still open:** curated-preset expansion.
