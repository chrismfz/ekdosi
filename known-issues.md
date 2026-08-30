# Known issues and readiness ledger

This file is the working source of truth for installer, first-run setup, myDATA,
scheduler/queue and production-readiness work. Keep completed entries in the file:
change their status to **DONE**, add the implementing commit/PR and record the date
in the change log.

## Audit baseline

- Repository: `chrismfz/ekdosi`
- Audited branch: `main`
- Audited commit: [`7c75cd35a6ea38bb5a11b04b818a6585baeea869`](https://github.com/chrismfz/ekdosi/commit/7c75cd35a6ea38bb5a11b04b818a6585baeea869)
- Audit date: 2026-08-29
- AADE production specification checked: [myDATA ERP v2.0.1](https://www.aade.gr/sites/default/files/2026-03/myDATA%20API%20Documentation%20v2.0.1_official_erp.pdf)
- Digital Delivery Note specification checked: [Delivery Note v2.0.1](https://www.aade.gr/sites/default/files/2026-03/myDATA%20API%20Documentation_DeliveryNote_v2.0.1_official.pdf)

This is a source audit. It confirms what the repository installs and schedules; it
cannot prove that a particular host actually has the required OS crontab, worker,
TLS certificate or off-site backup credentials.

## Focused myDATA re-audit — 2026-08-30

This pass is documentation-only. No MYD implementation should start until the
issue-specific legal mapping, payload path and acceptance tests below are agreed.

Production sources used:

- [AADE production technical specifications](https://www.aade.gr/mydata/tehnikes-prodiagrafes-ekdoseis-mydata)
- [myDATA ERP API v2.0.1](https://www.aade.gr/sites/default/files/2026-03/myDATA%20API%20Documentation%20v2.0.1_official_erp.pdf)
- [Digital Delivery Note API v2.0.1](https://www.aade.gr/sites/default/files/2026-03/myDATA%20API%20Documentation_DeliveryNote_v2.0.1_official.pdf)
- [AADE digital-movement FAQ](https://www.aade.gr/ypohreotiki-ilektroniki-timologisi-psifiaka-parastatika-diakinisis-syhnes-erotiseis)
- [AADE timologio v1.5 mixed-document notes](https://www.aade.gr/mydata-ilektronika-biblia-aade/timologio/ekdosi-v150-ti-perilambanei)
- [Current VAT Code, law 5144/2024](https://elib.aade.gr/elib/gr/act/2024/5144)

Specification policy:

- v2.0.1 is the current production baseline.
- [v2.0.2 is preofficial/test-environment material](https://www.aade.gr/mydata-ilektronika-biblia-aade/mydata/dokimastiko-periballon);
  track it under DEP-001, but do not implement production behavior from it yet.
- Passing an XSD is not enough for legal correctness. VAT exemptions and income
  classifications must match the actual transaction and business activity.

Re-audit outcome:

| ID | Verdict | Priority | Research decision |
|---|---|---:|---|
| MYD-001 | Confirmed | P0 | 1.3/2.3 must use E3_561_006, not E3_561_005 |
| MYD-002 | Confirmed, wording corrected | P0 | ΤΔΑ is valid as mixed behavior; Ekdosi currently emits only ordinary 1.1 |
| MYD-003 | Confirmed | P0 | 9.1/9.2/9.3 are movement-only and must not use the monetary invoice path |
| MYD-004 | Confirmed, expanded | P0 | Validate actual configured VAT codes and distinguish official codes 6 and 10 |
| MYD-005 | Confirmed as enhancement | P2 | measurementUnit is optional on ordinary invoice lines, not an XSD blocker |
| MYD-006 | Confirmed as policy gap | P1 | No universal seed exists; onboarding must select/review the business policy |
| MYD-007 | New confirmed issue | P0 | EU/export exemption hints and the global 0% reason model are unsafe |

## Extended myDATA path audit — 2026-08-30

This pass reviewed the paths outside the original installer/default audit:
provider-issued credits, frozen legal snapshots, branch numbers, Digital Delivery
Note issuance/lifecycle, inbound expense cancellation and VAT-picture aggregation.

Only confirmed defects are listed. Possible future capabilities such as foreign
currencies, reverse delivery notes, weighing and multi-classification splits were
not promoted to issues without a currently exposed path that behaves incorrectly.

| ID | Verdict | Priority | Confirmed impact |
|---|---|---:|---|
| MYD-008 | Confirmed | P0 | Provider-issued originals cannot be referenced by correlated credits |
| MYD-009 | Confirmed | P0 | Customer edits can alter counterpart identity after the invoice snapshot |
| MYD-010 | Confirmed | P0 | Branch businesses are always reported as establishment 0 |
| MYD-011 | Confirmed | P0 | Foreign supplier/manual delivery recipients are reported as GR |
| MYD-012 | Confirmed | P0 | 9.1 is selectable but no correlated MARK is transmitted |
| MYD-013 | Confirmed | P1 | A non-UI caller can build RegisterTransfer without mandatory transportType |
| MYD-014 | Confirmed | P1 | A cancelled supplier document can remain locally VALID indefinitely |
| MYD-015 | Confirmed | P1 | 8.5 POS returns increase rather than reduce the VAT-picture totals |
| MYD-016 | Confirmed | P1 | Bad delivery-unit data is changed to pieces instead of being rejected |

## Status and priority

Statuses:

- **OPEN** — verified issue, not implemented.
- **IN PROGRESS** — implementation has started.
- **VERIFY** — implementation exists but acceptance criteria have not all passed.
- **WATCH** — currently correct; re-check when an upstream dependency/spec changes.
- **DONE** — fixed and verified; retain the entry for history.

Priorities:

- **P0** — can file incorrect data, expose the wrong filing path or falsely declare go-live readiness.
- **P1** — blocks or materially confuses a normal first-time operator.
- **P2** — operations, resilience, documentation or coverage gap.

## Work board

| ID | Priority | Status | Area | Summary |
|---|---:|---|---|---|
| MYD-001 | P0 | OPEN | Classification | Third-country 1.3/2.3 use the intra-EU E3 code |
| MYD-002 | P0 | OPEN | ΤΔΑ | Seeded label promises a combined invoice/delivery payload that is not emitted |
| MYD-003 | P0 | OPEN | Delivery notes | 9.x movement-only types are exposed in the monetary invoice picker |
| MYD-004 | P0 | OPEN | VAT validation | 3%, dual 4% codes and 0% can produce false readiness results |
| MYD-005 | P2 | OPEN | Quantity units | Ordinary invoice XML omits optional myDATA measurementUnit |
| MYD-006 | P1 | OPEN | Classifications | Readiness does not require a business-specific classification policy |
| MYD-007 | P0 | OPEN | VAT exemption | EU/export hints are wrong and one tenant-wide 0% reason cannot represent mixed cases |
| MYD-008 | P0 | OPEN | Provider credits | Correlated credit cannot find a provider-issued original MARK |
| MYD-009 | P0 | OPEN | Counterpart identity | Submitted AFM/name can come from live customer instead of the frozen invoice snapshot |
| MYD-010 | P0 | OPEN | Branches | Issuer and counterpart branch are always filed as head office 0 |
| MYD-011 | P0 | OPEN | Delivery recipient | Supplier/manual recipient country is lost and filed as GR |
| MYD-012 | P0 | OPEN | Delivery correlation | Seeded 9.1 is offered without any correlated MARK payload |
| MYD-013 | P1 | OPEN | Delivery lifecycle | RegisterTransfer can omit the mandatory transportType |
| MYD-014 | P1 | OPEN | Expense sync | Supplier cancellation is detected but cannot update an existing local expense |
| MYD-015 | P1 | OPEN | VAT picture | Type 8.5 POS return is added with a positive sign |
| MYD-016 | P1 | OPEN | Delivery units | Invalid or missing coded unit is silently filed as pieces |
| SETUP-001 | P1 | OPEN | Onboarding | Fresh tenant is not guided to a first valid invoice |
| SETUP-002 | P1 | OPEN | Issuer identity | Installer accepts insufficient legal/myDATA issuer data |
| SETUP-003 | P1 | OPEN | Payment | Missing payment method silently becomes cash in XML |
| SETUP-004 | P2 | OPEN | Estonia | EE tenant skips even non-AADE standard lookups |
| OPS-001 | P1 | OPEN | Scheduler/queue | Installer does not provision or prove OS cron and worker |
| OPS-002 | P2 | OPEN | Installer | Writable env/application root is not a hard preflight |
| OPS-003 | P2 | OPEN | Shared hosting | No cPanel/shared-hosting queue recipe or direct completion link |
| TEST-001 | P2 | OPEN | Tests/CI | No full web installer success-path test; inspected CI was not green |
| DEP-001 | P2 | WATCH | Dependency | firebed/aade-mydata is current; watch AADE v2.0.2 |
| UPD-001 | P0 | OPEN | Queue safety | PHP update/rollback does not drain an in-flight worker |
| UPD-002 | P0 | OPEN | Failure recovery | Partial apply failure lifts maintenance and can serve inconsistent code |
| UPD-003 | P0 | OPEN | Update integrity | UI queues a mutable tag, not a verified immutable commit SHA |
| UPD-004 | P0 | OPEN | Rollback readiness | Apply can start without a known current ref or proven rollback path |
| UPD-005 | P1 | OPEN | Maintenance mode | Live UI and opcache self-hit are blocked while the app is down |
| UPD-006 | P1 | OPEN | Crash recovery | A killed process can leave a permanent running row and maintenance state |
| UPD-007 | P1 | OPEN | Health result | Critical health/advisory failures still end as succeeded |
| UPD-008 | P1 | OPEN | Snapshot retention | PHP update/rollback snapshots are never pruned by --keep=10 |
| UPD-009 | P1 | OPEN | Preflight | Button does not prove cron, binaries, space, permissions or clean target |
| UPD-010 | P1 | OPEN | Script strategy | Bash deploy script is invoked through sh |
| UPD-011 | P1 | OPEN | Tests | Apply, migration, failure and rollback paths are not executed in tests |
| UPD-012 | P1 | OPEN | Safety controls | “Read-only” update setting also arms one-click apply |
| UPD-013 | P2 | OPEN | Credentials | Git token remains in the updater process environment after fetch |
| UPD-014 | P2 | OPEN | Discovery | Future GitHub Releases can mask newer tag-only releases |
| UPD-015 | P2 | OPEN | Concurrency | Single-flight is UI/scheduler based, not an atomic command-level lock |

## Detailed issues

### MYD-001 — Third-country sales use the wrong E3 code

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

AADE v2.0.1 distinguishes E3_561_005 for intra-community sales from
E3_561_006 for third-country sales.

**Repository evidence**

- [Codes::TYPE_DEFAULTS](app/Support/MyData/Codes.php) maps both 1.3 and 2.3
  to E3_561_005.
- The existing 1.2/2.2 mappings to E3_561_005 are correct.
- [MyDataLookupSeeder](app/Services/MyData/MyDataLookupSeeder.php) consumes
  those defaults and preserves later operator edits.

**Required change**

- Map 1.3 and 2.3 to E3_561_006.
- Add exact default/seeder tests for 1.2, 1.3, 2.2 and 2.3.
- Preserve the fill-empty behavior for existing operator edits.

**Acceptance**

- A fresh GR install seeds 1.2/2.2 as 561_005 and 1.3/2.3 as 561_006.
- Existing operator-edited classifications remain unchanged.

### MYD-002 — ΤΔΑ label exists, but the combined payload does not

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED, WORDING CORRECTED 2026-08-30

**Official finding**

ΤΔΑ is not a separate myDATA invoiceType, but combined value-and-movement
functionality does exist. AADE documents type 1.1 with isDeliveryNote=true and
the required dispatch, movement and address data as an invoice plus delivery
document. Therefore the correct conclusion is not that ΤΔΑ no longer exists.

**Repository evidence**

- [MyDataLookupSeeder](app/Services/MyData/MyDataLookupSeeder.php) correctly
  bases the ΤΔΑ seed on monetary type 1.1.
- [AadeInvoiceDocument](app/Services/EInvoice/AadeInvoiceDocument.php) has no
  combined-document state and emits neither isDeliveryNote=true nor the complete
  movement header, loading/delivery addresses and dispatch data.
- The current result is therefore an ordinary 1.1 invoice whose UI label promises
  delivery-note behavior it does not perform.

**Required change**

- Long-term: implement the complete combined payload, validation and lifecycle.
- Safe interim: hide or clearly disable ΤΔΑ as not yet supported; do not rename
  an ordinary 1.1 invoice as ΤΔΑ.

**Acceptance**

- A ΤΔΑ passes the official invoice and delivery-note schemas and sandbox
  lifecycle with the required movement data; or it is not offered as available.

### MYD-003 — Movement-only 9.x types appear in the monetary invoice form

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

AADE separates value-plus-movement documents from movement-only types
9.1, 9.2 and 9.3. The latter belong to the Digital Delivery Note flow.

**Repository evidence**

- Seeded ΔΑΣ/ΣΔΑ/ΔΑΠ have show_on_menu=true.
- [PickerOptions::invoiceTypeOptions](app/Filament/Support/PickerOptions.php)
  includes all visible types, and [InvoiceForm](app/Filament/Resources/Invoices/Schemas/InvoiceForm.php)
  uses that list.
- Monetary invoice submission builds [AadeInvoiceDocument](app/Services/EInvoice/AadeInvoiceDocument.php).
- The correct coded movement path exists separately in
  [DeliveryNoteSubmitter](app/Services/Delivery/DeliveryNoteSubmitter.php).

**Required change**

- Exclude 9.x from every monetary invoice selector and issue action.
- Route movement-only documents exclusively through the Delivery Notes resource.
- Enforce the separation in domain validation as well as the UI.

**Acceptance**

- Create Invoice cannot select or submit 9.1/9.2/9.3.
- Delivery Notes still exposes supported movement types and uses
  DeliveryNoteSubmitter.

### MYD-004 — VAT readiness checks do not prove the submitted VAT code

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED AND EXPANDED 2026-08-30

**Official finding**

The current official VAT table includes:

| myDATA VAT code | Rate/meaning |
|---:|---|
| 6 | 4% |
| 7 | 0% / without VAT; exemption reason required |
| 9 | 3% under article 31 of law 5057/2023 |
| 10 | 4% under article 31 of law 5057/2023 |

Codes 6 and 10 have the same rate but different legal bases. A numeric percentage
alone cannot choose safely between them.

**Repository evidence**

- [Codes::vatCategorySeedRows](app/Support/MyData/Codes.php) seeds codes 1–7
  by rate, but not explicit rows for code 9 or 10.
- The label for code 10 describes it as island 4%, which the official table does
  not say and should be corrected.
- [AadeInvoiceDocument](app/Services/EInvoice/AadeInvoiceDocument.php) can emit
  3% only when an explicit override selects code 9; its rate fallback cannot.
- [MyDataConfigAudit](app/Services/MyData/MyDataConfigAudit.php) checks rate
  tables rather than the exact resolver/configuration used by submission.
- Missing 0% exemption data is only a warning, and
  [MyDataPreflight](app/Console/Commands/MyDataPreflight.php) exits successfully
  when warnings are the only findings.
- Invoice lines snapshot vat_percent, not the selected myDATA VAT code. That is
  insufficient if both 4% regimes must coexist in one tenant.

**Required change**

- Use one shared VAT resolver for configuration audit, preflight and XML build.
- Seed 3% with explicit code 9 and correct the code 10 description.
- Require an explicit 6-versus-10 choice; never infer it from 4%.
- Make a used 0% row without a valid exemption reason a blocking error.
- If both 4% regimes can coexist, snapshot the chosen VAT code per invoice line.

**Acceptance**

- Every VAT setup that passes preflight builds the same expected XML code.
- 3% without code 9 and 0% without a reason fail before issue.
- Tests cover codes 6, 7, 9 and 10, including same-rate code distinction.

### MYD-005 — Optional measurementUnit is omitted from ordinary invoice XML

**Status:** OPEN · **Priority:** P2 · **Research:** CONFIRMED AS DATA-FIDELITY ENHANCEMENT 2026-08-30

**Official finding**

For ordinary myDATA invoice lines, quantity and measurementUnit are optional.
Their omission is therefore not by itself an XSD or filing-correctness defect.
It does, however, lose the distinction between pieces, kilos, litres and metres.

**Repository evidence**

- Standard lookups seed ΤΕΜ, ΚΙΛΟ, ΛΙΤΡΟ, ΜΕΤΡΟ, Μ², Μ³ and service/time units.
- [InvoiceLine](app/Models/InvoiceLine.php) snapshots a free-text metric_unit.
- [AadeInvoiceDocument](app/Services/EInvoice/AadeInvoiceDocument.php) omits
  measurementUnit on ordinary invoices.
- Delivery notes already use official coded quantity units.

**Required change**

- Add a reviewed catalogue-unit to official-code mapping for pieces, kilos,
  litres, metres, M2 and M3.
- Emit measurementUnit when a safe mapping exists.
- Continue omitting service/time units unless an official mapping is appropriate.

**Acceptance**

- Supported physical units retain their meaning in generated XML.
- Unsupported semantic units are omitted deliberately and tested.

### MYD-006 — Classification defaults need a business policy

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED AS ONBOARDING/POLICY GAP 2026-08-30

**Official finding**

AADE income categories distinguish category1_1 for merchandise/resale,
category1_2 for own products and category1_3 for services. There is no single
default that is correct for every business.

**Repository evidence**

- Goods document types default to category1_1.
- Product categories for Services, Merchandise and Products are seeded, but their
  classification override fields are empty.
- Per-category overrides exist, so the application can represent a mixed business
  only after operator configuration.
- Credit defaults are service-first, while a correlated credit should normally
  inherit the original transaction/line classification.

**Required change**

- During onboarding require reseller, manufacturer, services or mixed selection.
- Apply or review category defaults accordingly; mixed businesses must configure
  per-category mappings.
- Make correlated credits inherit classifications from the original document or
  credited lines instead of relying on a generic credit-type default.

**Acceptance**

- Go-live cannot be green until the classification policy is selected/reviewed.
- Tests cover reseller, own-product, services and correlated-credit cases.

### MYD-007 — VAT exemption reasons are wrong or too global

**Status:** OPEN · **Priority:** P0 · **Research:** NEW, CONFIRMED 2026-08-30

**Official finding**

Under the current VAT Code and myDATA exemption table:

| Scenario | Expected basis |
|---|---|
| Export of goods outside the EU | Reason 8 / article 29 |
| Intra-community supply of goods | Reason 14 / article 33 |
| Small-business exemption | Reason 15 / article 44 |
| Domestic reverse-charge cases | Reason 16 / article 45 |

Cross-border services need case-specific place-of-supply analysis; they must not
blindly inherit the goods-supply reason.

**Repository evidence**

- [Codes](app/Support/MyData/Codes.php) defines the intra-community exemption
  constant as reason 16.
- [ReverseCharge](app/Support/MyData/ReverseCharge.php),
  [InvoiceForm](app/Filament/Resources/Invoices/Schemas/InvoiceForm.php) and
  [VatCategoryForm](app/Filament/Resources/VatCategories/Schemas/VatCategoryForm.php)
  recommend reason 16/article 45 for a generic EU customer with a VAT ID.
- VatCategoryForm recommends reason 15/article 44 for export.
- [AadeInvoiceDocument::resolveVatExemptionCategory](app/Services/EInvoice/AadeInvoiceDocument.php)
  selects one unique tenant-wide 0% reason and applies it to all zero-rated lines.
- [InvoiceLine](app/Models/InvoiceLine.php) snapshots vat_percent but not the
  legally selected exemption reason.

This can emit syntactically valid but legally wrong data. It also prevents one
tenant from safely issuing, for example, both an intra-EU goods supply and a
third-country export without global reconfiguration.

**Required change**

- Correct EU-goods guidance to reason 14/article 33 and export-goods guidance to
  reason 8/article 29.
- Keep reason 16 only for applicable domestic reverse-charge cases.
- Do not auto-assign a goods exemption to cross-border services.
- Model and snapshot the exemption reason per VAT selection/invoice line, with
  controlled defaults and operator review.
- Validate customer country, document type, goods/services nature and exemption
  reason together before issue.

**Acceptance**

- Tests cover domestic reverse charge, EU goods supply, third-country goods
  export, small-business exemption and cross-border services.
- One invoice/tenant can represent different valid zero-VAT reasons without
  editing a global setting between documents.
- Preflight and submission use the same exemption resolver.

### MYD-008 — Correlated provider credit cannot resolve the original MARK

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

A correlated credit type 5.1 carries the MARK of the original document in
correlatedInvoices. Provider-issued documents still have an AADE MARK and must
remain correctable through the same legal correlation.

**Repository evidence**

- [AadeInvoiceDocument::originalInsertMark](app/Services/EInvoice/AadeInvoiceDocument.php)
  searches mydata_marks only where mydata_action is INSERT.
- [GrProviderSubmitter](app/Services/EInvoice/GrProviderSubmitter.php) records a
  successful provider issue as PROVIDER_INSERT.
- GrProviderSubmitter uses the same AadeInvoiceDocument builder. Therefore a 5.1
  credit against a provider-issued original throws no INSERT MARK before transport.
- Delivery-note cancellation already demonstrates the intended cross-channel
  pattern by accepting both INSERT and PROVIDER_INSERT.

**Required change**

- Resolve the original filing MARK from both INSERT and PROVIDER_INSERT.
- Require that the MARK belongs to the same tenant and to a successful issue row.
- Keep one shared resolver for direct and provider correction flows.

**Acceptance**

- A 5.1 credit correlates successfully to both a direct-issued and provider-issued
  original.
- Tests prove that a rejected/failed provider attempt is never accepted as the
  original MARK.

### MYD-009 — myDATA counterpart identity ignores frozen invoice fields

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

PartyType requires the VAT number and country of the legal counterpart. The
invoice model intentionally snapshots vat_no, company_name, country and address
so later customer edits cannot change an issued document.

**Repository evidence**

- [Invoice](app/Models/Invoice.php) documents the party columns as legally frozen
  issue-time snapshots.
- [AadeInvoiceDocument::buildCounterpart](app/Services/EInvoice/AadeInvoiceDocument.php)
  reads the VAT number from customer.afm, not invoice.vat_no.
- For a foreign counterpart it also reads customer.name, not invoice.company_name.
- Country and address prefer the snapshot, producing a mixed identity assembled
  partly from frozen data and partly from the current customer row.
- [IssueCreditNote](app/Actions/IssueCreditNote.php) copies the original snapshot,
  but the builder can still replace its AFM/name with today's customer values.

**Required change**

- Build the legal counterpart entirely from the invoice snapshot after issue.
- Permit a live-customer fallback only for a clearly identified legacy row whose
  snapshot is blank, and record that fallback.
- Validate that snapshot AFM, country and foreign name/address form one coherent
  party before submission.

**Acceptance**

- Editing a customer after invoice/credit creation does not change preview XML.
- A migration/backfill or explicit blocker handles older rows with blank snapshots.

### MYD-010 — All filings hard-code branch 0

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

AADE PartyType requires branch as the establishment number. Zero is correct only
when the issuer establishment is the registered head office or no branch exists.

**Repository evidence**

- [AadeInvoiceDocument](app/Services/EInvoice/AadeInvoiceDocument.php) calls
  setBranch(0) for both issuer and counterpart.
- [DeliveryNoteSubmitter](app/Services/Delivery/DeliveryNoteSubmitter.php) does the
  same for delivery issuer and recipient.
- [Company](app/Models/Company.php), Invoice and DeliveryNote have no frozen
  issuer-branch field for the actual issuing establishment.
- DeliveryNote has startShippingBranch/completeShippingBranch, but those fields
  describe a different loading/delivery establishment and do not replace the
  PartyType branch of the issuer/recipient.

**Required change**

- Model the tenant issuing establishment and snapshot it per legal document.
- Model counterpart branch where the transaction requires it.
- Default to zero only after an explicit head-office/no-branch choice.
- Add a go-live warning or blocker when the operator declares branches but no
  document branch policy exists.

**Acceptance**

- Head-office documents file branch 0.
- A configured branch document files its real registry establishment number and
  keeps that value frozen after issue.

### MYD-011 — Foreign supplier/manual delivery recipient is filed as GR

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

PartyType country must be a two-character ISO 3166 code and must describe the
actual recipient.

**Repository evidence**

- [DeliveryNoteForm](app/Filament/Resources/DeliveryNotes/Schemas/DeliveryNoteForm.php)
  can select either a customer or supplier and can also accept a manual recipient.
- It snapshots recipient_afm, recipient_name and delivery address, but has no
  recipient-country field.
- A supplier selection deliberately leaves customer_id null.
- [DeliveryNoteSubmitter::buildCounterpart](app/Services/Delivery/DeliveryNoteSubmitter.php)
  derives country only from note.customer.country and otherwise defaults to GR.
- Therefore every supplier/manual foreign recipient is serialized as GR. The
  delivery normalizer also lacks the EL-to-GR and UK-to-GB aliases used by the
  monetary invoice builder.

**Required change**

- Add and freeze recipient_country on the delivery note.
- Populate it from either customer, supplier or manual operator input.
- Use one shared ISO normalizer across invoice and delivery payloads.
- Refuse an absent/unrecognized country instead of defaulting an external party
  to Greece. Reserve a deliberate GR default only for verified internal movement.

**Acceptance**

- Customer, supplier, manual and internal-movement recipient scenarios serialize
  the expected ISO country.
- Tests cover GR/EL, EU, non-EU and UK/GB aliases.

### MYD-012 — Seeded correlated delivery type 9.1 has no correlation model

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

AADE defines 9.1 as a correlated delivery note. The invoice header field
correlatedInvoices carries the related document MARK values.

**Repository evidence**

- The standard seed exposes 9.1 and
  [DeliveryNoteForm::deliveryTypeOptions](app/Filament/Resources/DeliveryNotes/Schemas/DeliveryNoteForm.php)
  offers every tenant 9.x type.
- DeliveryNote has an optional invoice_id link, but no explicit list of related
  AADE MARKs.
- [DeliveryNoteSubmitter::buildAadeDeliveryNote](app/Services/Delivery/DeliveryNoteSubmitter.php)
  treats 9.1, 9.2 and 9.3 alike and never calls addCorrelatedInvoice.
- An operator can therefore choose a document explicitly labelled correlated
  without selecting or transmitting the correlation.

**Required change**

- Require one or more valid related MARKs for 9.1 and emit correlatedInvoices.
- Define whether invoice_id is merely stock dedup metadata or an allowed source
  of the correlation; do not infer silently.
- Hide 9.1 until the required selection and validation exist.

**Acceptance**

- 9.1 cannot be issued without a valid same-tenant correlation.
- Preview XML contains the selected MARKs; ordinary 9.3 remains uncorrelated.

### MYD-013 — RegisterTransfer can omit mandatory transportType

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Official finding**

Digital Delivery Note v2.0.1 marks vehicleNumber, transportType and
carrierVatNumber as mandatory in TransportDetailType. transportType accepts 1–7;
vehicleNumber is mandatory when transportType is not 7.

**Repository evidence**

- The Filament form correctly requires transport_type.
- [DeliveryLifecycleService::registerTransfer](app/Services/Delivery/DeliveryLifecycleService.php)
  nevertheless treats it as optional and only emits it when a valid enum happens
  to be present.
- A console/API/imported record can therefore reach the service without the
  mandatory field and receive an avoidable AADE rejection.
- An invalid value is omitted rather than rejected with an actionable local error.

**Required change**

- Make transportType a service-level required enum.
- Validate vehicle rules against the selected type, including type 7.
- Keep the UI requirement, but do not rely on UI validation as the legal gate.

**Acceptance**

- Every caller fails locally on missing/invalid transportType.
- Valid types 1–7 produce the required payload and have lifecycle tests.

### MYD-014 — Expense cancellations are detected but cannot be applied

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Official finding**

RequestDocs returns documents, classifications and cancellations submitted by
other users. A supplier cancellation is therefore part of the authoritative
inbound state.

**Repository evidence**

- [ExpenseReconciler](app/Services/MyData/ExpenseReconciler.php) correctly folds
  cancelledByMark and cancelledInvoicesDoc and reports a stateMismatch.
- [ExpenseImporter](app/Services/MyData/ExpenseImporter.php) skips any existing
  MARK, including an existing VALID expense that the supplier later cancelled.
- [MyDataConsoleExpenses](app/Filament/Pages/MyDataConsoleExpenses.php) can import
  missing documents but provides no operator action equivalent to
  SyncInvoiceStateFromAade for an existing expense.
- myDATA-sourced expenses are otherwise treated as read-only, so the mismatch can
  remain indefinitely.

**Required change**

- Add an explicit audited expense-state sync from AADE.
- Persist cancelled_by_mark and a forensic ExpenseMark state-sync row.
- Exclude locally CANCELLED expenses consistently from local accounting views.
- Consider safe scheduled auto-sync only after the operator flow is proven.

**Acceptance**

- A supplier cancellation changes an existing local expense from VALID to
  CANCELLED without re-importing or duplicating it.
- Re-running reconciliation moves the row from mismatch to matched.

### MYD-015 — POS return type 8.5 increases the VAT-picture totals

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Official finding**

AADE type 8.5 is Απόδειξη Επιστροφής POS, the return counterpart of type 8.4.
Its economic direction is a return, not additional collection.

**Repository evidence**

- [Codes::INCOME_TYPE_PREFIXES](app/Support/MyData/Codes.php) classifies every
  8.x document as income.
- [Codes::CREDIT_NOTE_TYPES](app/Support/MyData/Codes.php) does not include 8.5,
  so documentSign returns +1.
- [MyDataVatAggregator](app/Services/MyData/MyDataVatAggregator.php) adds sign
  times net/VAT/gross for every income type.
- Consequently a positive-magnitude 8.5 returned by RequestTransmittedDocs
  increases the displayed output totals instead of reducing them.

**Required change**

- Give 8.5 the correct negative reporting sign.
- Replace prefix-only 8.x assumptions with an explicit reviewed policy for
  8.1, 8.2, 8.4, 8.5 and 8.6.
- Add fixture tests containing an 8.4 collection, 8.5 return and 8.6 order so
  payment/order documents cannot distort sales totals.

**Acceptance**

- A 100-euro 8.4 followed by a 40-euro 8.5 contributes net 60 euros to the
  relevant displayed bucket, never 140.
- 8.6 zero-value orders do not create revenue.

### MYD-016 — Delivery units silently become pieces

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Official finding**

measurementUnit is a coded legal quantity meaning: pieces, kilos, litres, metres,
square metres, cubic metres or other pieces. Substituting one code for another
changes the meaning of the movement line.

**Repository evidence**

- The delivery form offers the official 1–7 set.
- [DeliveryNoteSubmitter::buildAadeDeliveryNote](app/Services/Delivery/DeliveryNoteSubmitter.php)
  defaults a missing unit to 1 and clamps every out-of-range value to 1.
- Thus a bad/imported value intended as kilos or another unit can be filed as
  pieces without an error or audit indication.

**Required change**

- Require an explicit valid 1–7 unit at the service boundary.
- Default to pieces only when a newly-created UI line visibly starts as pieces,
  not as a repair for persisted invalid data.
- For unit 7, implement and validate the accompanying other-unit quantity/title
  fields before offering it where required.

**Acceptance**

- Missing/out-of-range persisted units block submission with an actionable error.
- Each supported code survives preview/submission unchanged.

### SETUP-001 — No guided first-valid-invoice onboarding

**Status:** OPEN · **Priority:** P1

**Evidence**

- Production install correctly avoids demo customer/product data.
- The invoice form requires a customer, including for ΑΛΠ.
- Customer cannot be created inline and no «Λιανική» customer is seeded.
- A product can now be created inline; product categories, delivery methods and
  standard lookups are already seeded.
- [`install/done.blade.php`](resources/views/install/done.blade.php) presents
  infrastructure reminders, not a business onboarding flow.
- `mydata_mode=off` is the correct safe install default, but there is no guided
  transition through credentials → sandbox → production.

**Required change**

Add a resumable first-run checklist/wizard:

1. Complete issuer identity.
2. Configure myDATA credentials and choose sandbox/production deliberately.
3. Review business classification and VAT defaults.
4. Choose a default payment method.
5. Create first customer (or retail customer policy).
6. Create first product/service.
7. Build and submit a sandbox test document.
8. Run go-live/preflight and show actionable failures.

### SETUP-002 — Installer accepts insufficient issuer data

**Status:** OPEN · **Priority:** P1

**Evidence**

- The web installer captures company name, country and optional AFM.
- A direct myDATA submission cannot succeed without issuer AFM.
- Delivery notes and correct legal/PDF identity need complete address data.

**Required change**

- Either require AFM for a GR myDATA tenant during install, or make «Complete
  issuer details» an unavoidable onboarding gate.
- Require name, AFM, address, city, postcode and country before declaring filing
  readiness.
- Keep pure PDF/offline tenants possible through an explicit choice.

### SETUP-003 — Missing payment method silently becomes cash

**Status:** OPEN · **Priority:** P1

**Evidence**

- All eight official methods are seeded.
- `payment_method_id` is optional in [`InvoiceForm`](app/Filament/Resources/Invoices/Schemas/InvoiceForm.php).
- [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php) falls
  back to myDATA payment method 3 (cash) when no valid mapping is available.

**Required change**

- Require a payment method before filing, or set a visible tenant/type/customer
  default.
- Do not silently convert an unknown/unmapped selection to cash.
- Add a pre-submit validation test.

### SETUP-004 — EE tenants skip neutral lookups

**Status:** OPEN · **Priority:** P2

**Evidence**

- [`Install`](app/Console/Commands/Install.php) calls standard lookup seeding
  only when `country=GR`.
- EE therefore receives neither AADE-specific rows nor neutral units, payment
  methods, delivery methods and product categories.

**Required change**

Split the seed into:

- country-neutral commercial lookups; and
- GR/myDATA-specific VAT, invoice and classification lookups.

Run the neutral portion for EE.

### OPS-001 — Scheduler and queue are implemented but not provisioned/proved

**Status:** OPEN · **Priority:** P1

**Verified implementation**

- [`routes/console.php`](routes/console.php) contains the real Laravel schedule.
- [`config/ekdosi.php`](config/ekdosi.php) provides per-task enable/timing defaults.
- [`ScheduleSettings`](app/Filament/Pages/ScheduleSettings.php) exposes DB overrides.
- Default queue connection is `database`.
- A tiny queued heartbeat runs every five minutes and `ops:health` can detect a
  dead scheduler/worker.
- Scheduled jobs use bounded `withoutOverlapping` locks and per-tenant isolation
  where appropriate.
- [`INSTALL.md`](INSTALL.md) documents a VPS cron and systemd queue service.

**External runtime contract**

The repository does not run any scheduled task until the host has:

~~~cron
* * * * * cd /var/www/ekdosi && php artisan schedule:run >> /dev/null 2>&1
~~~

It also needs a continuously supervised worker, for example:

~~~text
php artisan queue:work --queue=default --tries=3 --max-time=3600
~~~

The installer cannot prove those host-level services are active merely by writing
configuration.

**Required change**

- Make the completion screen distinguish «application installed» from
  «scheduler/worker verified».
- Poll the scheduler and queue heartbeats after provisioning and keep the
  go-live status non-green until both are fresh.
- Link directly to the relevant `INSTALL.md`/runbook sections.
- Consider generating copy/paste service definitions using the actual PHP binary
  and application path.

### OPS-002 — Env/application-root writability is not a hard preflight

**Status:** OPEN · **Priority:** P2

**Evidence**

- `EnvWriter` performs an atomic env-file replacement.
- Migrations and `ekdosi:install` run before the final env write/installed marker.
- `RequirementsChecker` validates extensions and storage/cache paths but does
  not prove that the application root/`.env` target is writable.

This means the env write can fail after the database already contains migrated
schema/company/admin data. Retry is designed to be idempotent, but the complete
install is not one atomic transaction.

**Required change**

- Add a safe writability/replacement preflight for the exact env target directory.
- Surface a hard blocker before any DB mutation.
- Add a failure/retry test proving no duplicate tenant/admin is created.

### OPS-003 — Shared-hosting/cPanel deployment path is incomplete

**Status:** OPEN · **Priority:** P2

**Evidence**

- VPS/systemd instructions exist in `INSTALL.md`.
- The short go-live runbook does not contain a shared-hosting worker recipe.
- The install completion page does not link directly to a cPanel-specific path.

**Required change**

Document and test a shared-hosting fallback, for example a once-per-minute cron
with overlap protection running:

~~~text
php artisan queue:work --stop-when-empty --queue=default --tries=3
~~~

This is less responsive than a supervised worker but is appropriate where
systemd/Supervisor is unavailable. Document PHP binary discovery, application
path, lock/overlap protection, scheduler cron and log location.

### TEST-001 — Missing end-to-end installer proof and independent green CI

**Status:** OPEN · **Priority:** P2

**Evidence**

- Installer guard, requirement, env writer and CLI command tests exist.
- There is no complete browser/HTTP success-path test proving:
  `POST /install → migrations → ekdosi:install → env → installed marker`
  against a real MariaDB target.
- The most recent inspected PR #383 workflow ended before runner allocation
  (no steps/logs). That is an Actions/runner infrastructure failure, not evidence
  of an application regression, but it also is not a green proof for this baseline.

**Required change**

- Add a disposable-MariaDB web installer success test.
- Cover empty DB, allowed existing DB, env-write failure/retry and final route lock.
- Restore a completed green GitHub Actions run on main.

### DEP-001 — firebed/aade-mydata upstream status

**Status:** WATCH · **Priority:** P2

**Checked 2026-08-29**

| Item | Result |
|---|---|
| Composer constraint | `firebed/aade-mydata: ^5.10` |
| Locked version | `v5.10.4` |
| Locked commit | [`6572afb6779cfcb31bdc8fb7cc2a7721eb7ace79`](https://github.com/firebed/aade-mydata/commit/6572afb6779cfcb31bdc8fb7cc2a7721eb7ace79) |
| Upstream default branch | `5.x` |
| Upstream HEAD | `6572afb6779cfcb31bdc8fb7cc2a7721eb7ace79` |
| Compare v5.10.4…HEAD | identical; ahead 0, behind 0 |
| Supported schema | myDATA v2.0.1 XSDs |
| Upgrade required now | **No** |

Notes:

- GitHub's latest formal Release object is v5.10.0, but upstream tags continued
  through v5.10.4. The lock is on the current v5.10.4 tag and exact branch HEAD.
- The package contains the v2.0.1 invoice and Digital Goods Movement XSDs/APIs.
- Being current does not resolve MYD-001–MYD-005 automatically: Ekdosi owns custom
  lookup defaults, UI routing and [`AadeInvoiceDocument`](app/Services/EInvoice/AadeInvoiceDocument.php).
- AADE already publishes a preofficial v2.0.2 in the
  [test environment](https://www.aade.gr/mydata-ilektronika-biblia-aade/mydata/dokimastiko-periballon).
  Keep this item in WATCH until v2.0.2 becomes production/final and the package
  publishes corresponding support.

Re-check this entry when any of the following happens:

- AADE changes the production technical-specification version.
- `firebed/aade-mydata` publishes a new tag.
- Composer changes the locked commit.
- A new delivery-note lifecycle endpoint is adopted by Ekdosi.

## Updater audit

### Audit baseline and verdict

- Audited branch/commit: [`main@b3b9243`](https://github.com/chrismfz/ekdosi/commit/b3b9243ff7a3edae59f5df7446ba4b75b92447a7)
- Audit date: 2026-08-29
- Current app version in `config/app.php`: `1.14.0`
- Highest repository tag: `v1.14.0`
- GitHub Releases currently present: none; tag fallback is therefore the active discovery path.

**Verdict:** the read-only update checker and the operator-run
[`deploy/update.sh`](deploy/update.sh) pipeline have a strong base. The default
one-click `php` strategy is not yet safe to call production-ready for a
multi-tenant money application with active workers. Do not rely on the UI apply
path until UPD-001–UPD-004 are closed.

### UPD-001 — PHP update and rollback do not quiesce the queue

**Status:** OPEN · **Priority:** P0

**Evidence**

- [`SelfUpdate::runPhp()`](app/Console/Commands/SelfUpdate.php) enters
  maintenance, snapshots, checks out code, installs dependencies and migrates
  without stopping/draining the queue worker.
- [`SelfUpdate::runRollback()`](app/Console/Commands/SelfUpdate.php) likewise
  snapshots and restores the whole DB without draining the worker.
- Laravel maintenance mode stops workers from taking **new** jobs, but it does
  not cancel a job already executing. See
  [Laravel 12 maintenance mode and queues](https://laravel.com/framework/docs/12.x/queues#maintenance-mode-and-queues).
- [`deploy/update.sh`](deploy/update.sh) and [`deploy/rollback.sh`](deploy/rollback.sh)
  already recognize this risk and implement optional queue stop/start hooks.
- Even the shell strategy proceeds after only a warning when no stop hook/unit
  exists.

**Risk**

A long import, email, myDATA reconciliation or other in-flight job can write
during the snapshot/migration/restore window. A later rollback can then lose
locally committed data or an AADE-related state written after the snapshot.

**Required change**

- Add a host-neutral queue-quiescence protocol to the PHP strategy.
- Refuse schema migration/DB restore unless worker quiescence is proven.
- Keep explicit VPS stop/start hooks; for shared hosting add a cooperative
  “drain requested / active jobs = 0” handshake with a bounded timeout.
- Make “no stop mechanism” a hard blocker for DB-changing updates, not a warning.

**Acceptance**

- An integration test starts a long job, requests update/rollback and proves no
  checkout/migration/restore begins until the job exits.
- A timeout/failure to drain aborts before code or DB mutation.

### UPD-002 — Failure after partial apply fails open

**Status:** OPEN · **Priority:** P0

**Evidence**

- [`SelfUpdate::failRun()`](app/Console/Commands/SelfUpdate.php) runs
  `artisan up` whenever the command had entered maintenance, including failures
  after checkout, Composer or migration.
- It marks `wentDown=false` without checking the exit status of the `up`
  subprocess.
- This overrides the deliberately safer behavior documented and implemented by
  the shell scripts: a half-applied deployment stays down.
- The class-level comment still says failures leave the app down, while the
  implementation and later design notes say the opposite.

**Risk**

The web app and workers can resume on new code with incomplete dependencies,
half-applied migrations, stale opcache or an otherwise inconsistent schema.
The panel that is supposed to offer rollback may itself be unable to boot.

**Required change**

Use phase-aware recovery:

- Pre-check/snapshot failure before checkout: safe to lift maintenance.
- Failure after checkout/composer/migrate starts: remain down, or automatically
  restore code + DB and prove health before lifting.
- Check every recovery subprocess exit code.
- Persist a durable emergency recovery instruction outside the application DB/log
  so it remains available when Laravel cannot boot.

**Acceptance**

Injected failures at checkout, Composer and migration never expose a partially
applied application. Each ends either safely down or automatically restored and
health-verified.

### UPD-003 — Update target is not locked to an immutable SHA

**Status:** OPEN · **Priority:** P0

**Evidence**

- [`UpdateChecker`](app/Services/Updates/UpdateChecker.php) returns the latest
  tag/version but not the commit SHA behind it.
- [`SystemHealth::installUpdate()`](app/Filament/Pages/SystemHealth.php) stores
  the tag in `to_ref`.
- [`SelfUpdate`](app/Console/Commands/SelfUpdate.php) force-fetches tags and
  checks out that tag later.
- The design document says “lock the exact SHA and re-verify”, but this is not
  implemented.

**Risk**

A tag can be moved between operator confirmation and apply. The code installed
may therefore differ from the code the operator saw/approved. The PHP strategy
also has no downgrade/ancestry guard equivalent to `deploy/update.sh`.

**Required change**

- Resolve and store `target_sha` during the fresh authenticated check.
- Immediately before checkout, resolve the remote tag again and require exact
  equality with the stored SHA.
- Checkout the SHA, retain the tag only as display metadata.
- Reject unexpected ancestry/downgrades and optionally verify annotated tag or
  commit signatures according to the release policy.

### UPD-004 — Apply is offered without proven rollback readiness

**Status:** OPEN · **Priority:** P0

**Evidence**

- `from_ref` comes from `BuildInfo::sha()` and may be null when
  `storage/app/build.json` was never stamped.
- The install action does not require a current SHA before queuing.
- Rollback later requires `from_ref`, so the UI can promise a rollback point
  that cannot restore the previous code.
- The button does not preflight `.git`, `proc_open`, Git/Composer,
  `mysqldump`/MySQL client, writable code/vendor/storage, free disk, readable
  target tag, fresh scheduler heartbeat or a successful snapshot capability.

**Required change**

Add an explicit dry-run/preflight result and hide/block Apply until all hard
requirements pass. Store both current and target full SHAs. Require a fresh
scheduler heartbeat because the apply runs from `schedule:run`.

**Acceptance**

A queued update always has non-null full `from_sha` and `to_sha`, and the UI
can prove that the scheduler will pick it up and a snapshot can be created.

### UPD-005 — Maintenance mode blocks both live progress and opcache flush

**Status:** OPEN · **Priority:** P1

**Evidence**

- Updater uses `artisan down --retry=15` without a bypass secret.
- Laravel returns the maintenance 503 for all HTTP requests unless a bypass is
  configured. See
  [Laravel 12 maintenance mode](https://laravel.com/framework/docs/12.x/configuration#maintenance-mode).
- Therefore the operator cannot actually watch the promised live-polling
  `UpdateRun` page while the update is running.
- [`flushOpcache()`](app/Console/Commands/SelfUpdate.php) self-hits the signed
  web route **before** `artisan up`, so it normally receives 503. It logs the
  HTTP status but does not require success.

**Required change**

- Provide a secure maintenance bypass/status channel for the initiating
  super-admin, or describe progress as unavailable during maintenance.
- Move the FPM opcache reset to a reachable point or explicitly exempt only the
  signed route from maintenance.
- Require HTTP 2xx plus `opcache_reset=true` when opcache timestamps cannot
  provide a safe fallback.

### UPD-006 — No recovery for a stale running update

**Status:** OPEN · **Priority:** P1

A power loss, killed cron process or timeout after status becomes `running`
leaves the row active forever. `hasActive()` then blocks new update and rollback
actions, while the scheduler only selects `queued` rows.

Add a heartbeat/lease to `UpdateRun`, detect stale runs, inspect the maintenance
and deployed-build state, and offer an explicit “resume / rollback / mark failed”
recovery flow. Never automatically retry a migration/restore without knowing the
last completed durable phase.

### UPD-007 — Critical post-update health can still be green in history

**Status:** OPEN · **Priority:** P1

The PHP strategy runs `ops:health` with `allowFailure=true`; Shield generation,
role sync and opcache are also advisory. The shell script logs a critical health
exit but still returns success. The wrapper therefore writes `status=succeeded`
even when the worker is dead or health is critical.

Add `succeeded_with_warnings`/verification state or fail the run on critical
health. Do not label the update complete until the new build, migrations, queue
heartbeat and required permissions are verified.

### UPD-008 — In-app snapshots bypass the retention policy

**Status:** OPEN · **Priority:** P1

The PHP strategy creates `update-*.sql.gz` and rollback creates
`rollback-*.sql.gz`, while [`DbSnapshot::prune()`](app/Console/Commands/DbSnapshot.php)
only prunes `ekdosi-*.sql.gz`. Passing `--keep=10` therefore does not prune
either in-app naming scheme. Full DB snapshots containing business data and
secrets accumulate indefinitely.

Implement a common snapshot registry/retention policy that preserves snapshots
still referenced by rollbackable runs and securely removes expired unreferenced
update/rollback snapshots.

### UPD-009 — Preflight exists in the design, not in the UI

**Status:** OPEN · **Priority:** P1

The action only checks that update checking is enabled, a repo exists, a newer
version was cached and no active row exists. Failures such as no cron, dirty tree,
missing Composer/client binaries, insufficient disk or unwritable vendor occur
after the operator has already queued the run.

Implement the documented dry-run preview: exact target commit, commits/migrations,
strategy, current/target versions, dependency tools, writable paths, disk,
snapshot probe, worker-drain capability and scheduler freshness.

### UPD-010 — Script strategy invokes a Bash script with `sh`

**Status:** OPEN · **Priority:** P1

[`SelfUpdate::runScript()`](app/Console/Commands/SelfUpdate.php) executes
`['sh', deploy/update.sh, target]`, but the script uses Bash-only syntax
(`[[ ... ]]`, `set -o pipefail`, functions/conditionals) and declares a Bash
shebang. This breaks wherever `/bin/sh` is not Bash.

Invoke the executable directly after validating permissions, or explicitly call
a discovered `bash` binary. Add a portability test.

### UPD-011 — Tests do not execute the updater lifecycle

**Status:** OPEN · **Priority:** P1

[`SelfUpdateCommandTest`](tests/Feature/Updates/SelfUpdateCommandTest.php) covers
only “nothing queued” and “row not queued”. No test executes checkout, snapshot,
Composer, migration, maintenance recovery, opcache, health or DB rollback.
Shell scripts also have no automated behavior tests.

Build a disposable test harness with a temporary Git repository and MariaDB,
replace external binaries with controlled fixtures, and inject failure at every
durable phase. At minimum prove happy update, pre-check abort, snapshot abort,
Composer failure, migration failure, crash recovery and full rollback.

### UPD-012 — A read-only setting implicitly arms code deployment

**Status:** OPEN · **Priority:** P1

The setting/help text in [`GeneralSettings`](app/Filament/Pages/GeneralSettings.php),
[`SystemHealth`](app/Filament/Pages/SystemHealth.php), the Blade view,
configuration comments and parts of
[`versioning-and-updates.md`](docs/versioning-and-updates.md) still describe the
feature as read-only. In reality, enabling the check and supplying a repository
token also exposes one-click apply; there is deliberately no separate arming flag.

Separate `EKDOSI_UPDATE_CHECK` from an explicit default-OFF
`EKDOSI_UPDATE_APPLY` capability. Update all UI/help/docs to state the actual
behavior and show the active strategy.

### UPD-013 — Git token remains in the process environment

**Status:** OPEN · **Priority:** P2

The temporary askpass file is removed and output is redacted correctly, but
`makeAskpass()` calls `putenv('EKDOSI_GIT_TOKEN=...')` and never unsets it.
Later Composer and Artisan subprocesses may inherit the token.

Pass the token only in the Git process environment and unset it in a `finally`
block. Add a test proving later subprocess environments do not contain it.

### UPD-014 — A formal GitHub Release can hide newer tag-only releases

**Status:** OPEN · **Priority:** P2

The checker uses `releases/latest` whenever any Release exists and only falls
back to tags on 404. The documented release flow pushes tags but does not create
GitHub Releases. The repository currently has no Releases, so fallback works
today; if one Release is created and later versions remain tag-only, the checker
can stay pinned to the older Release.

Fetch both signals and choose the highest valid stable SemVer, or standardize the
release process so every production tag always creates a GitHub Release.

### UPD-015 — Single-flight is not atomic at the command boundary

**Status:** OPEN · **Priority:** P2

The UI performs `hasActive()` followed by `create()` without a transaction or
unique DB guard. The scheduler has `withoutOverlapping`, but a manual
`--run=<id>` invocation does not acquire the same global lock. Two near-simultaneous
operators or a manual command can therefore create/concurrently claim work.

Claim queued rows atomically and acquire one shared update lock inside the command
for UI, scheduler and manual invocations.

### Updater verified baseline — do not regress

- Update history and actions are super-admin-only and cross-tenant.
- Web actions only queue work; the apply does not run inside the request or on
  the worker it restarts.
- Successful update checks are cached and network failures degrade gracefully.
- Current repository discovery correctly falls back to tags because there are no
  GitHub Releases; `config/app.php` and the highest tag are both `1.14.0`.
- PHP strategy refuses a dirty working tree and requires `.git` + `proc_open`.
- Git fetch uses an askpass helper rather than putting the token in argv or
  `.git/config); persisted output redacts the configured token.
- Composer installs the committed lock with `composer install`, never
  `composer update`.
- A local whole-DB snapshot is mandatory before code checkout.
- Rollback is intentionally destructive and clearly warns that post-update
  invoices/payments can be lost; it takes a fresh safety snapshot first.
- The shell scripts drain a configured worker, snapshot, migrate, optimize,
  restart and run health checks in the correct broad order.
- Update output is capped in the DB and also persisted per run under
  `storage/logs/updates/`.
- Scheduler execution is gated on pending rows and uses an overlap lock.

## Scheduler inventory

The code-side schedule exists. The current defaults below still require the
external once-per-minute `schedule:run` invocation.

| Task group | Default cadence | Default |
|---|---|---|
| Queue heartbeat | every 5 minutes | ON |
| Mail orphan sweep | every 15 minutes | ON |
| Failed invoice email retry | hourly at :30 | OFF |
| WHMCS fetch | every 15 minutes | ON |
| WHMCS auto issue | every 15 minutes | OFF |
| WHMCS payment sync/reconcile | every 30 minutes | OFF |
| myDATA sales reconciliation | daily 06:00 Europe/Athens | ON |
| myDATA VAT-picture refresh | every 4 hours | ON |
| myDATA expenses refresh | every 6 hours | OFF |
| myDATA console warmup | every 6 hours | OFF |
| Overdue notifications | daily 07:30 Europe/Athens | OFF |
| Service renewal staging | daily 07:00 Europe/Athens | OFF |
| Service dunning sweep | daily 08:00 Europe/Athens | ON; product opt-in still required |
| AI reminder dispatch | every minute | ON; feature master switch still applies |
| Whole-DB backup run/clean/monitor | 02:00 / 02:30 / 08:00 | ON |
| Per-company backups | hourly due sweep | OFF |
| Pending self-update | every minute | ON; no-op unless queued |
| Failed queue prune | daily, retain 14 days | ON |

## Verified working baseline — do not regress

These are not open issues:

- Pre-APP_KEY web installer access is token protected and does not depend on
  session/CSRF state.
- Database test and explicit non-empty-DB override protect against accidental use
  of the wrong database.
- Env replacement itself is atomic.
- Migrations, `ekdosi:install --force`, standard GR lookup seeding, Shield
  generation and tenant role provisioning are wired.
- The installed marker closes `/install` after completion.
- GR seed includes standard VAT rows 1–7, all eight payment methods, commercial
  metric units, delivery methods, product categories and 15 document-type rows.
- Core domestic mappings for ΤΙΜ 1.1, ΤΠΥ 2.1, ΑΛΠ 11.1 and ΑΠΥ 11.2 are
  sensible baseline defaults.
- Lookup seeding fills empty fields and preserves operator edits.
- Standalone Delivery Notes have a separate coded quantity/unit submission path.
- `mydata_mode=off` is an intentional safe install default, not a defect.
- `firebed/aade-mydata` is currently fully up to date with its upstream 5.x branch.

## Change log

| Date | Change | Commit/PR |
|---|---|---|
| 2026-08-29 | Initial combined installer/myDATA/cron/dependency audit ledger | [`fe20e73`](https://github.com/chrismfz/ekdosi/commit/fe20e73dc259254698b4ed0390994a154d545fc8) |
| 2026-08-29 | Added full updater integrity, rollback, queue and recovery audit | [`d2ca379`](https://github.com/chrismfz/ekdosi/commit/d2ca3792b4a0c37f4ed7c76d8829ce7c5226b181) |
| 2026-08-30 | Re-researched MYD-001–MYD-006, corrected priorities/wording and added MYD-007 | Documentation-only audit |
| 2026-08-30 | Extended myDATA audit: added MYD-008–MYD-016 | Documentation-only audit |
