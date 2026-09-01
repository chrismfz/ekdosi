# Known issues and readiness ledger

This file is the working source of truth for installer, first-run setup, myDATA,
scheduler/queue, Provider/ΥΠΑΗΕΣ and production-readiness work. Keep completed entries in the file:
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

## Final myDATA and lifecycle pass — 2026-08-30

This pass reviewed reconciliation equality, immutable filing identity, externally
changed delivery-note state, non-VAT tax terminology and stock compensation on
cancellation. The payload findings were checked against the production ERP v2.0.1
specification; legacy names were not treated as payload defects when the emitted
numeric tax type remains correct.

| ID | Verdict | Priority | Confirmed impact |
|---|---|---:|---|
| MYD-017 | Confirmed | P0 | Reconciliation can label a different local amount/header as matched |
| MYD-018 | Confirmed | P0 | Editing a shared series/type can change a numbered but not-yet-filed payload |
| MYD-019 | Confirmed | P1 | Remote delivery-note cancellation updates only one of three local state fields |
| MYD-020 | Confirmed terminology debt | P2 | UI/docs still call Digital Transaction Fee “stamp duty” and cite shifted sections |
| STOCK-001 | Confirmed | P1 | Delivery-note and credit-note cancellation can leave stock movements active |


## Critical myDATA integrity sweep — 2026-08-31

This pass targeted service-boundary and crash-window defects rather than adding
more lookup mappings. It reviewed direct myDATA invoice/delivery issue,
cancellation evidence, tenant coherence, immutable issuer identity, delivery
lifecycle events and retention of the legal audit trail at
[`main@4b9bd26`](https://github.com/chrismfz/ekdosi/commit/4b9bd26a0a6a94fd8abb63d9b945ac696a016c34).

| ID | Verdict | Priority | Confirmed impact |
|---|---|---:|---|
| MYD-021 | Confirmed | P0 | A crash after AADE acceptance can still permit a blind duplicate issue; delivery notes lack even the invoice lock |
| MYD-022 | Confirmed | P0 | A crafted service/API/CLI call can combine tenant B's document with tenant A's issuer identity and credentials |
| MYD-023 | Confirmed | P0 | Cancellation can become terminal without preserving the distinct AADE cancellation MARK |
| MYD-024 | Confirmed | P0 | Historical XML, recovery coordinates and regenerated PDFs use mutable current issuer identity |
| MYD-025 | Confirmed | P0 | Company delete/wipe can erase documents, MARKs and the legal audit trail |
| MYD-026 | Confirmed | P1 | Delivery lifecycle calls are not single-flight or durably recoverable after an ambiguous response |

## Provider / InvoSign / ΥΠΑΗΕΣ audit — 2026-08-30

This pass reviewed the full Ekdosi → InvoSign → AADE lifecycle: issue and
authentication, MARK/UID/QR persistence, provider document delivery, ambiguous
retries, credit notes, delivery-note cancellation, preflight, outage handling and
the October 2026 operational deadline.

Sources and current external baseline:

- [AADE A.1112/2025 — current Provider/ΥΠΑΗΕΣ obligations](https://www.aade.gr/sites/default/files/2025-08/a1112_2025fek.pdf)
- [AADE mandatory e-invoicing and Digital Delivery Note FAQ](https://www.aade.gr/ypohreotiki-ilektroniki-timologisi-psifiaka-parastatika-diakinisis-syhnes-erotiseis)
- [AADE production technical specifications](https://www.aade.gr/mydata/tehnikes-prodiagrafes-ekdoseis-mydata)
- [AADE licensed-provider register](https://www.aade.gr/mydata/adeiodotimena-logismika-parohon-ilektronikis-timologisis)
- [InvoSign public API guide](https://invosign.gr/site/help_site/)
- [InvoSign provider/product page](https://invosign.gr/)

Current external facts, checked 2026-08-30:

- iNVO Sign is listed by AADE as provider code **030**, licence
  **2025_05_130GVSolutions_001_iNVO Sign_V1_07052025**, with zero penalty points.
- The AADE register does not currently mark iNVO Sign for public-contract
  e-invoicing or All-in-one Cash Register/POS. This does not by itself prevent
  ordinary B2B/B2C use, but those two capabilities must not be implied by Ekdosi.
- For the second mandatory-e-invoicing period, the effective date is 2026-10-01.
  Parallel transition through 2026-12-31 is conditional on a timely declaration
  with a start date no later than 2026-10-01.
- The provider normally files the start declaration within ten days of the
  contract start; the issuer has ten days to accept/reject it. If the provider
  misses its window, the obligation passes to the issuer for a further ten days.
- The provider retaining the documents does not remove the issuer's independent
  accounting-record retention obligation.

Audit outcome:

| ID | Verdict | Priority | Confirmed impact |
|---|---|---:|---|
| MYD-008 | Confirmed cross-reference | P0 | A provider original cannot currently be used by correlated credit 5.1 |
| PROV-001 | Confirmed | P0 | Ambiguous invoice replies can be treated as a safe rejection and then re-sent |
| PROV-002 | Confirmed | P0 | Provider delivery-note timeouts have no status recovery at all |
| PROV-003 | Confirmed | P0 | Ekdosi emails/prints a local PDF missing mandatory provider evidence |
| PROV-004 | Confirmed | P0 | The credit UI allows incompatible 5.1/5.2/11.4 choices |
| PROV-005 | Confirmed | P1 | Provider preflight can be green with invalid token or missing mandatory issuer fields |
| PROV-006 | Sandbox/vendor verification required | P0 for retail | Anonymous retail may be rejected because InvoSign marks counterpart VAT/name mandatory |
| PROV-007 | Sandbox/vendor verification required | P1 | InvoSign print-extension discount fields may diverge from canonical totals |
| PROV-008 | Confirmed capability gap | P1 | Transmission Failure_1/2 and offline issue/recovery are not implemented |
| PROV-009 | Confirmed | P2 | UID, provider delivery feedback and remaining quota are not structured operational data |
| PROV-010 | Confirmed readiness gap | P0 | Contract/declaration/activation acceptance is not a go-live gate |
| PROV-011 | Vendor verification required | P1 | Public InvoSign API contract is unversioned and contains a cancellation-endpoint inconsistency |
| PROV-012 | Confirmed scope gap | P1 | Provider capability selection does not gate public-contract or All-in-one POS use |
| PROV-013 | Confirmed test gap | P0 | There is no complete InvoSign sandbox acceptance/failure matrix |
| PROV-014 | Confirmed | P0 | Issue is not single-flight and remains editable/cancellable while the provider call is in flight |
| PROV-015 | Confirmed | P0 | Provider cancellation can be falsely accepted or remotely succeed while remaining locally VALID |
| PROV-016 | Confirmed | P0 | Cancellation/correction uses mutable current provider and environment instead of the historical issue channel |
| PROV-017 | Confirmed | P1 | Arbitrary or plaintext provider endpoints can receive the token and full invoice payload |
| PROV-018 | Confirmed | P1 | Full-reversal actions fail after any earlier partial credit |
| PROV-019 | Confirmed | P0 | An unfiled draft credit is treated as legal reversal and does not block the replacement invoice |
| PROV-020 | Confirmed | P1 | Normal online InvoSign issue requires the current date, but Ekdosi accepts backdated/future provider documents |

### Provider hardening sweep — 2026-08-30

This additional pass audited concurrency, the post-response persistence window,
cancellation integrity, channel/environment cutover, endpoint trust and the
provider correction UI at commit
[`eb158fa6aa67905b511bc580d5362b4579feb9d0`](https://github.com/chrismfz/ekdosi/commit/eb158fa6aa67905b511bc580d5362b4579feb9d0).
It also re-checked the current InvoSign environment, status and cancellation
pages. The provider documents separate per-customer production/demo base URLs
and tokens; status lookup is by invoice coordinates; a successful delivery-note
cancellation example includes a distinct `cancellationMark`.

The sweep found six additional confirmed defects, PROV-014–PROV-019. They are
code-path findings, not assumptions about InvoSign behavior. Where recovery
requires a provider cancellation-status contract that is not public, the required
implementation remains conditional on written vendor confirmation.

### Required correction and credit compatibility matrix

The UI and service layer must enforce this policy; a free list of every
`is_credit=true` type is not sufficient.

| Original/provider document | Allowed correction/reversal | Required relationship | Explicitly disallow in that flow |
|---|---|---|---|
| Wholesale sale `1.x` / service `2.x` | `5.1` correlated credit | Original provider MARK in `correlatedInvoices` | `5.2` as a fake “cancellation” |
| Genuine non-document-specific turnover credit | `5.2` non-correlated credit | No original MARK required | Presenting it as reversal of one selected invoice |
| Retail `11.x` | `11.4` retail credit | Preserve the retail/provider correction semantics confirmed in sandbox | Wholesale `5.1/5.2` selected only because `is_credit=true` |
| Delivery note `9.3` | InvoSign `CancelDeliveryNote` | Existing MARK; persist returned cancellation MARK | Ordinary invoice cancellation endpoint |
| Wrong provider credit note | No blind cancel/re-credit shortcut | Accounting/provider-approved compensating flow | Pretending a provider credit can be deleted or locally cancelled |
| Any provider-issued value document | Credit/correction, never local-only cancellation | Original and correction both retain their own MARK/UID/authentication evidence | Changing only `local_status` |

For provider originals, MYD-008 must be fixed first: original MARK lookup must
accept both `INSERT` and `PROVIDER_INSERT`, remain tenant-scoped and select a
successful issue row only.

### Mandatory InvoSign sandbox acceptance matrix

No production activation is accepted until the following matrix records the sent
payload, raw response, provider portal result, AADE result, provider document,
local PDF and local persisted metadata for every row.

| Scenario | Required proof |
|---|---|
| `1.1` goods B2B | MARK, UID, authentication code, QR, provider document, matching amounts/classes |
| `2.1` services B2B | Same evidence; quantity/unit rules remain valid |
| `11.1` anonymous retail | Written/sandbox-confirmed counterpart-name/VAT convention |
| `11.2` anonymous retail service | Same retail convention and provider delivery proof |
| Full and partial `5.1` | Original provider MARK appears in correlation; balances and quantities reconcile |
| `5.2` | Available only through an explicitly non-correlated workflow |
| `11.4` | Retail correction accepted and linked/presented correctly |
| `9.3` issue | Provider MARK/UID/QR and delivery data match the portal |
| `9.3` cancel | Correct endpoint and persisted cancellation MARK; remote/local states agree |
| Timeout after provider accepts | Status lookup adopts the existing MARK; a second issue is impossible |
| Delayed status visibility | Durable in-doubt state blocks re-send until bounded recovery completes |
| HTTP 200 malformed XML | Treated as ambiguous, not deterministic rejection |
| `Success` without MARK | Status recovery runs; invoice remains blocked from blind retry |
| Wrong/expired token | Authenticated preflight fails before a real invoice |
| Header + line discounts | InvoSign document, AADE XML and Ekdosi totals are cent-identical |
| Provider→AADE Failure_2 | Correct provider state/indication and eventual MARK adoption |
| Two simultaneous issue requests | One durable attempt wins; only one provider POST and one legal document exist |
| Edit/local-cancel during issue | Mutation is blocked while the attempt is active; the stored local snapshot remains byte-consistent with the sent payload |
| DB failure after provider success | The pre-existing attempt remains in doubt and is reconciled; retry never performs a blind second POST |
| Cancel response lost | Local state remains cancellation-pending until provider/AADE evidence resolves it; no blind double-cancel |
| Cancel `Success` without `cancellationMark` | Treated as ambiguous/invalid, never as terminal local cancellation |
| Provider or sandbox/production switch | Historical issue, cancel and correction continue through the explicitly approved original channel policy |
| Partial credit followed by full reversal | Only remaining quantities are credited; no over-credit exception or duplicate quantity |
| Draft credit plus replacement | Original is not labeled legally reversed and replacement cannot file until the credit is provider-VALID |
| HTTP, private-network or unapproved provider endpoint | Configuration/preflight blocks submission before token or invoice data leave Ekdosi |
| Quota near zero/exhausted | Warning and hard failure are visible before business interruption |
| Provider document download fails | Filing stays VALID; artifact becomes pending and retries without re-filing |
| Provider document changes remotely | Hash mismatch raises an audit alert; original local artifact is not overwritten |
| Public contract / All-in-one POS request | Feature is blocked unless the current AADE register explicitly supports it |
| Cross-tenant document/service call | Rejected before preview, audit write or outbound request |
| Yesterday/tomorrow issue date | Normal online issue is blocked; a documented Transmission Failure flow is used where legally applicable |
| Tenant delete/company wipe | Filed documents, MARKs, provider artifacts and audit evidence remain immutable and exportable |

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
| MYD-001 | P0 | DONE | Classification | Third-country 1.3/2.3 use the intra-EU E3 code |
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
| MYD-013 | P1 | DONE | Delivery lifecycle | RegisterTransfer can omit the mandatory transportType |
| MYD-014 | P1 | OPEN | Expense sync | Supplier cancellation is detected but cannot update an existing local expense |
| MYD-015 | P1 | DONE | VAT picture | Type 8.5 POS return is added with a positive sign |
| MYD-016 | P1 | DONE | Delivery units | Invalid or missing coded unit is silently filed as pieces |
| MYD-017 | P0 | OPEN | Reconciliation | Same MARK/state is called matched without comparing amount, type or identity |
| MYD-018 | P0 | OPEN | Filing identity | Numbered invoices still read mutable series/type/classification defaults |
| MYD-019 | P1 | OPEN | Delivery sync | Remote cancellation leaves mydata_state/local_status unchanged |
| MYD-020 | P2 | DONE | Digital Transaction Fee | Legacy stamp-duty names and § references remain in UI/code |
| MYD-021 | P0 | OPEN | Direct idempotency | Direct issue is not protected by a durable pre-POST attempt; delivery notes also lack single-flight |
| MYD-022 | P0 | OPEN | Tenant isolation | Filing services do not prove that document, relations and credential tenant agree |
| MYD-023 | P0 | OPEN | Cancellation evidence | Direct cancellation MARKs are optional, lost or stored in the wrong field |
| MYD-024 | P0 | OPEN | Issuer identity | Historical filings and PDFs use mutable current company identity |
| MYD-025 | P0 | OPEN | Legal retention | Company delete/wipe can hard-delete documents, MARKs and audit evidence |
| MYD-026 | P1 | OPEN | Delivery lifecycle | Register/confirm events lack a durable single-flight/recovery state |
| PROV-001 | P0 | OPEN | Provider idempotency | Ambiguous invoice response is not durably blocked/recovered before re-send |
| PROV-002 | P0 | OPEN | Provider delivery notes | Timeout has no status recovery and can create a duplicate 9.3 |
| PROV-003 | P0 | OPEN | Provider documents | Customer PDF lacks required provider evidence and no official artifact is archived |
| PROV-004 | P0 | OPEN | Provider credits | UI/service do not enforce the 5.1/5.2/11.4 compatibility matrix |
| PROV-005 | P1 | OPEN | Provider preflight | Reachability is not token authentication and mandatory issuer fields are unchecked |
| PROV-006 | P0 | VERIFY | Provider retail | Anonymous InvoSign counterpart convention is not confirmed |
| PROV-007 | P1 | VERIFY | Provider totals | Header/line discount semantics of InvoSign api_* fields are not proven |
| PROV-008 | P1 | OPEN | Provider outage | Transmission Failure_1/2 issue and recovery lifecycle is absent |
| PROV-009 | P2 | OPEN | Provider observability | UID, reception feedback and remaining quota are not structured/surfaced |
| PROV-010 | P0 | OPEN | Provider activation | Contract, declaration and acceptance are not go-live gates |
| PROV-011 | P1 | VERIFY | Provider API | Version support and contradictory cancellation example need written confirmation |
| PROV-012 | P1 | OPEN | Provider scope | Public-contract/All-in-one POS capabilities are not gated from the AADE register |
| PROV-013 | P0 | OPEN | Provider tests | Required InvoSign sandbox success/failure matrix has not been completed |
| PROV-014 | P0 | OPEN | Provider concurrency | Issue is not single-flight and is not serialized against document mutation |
| PROV-015 | P0 | OPEN | Provider cancellation | Missing/lost cancellation evidence can create a false or split-brain terminal state |
| PROV-016 | P0 | OPEN | Provider cutover | Historical issue channel/environment is not frozen or used for later actions |
| PROV-017 | P1 | DONE | Provider endpoint security | Base URL is not constrained to HTTPS and an approved provider host |
| PROV-018 | P1 | OPEN | Provider partial credits | Full-reversal actions reuse original rather than remaining quantities |
| PROV-019 | P0 | OPEN | Provider correction state | Draft credit is treated as legal reversal and replacement is not filing-gated |
| PROV-020 | P1 | DONE | Provider issue date | Backdated/future online issue reaches InvoSign instead of failing actionable preflight |
| STOCK-001 | P1 | OPEN | Stock ledger | Cancelling delivery/credit documents does not fully compensate stock |
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

**Status:** DONE 2026-08-31 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix:** `Codes::TYPE_DEFAULTS` maps 1.3/2.3 → `E3_561_006`; 1.2/2.2 keep
`E3_561_005`. Seeder derives income class from `typeDefaults()` so ΕΞΑ/ΥΤΧ pick it
up automatically. Tests in `MyDataLookupSeederTest` assert the 005-vs-006 split and
the seeded ΕΞΑ/ΥΤΧ rows. Fill-empty behaviour unchanged (operator edits preserved).
See `CHANGELOG.md` [Unreleased] → Fixed.

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

**Partial (2026-08-31):** the code-10 label sub-item is DONE — `Codes::VAT_CATEGORY_LABELS[10]`
now reads «ΦΠΑ 4% (αρ.31 ν.5057/2023)» to match the official §8.2 table (the
unofficial «νήσων» is dropped; codes 4/5/6 remain the genuine island rates). The
substantive work below (shared VAT resolver, explicit code-9 seed, mandatory
6-vs-10 choice, 0%-without-reason blocking, per-line VAT-code snapshot) is still
OPEN. See `CHANGELOG.md` [Unreleased] → Fixed.

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
- [InvoSignDocument::invoiceCounterpartFields](app/Services/EInvoice/Transports/InvoSignDocument.php)
  mixes the invoice snapshot with live customer tax-office, phone and email
  fields, so the provider representation is not reproducible from the frozen
  document alone.

**Required change**

- Build the legal counterpart entirely from the invoice snapshot after issue.
- Permit a live-customer fallback only for a clearly identified legacy row whose
  snapshot is blank, and record that fallback.
- Validate that snapshot AFM, country and foreign name/address form one coherent
  party before submission.
- Build provider counterpart fields from the same immutable snapshot. If contact
  fields are intentionally live and non-legal, label and store that distinction
  instead of silently mixing them into the issue document.

**Acceptance**

- Editing a customer after invoice/credit creation does not change preview XML.
- A migration/backfill or explicit blocker handles older rows with blank snapshots.
- Direct and provider previews remain identical in legal counterpart identity
  after the customer record changes.

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

**Status:** DONE 2026-08-31 · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Fix:** `DeliveryLifecycleService::registerTransfer` now gates the mandatory
`TransportDetailType` fields at the service boundary (not just the Filament form):
a null/out-of-range `transport_type` throws an actionable local error instead of
being silently omitted (→ AADE rejection), and `vehicle_number` is required for
every `transportType` except 7 (Άνευ), for which the explicit placeholder is kept.
`DeliveryLifecycleServiceTest` adds missing/invalid transportType, missing-vehicle,
type-7-without-vehicle and payload-carries-both cases. See `CHANGELOG.md`
[Unreleased] → Fixed.

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

**Status:** DONE 2026-08-31 · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Fix:** `8.5` added to a new `Codes::REDUCING_EXTRA_TYPES` list read only by
`documentSign()`, so a POS return reduces the myDATA VAT picture
(`MyDataVatAggregator`). Kept OUT of `CREDIT_NOTE_TYPES` so credit-note identity
(`isCreditNoteType`) stays exact and the expense-side subtract rule in
`LedgerBook`/`VatPeriodReport` (matched against `expenses.invoice_type`, where an
income type 8.5 never appears) is untouched. The const doc-comment records the
explicit §8.x sign policy (8.1/8.2/8.4 = +, 8.5 = −, 8.6 order slip = + but
zero-value, not separately enforced). Aggregator fixture proves 100€ 8.4 + 40€ 8.5
→ net 60 (never 140); a `documentSign` policy test covers 8.4/8.5/8.6, credit/sales
regressions and that `isCreditNoteType('8.5')` stays false. `8.6` left unchanged.
See `CHANGELOG.md` [Unreleased] → Fixed.

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

**Status:** DONE 2026-08-31 · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Fix:** `DeliveryNoteSubmitter::buildAadeDeliveryNote` no longer defaults a missing
unit or clamps an out-of-range one to 1 — a persisted `measurement_unit` outside
§8.13 1–7 now throws an actionable local error instead of silently changing the
line's meaning. Unit 7 (Τεμάχια_Λοιπές Περιπτώσεις) requires
`otherMeasurementUnitQuantity/Title` (§8.13 note 9, mandatory) which are not
modelled, so it is explicitly blocked at the service and not offered to NEW lines
in the two delivery line-unit pickers via `Codes::selectableQuantityTypes()` (a
line already stored as 7 still shows it — state-aware options — so an unrelated
edit can't silently drop the value; the full `QUANTITY_TYPES` map is also kept for
DISPLAY of legacy rows). Full unit-7 support is
logged in `docs/BACKLOG.md`. `DeliveryNoteSubmitterTest` covers missing,
out-of-range, unit-7-blocked and a supported unit surviving unchanged. See
`CHANGELOG.md` [Unreleased] → Fixed.

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

### MYD-017 — Reconciliation can return a false green on different content

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

RequestTransmittedDocs and RequestDocs return the document header, parties and
summary, not only a MARK/state pair. These fields are available for a content-level
comparison.

**Repository evidence**

- [SalesReconciler](app/Services/MyData/SalesReconciler.php) fetches UID,
  series, AA, issue date, counterpart, gross and invoice type.
- Its diff() puts a row in matched whenever the MARK exists on both sides and
  the cancellation boolean agrees. Gross, series/AA, date, type and counterpart
  are not compared.
- [ExpenseReconciler](app/Services/MyData/ExpenseReconciler.php) applies the
  same MARK/state-only rule.
- [EnrichInvoiceFromAade](app/Services/MyData/EnrichInvoiceFromAade.php) can
  show several field differences for one selected document, but that does not
  protect the scheduled/global reconciliation result.

**Risk**

A post-filing local edit, incomplete import or wrong MARK association can still
produce a green “matched” count even when the legally relevant local content is
different from AADE. This is a false readiness/audit result.

**Required change**

- Add a separate contentMismatch bucket.
- Compare gross with an explicit cent tolerance, invoice type, series/AA, issue
  date and counterpart AFM where the type returns one.
- Keep state mismatch separate so operators know which repair action applies.
- Show which fields differ; never silently rewrite frozen values.

**Acceptance**

- A same-MARK/same-state row with a different gross, type or series/AA is not
  counted as matched.
- Unit tests cover sales and expenses, retail-without-counterpart and rounding
  tolerance.

### MYD-018 — Numbered filings still depend on mutable InvoiceType configuration

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official finding**

AADE v2.0.1 derives the document UID from issuer VAT, issue date, issuer branch,
invoice type, series and AA (plus deviation type where present). Series and type
are therefore filing identity, not mutable display metadata.

**Repository evidence**

- [AadeInvoiceDocument::build()](app/Services/EInvoice/AadeInvoiceDocument.php)
  reads series, myDATA type, income class/category and the quantity flag from the
  live invoiceType relation.
- [InvoiceTypeForm](app/Filament/Resources/InvoiceTypes/Schemas/InvoiceTypeForm.php)
  permits editing code and myDATA mappings after the row is in use.
- Line classification is resolved at send time through the live product/product
  category, while payment-method myDATA type and zero-VAT exemption mapping are
  also read from mutable tenant lookups.
- [IssueCreditNote](app/Actions/IssueCreditNote.php) and
  [ReissueInvoiceAsDraft](app/Actions/ReissueInvoiceAsDraft.php) retain product
  references rather than a complete frozen filing-policy snapshot.
- The invoice's mydata_type snapshot is stored only after a successful response;
  there is no frozen series/classification/quantity snapshot.
- The in-doubt recovery in
  [MyDataSubmitter](app/Services/MyDataSubmitter.php) also searches AADE using
  the current invoiceType.code. A series rename after an ambiguous POST can
  miss the already-created MARK and later submit a different identity.

**Risk**

Changing a shared lookup can alter the payload of an already-numbered draft or
retry. The printed invcode, AADE UID search coordinates and eventual filing can
then disagree, including a duplicate or wrongly classified filing.

**Required change**

- Freeze series, myDATA type, line/product classification, quantity policy,
  payment-method mapping, VAT code and exemption reason when the AA is
  allocated/finalization begins.
- Build, retry/adoption and forensic display from those snapshots.
- Prevent destructive edits of identity fields on an in-use series, or version
  the series by creating a new row for future documents.

**Acceptance**

- Editing an InvoiceType after an invoice is numbered cannot change that
  invoice's XML or its in-doubt lookup coordinates.
- New documents use the new/versioned configuration; old documents retain the
  original values.

### MYD-019 — Delivery status refresh does not fully apply remote cancellation

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Official finding**

GetDeliveryNoteStatus exposes the AADE delivery status and lifecycle, including
the terminal CANCELLED state. A local refresh must not leave the document
business-active after learning that terminal state.

**Repository evidence**

- [DeliveryLifecycleService::refreshStatus()](app/Services/Delivery/DeliveryLifecycleService.php)
  updates only delivery_state.
- A locally initiated cancellation correctly updates mydata_state=CANCELLED,
  delivery_state=cancelled and local_status=cancelled together.
- Therefore a cancellation performed outside Ekdosi can leave the same note as
  delivery_state=cancelled, mydata_state=VALID, local_status=active.

**Risk**

Different screens/actions can make opposite decisions about the same legal
document. A cancelled note can remain locally active, and downstream cancellation
side effects are skipped.

**Required change**

When a refresh returns CANCELLED, atomically synchronize all three state fields,
write a forensic state-sync audit event and run the same idempotent business
compensation as a local cancellation. Do not automatically resurrect a
business-cancelled note merely because a non-terminal remote status is returned.

**Acceptance**

- An AADE-side cancellation followed by refresh produces the same terminal local
  state and audit trail as an Ekdosi-side cancellation.
- Repeated refresh is idempotent.

### MYD-020 — Digital Transaction Fee still appears as legacy stamp duty

**Status:** DONE 2026-08-31 · **Priority:** P2 · **Research:** CONFIRMED, PAYLOAD NOT MIS-MAPPED 2026-08-30

**Fix:** Operator-facing labels/help now say «Ψηφιακό Τέλος Συναλλαγής» (taxType 4)
instead of «Χαρτόσημο» across `ProductForm`, `InvoiceForm`, `PdfLabels` and
`CommonTaxPresets`. A full sweep corrected the shifted additional-tax §-refs
against the spec's own section headers — **§8.5** Λοιποί Φόροι (type 3), **§8.6**
Ψηφιακό Τέλος (type 4), **§8.7** Τέλη (type 2) — in `AadeInvoiceDocument::
addAdditionalTaxes()`, both the fees AND other-taxes labels in `InvoiceForm`, the
`ProductForm` options, the levied-products cluster (`LeviedProductTemplates`,
`ListProducts`, `ImportLeviedProducts` + test), `DemoCompanySeeder`, the
`add_additional_taxes_to_invoices` migration comment and the tests; the bogus
deductions «§8.8» ref (§8.8 is income classification) was dropped. Payload,
`stamp_duty_*` columns and the `stamp_duty` preset group key are unchanged
(compatibility → stored values still emit taxType 4). `MyDataSubmitterSafetyTest`
now asserts the taxType↔taxCategory pairing per group. See `CHANGELOG.md`
[Unreleased] → Changed.

**Note (out of scope, follow-up):** `MyDataLookupSeeder` doc-comments still cite
«§8.5 E3 type / §8.6 bucket» for INCOME classification — a different concept
(income class type is §8.9, category §8.8). Left untouched here to keep this a
pure additional-taxes fix; candidate for a tiny separate doc cleanup.

**Official finding**

AADE renamed stamp duty to **Digital Transaction Fee**; the v1.0.11 history states
that this was a naming-only change. In v2.0.1, taxType=4 is Digital Transaction
Fee and §8.6 contains categories 1=1.2%, 2=2.4%, 3=3.6%, 4=other amount.
§8.5 is Other Taxes and §8.7 is Fees.

**Repository evidence**

- [AadeInvoiceDocument::addAdditionalTaxes()](app/Services/EInvoice/AadeInvoiceDocument.php)
  emits the correct numeric structure: type 2 fees, type 3 other taxes and type 4
  from the legacy stamp_duty_* fields.
- Its human references are shifted: fees says §8.5, other taxes §8.6 and
  stamp/digital fee §8.7.
- [CommonTaxPresets](app/Support/MyData/CommonTaxPresets.php) exposes
  “Χαρτόσημο 1,2% / 2,4% / 3,6%” instead of the current legal name.

**Required change**

- Rename operator-facing labels/help/errors to Ψηφιακό Τέλος Συναλλαγής.
- Correct the references to Other Taxes §8.5, Digital Transaction Fee §8.6 and
  Fees §8.7.
- Keep a compatibility migration/alias for existing stamp_duty_* data rather
  than silently dropping historical values.
- Add XML tests asserting taxType and category for every additional-tax group.

**Acceptance**

- UI, validation and documentation use the current terminology and sections.
- Existing stored values still emit the same correct taxType 4/category payload.


### MYD-021 — Direct myDATA issue is not durably exactly-once

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Repository evidence**

- [MyDataSubmitter::submit](app/Services/MyDataSubmitter.php) uses a 120-second
  cache lock, but records `mydata_pending_since` only after a caught transport/
  protocol exception.
- A hard process kill, host failure or database outage after AADE accepts the
  request but before success persistence/catch can therefore leave no durable
  pre-send attempt. After the lock expires, a retry can POST blindly.
- [DeliveryNoteSubmitter::submit](app/Services/Delivery/DeliveryNoteSubmitter.php)
  has no equivalent lock, pending marker or status-adoption flow. Two requests
  can issue the same local 9.x document concurrently.
- Delivery submission also lacks a service-level guard against
  `local_status=cancelled`; UI visibility is not protection for CLI/API callers.
- Same-MARK de-duplication occurs only after network I/O and cannot prevent two
  different remote MARKs.

**Risk**

A timeout, double click, worker overlap or crash in the acceptance/persistence
window can create two legal AADE documents for one local invoice/delivery note.
The local database may then preserve only one of them.

**Required change**

- Create one durable issue-attempt row/state before network I/O for both invoices
  and delivery notes, containing tenant, environment, immutable coordinates,
  payload hash and attempt ID.
- Atomically claim one active attempt per local document; use the cache lock only
  as an optimization, not the source of truth.
- Reconcile every in-doubt attempt by its frozen coordinates before any new POST.
- Block locally cancelled documents and all mutation while issuing/in-doubt.
- Finalize by compare-and-set and preserve post-response persistence failures as
  recoverable in-doubt attempts.

**Acceptance**

Parallel-submit, timeout-after-accept, process-kill-after-POST, DB-failure-after-
success and locally-cancelled service-call tests produce at most one legal issue
and never perform a blind retry.

### MYD-022 — Filing services do not enforce tenant coherence

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Repository evidence**

- MyDataSubmitter, GrProviderSubmitter, DeliveryNoteSubmitter and
  DeliveryLifecycleService receive a `Company $tenant` independently from the
  Invoice/DeliveryNote and do not assert matching `company_id`.
- [AadeInvoiceDocument](app/Services/EInvoice/AadeInvoiceDocument.php) builds the
  issuer and credentials from the injected tenant while reading the document,
  customer, type and lines from the passed invoice.
- [InvoSignDocument](app/Services/EInvoice/Transports/InvoSignDocument.php) reads
  provider extension issuer fields from `$invoice->company`, so a mismatched call
  can produce contradictory issuer identities inside one provider payload.
- UI scopes reduce normal exposure, but service/API/CLI calls and missing
  composite tenant foreign keys can bypass that assumption.

**Risk**

A programming error or crafted internal call can transmit tenant B's commercial
data under tenant A's AFM, branch, credentials or provider contract. This is both
a false filing and a cross-tenant confidentiality incident.

**Required change**

Add one central fail-closed tenant-coherence assertion before preview, submit,
cancel and delivery lifecycle I/O. The document and every legal relation
(customer, type, payment method, lines/products where used) must belong to the
same company. Prefer resolving the service from the document's frozen issuer
profile and never rely on an ambient global scope for this boundary.

**Acceptance**

Cross-tenant tests for every direct/provider invoice and delivery operation fail
before payload construction, audit writes or outbound requests.

### MYD-023 — Cancellation evidence is optional or stored inconsistently

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Official finding**

A successful AADE cancellation returns its own `cancellationMark`; it is distinct
evidence from the MARK of the document being cancelled.

**Repository evidence**

- [MyDataSubmitter::cancel](app/Services/MyDataSubmitter.php) can call terminal
  finalisation after a `Success` response with a null cancellation MARK.
- Its “already cancelled” error adoption records no cancellation MARK unless a
  later read path enriches it.
- [DeliveryLifecycleService::cancel](app/Services/Delivery/DeliveryLifecycleService.php)
  discards the direct response's cancellation MARK and passes the original issue
  MARK to persistence.
- [DeliveryMark](app/Models/DeliveryMark.php) has no dedicated
  `cancellation_mark` field.
- External invoice-state synchronization can adopt CANCELLED without persisting
  AADE's available `cancelledByMark`.
- Provider cancellation has the same evidence-strictness problem under PROV-015
  and additionally uses inconsistent generic/dedicated MARK columns.

**Risk**

The UI can show a terminal cancellation while the database cannot prove which
AADE cancellation event caused it. Delivery notes can positively mislabel the
original issue MARK as cancellation evidence.

**Required change**

- Require a non-empty cancellation MARK for a fresh normal `Success`; malformed
  success remains `cancel_in_doubt`, never terminal.
- Store issue MARK and cancellation MARK in distinct fields consistently for
  direct/provider invoices and delivery notes.
- Persist `cancelledByMark` when adopting an external cancellation.
- Treat “already cancelled” as an audited remote adoption with raw proof and
  later cancellation-MARK backfill, not as ordinary local success.

**Acceptance**

Tests cover valid cancellation evidence, `Success` without it, already-cancelled
adoption and external cancellation sync for invoices and delivery notes.

### MYD-024 — Issuer and filing identity are not frozen per document

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Repository evidence**

- Invoice counterpart fields are partly snapshotted, but invoices/delivery notes
  have no complete issuer snapshot.
- AadeInvoiceDocument and DeliveryNoteSubmitter read the current company AFM,
  legal name, address and related issuer data when building XML.
- InvoSignDocument and the invoice/delivery PDF templates also read the current
  Company, so regenerating a historical representation after an edit changes it.
- In-doubt recovery searches with current issuer AFM and mutable series/type
  coordinates; a legal-identity change can miss the existing remote document.

**Risk**

Changing company AFM, name, address, tax office, activity, GEMI or branch can
rewrite historical PDFs and retries, or cause recovery to search a different
legal identity and refile.

**Required change**

Freeze issuer AFM/country/branch, legal and commercial name, address, tax office,
activity/KAD, GEMI and document series at numbering/finalization. Payload,
recovery, provider metadata and historical PDF must use the snapshot. Company
changes apply only to future documents through an audited effective-date/cutover
workflow; legacy rows require a controlled backfill or filing blocker.

**Acceptance**

Editing any company identity field cannot change an existing numbered document's
XML, provider extension, recovery coordinates or regenerated PDF.

### MYD-025 — Legal filing evidence can be hard-deleted

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Repository evidence**

- The mydata_marks and delivery_marks migrations use `cascadeOnDelete()` from
  company and document foreign keys.
- [Company](app/Models/Company.php) has no SoftDeletes, while
  [EditCompany](app/Filament/Resources/Companies/Pages/EditCompany.php) exposes a
  normal Filament `DeleteAction`.
- [CompanyDataWiper](app/Services/Portability/CompanyDataWiper.php) explicitly
  deletes invoices, delivery notes, marks, expenses and activity logs with
  foreign-key checks disabled.
- Its force gate counts only invoices whose `mydata_state=VALID`. It misses
  CANCELLED invoices, delivery notes, provider/other mark evidence and can delete
  everything with `--force`.

**Risk**

An ordinary tenant delete or maintenance import workflow can erase the local
proof of transmitted, cancelled and provider-issued documents while the remote
tax records continue to exist. Backups do not turn intentional hard deletion
into an acceptable retention policy.

**Required change**

- Replace legal-record cascades with restricted deletion and tenant archival/
  deactivation.
- Block company deletion when any legal document/audit evidence exists.
- Limit the wiper to demonstrably unfiled test/import staging data; production
  `--force` must not delete legal marks, provider artifacts or their audit log.
- Define immutable retention/export and restore verification for all issue,
  cancellation, raw XML and provider artifacts.

**Acceptance**

Company delete, document delete and wipe tests prove that any direct/provider
issue or cancellation evidence survives. An archived tenant remains readable and
exportable to authorized users.

### MYD-026 — Delivery lifecycle events are not single-flight or crash-recoverable

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-31

[DeliveryLifecycleService](app/Services/Delivery/DeliveryLifecycleService.php)
dispatches RegisterTransfer and ConfirmDeliveryOutcome without a durable attempt,
lock or compare-and-set claim. A lost response can leave the remote lifecycle
advanced while local state/history remains stale; concurrent callers can send
duplicate or out-of-order events. A later manual status refresh can observe the
remote state, but it is not tied to the ambiguous operation and does not make a
blind retry safe.

Give each lifecycle operation an immutable, single active attempt with request
hash, expected prior state, transport/outcome values and recovery status.
Reconcile the remote lifecycle/history before retry, adopt matching events
idempotently and reject stale responses. Tests must cover two simultaneous calls,
timeout after remote acceptance, delayed visibility and confirm-versus-cancel
races.

### PROV-001 — Ambiguous invoice responses are not durably idempotent

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Repository evidence**

- [InvoSignTransport::parse](app/Services/EInvoice/Transports/InvoSignTransport.php)
  returns `ProviderResult::failed()` for unreadable HTTP-200 XML and for
  `Success` without a MARK.
- [GrProviderSubmitter::submit](app/Services/EInvoice/GrProviderSubmitter.php)
  invokes status recovery only from the exception path. A failed result is
  recorded as `PROVIDER_REJECTED` without status lookup, despite comments that
  imply recovery will run.
- A thrown timeout/non-2xx performs only one immediate lookup. If InvoSign status
  is eventually consistent and does not yet expose the filing, no durable
  in-doubt state prevents the operator from submitting again.
- The direct-myDATA path already has pending/grace/adoption behavior; the provider
  path does not provide equivalent protection.

**Risk**

A request can be accepted and legally issued by the provider while Ekdosi loses
or cannot parse the response. A later click can create a second legal document.

**Required change**

- Classify validation/auth rejections separately from ambiguous transport/protocol
  outcomes.
- Treat timeout, connection loss, non-2xx after send, malformed 2xx and
  `Success` without MARK as **in doubt**.
- Persist immutable issue coordinates, exact attempted payload, attempt ID and
  `provider_pending_since`.
- Status-check with bounded retry/backoff and block every new send while pending.
- Allow an explicit, audited operator resolution only after provider/portal
  evidence has been checked.
- Never reuse mutable InvoiceType/series/branch data for recovery; cross-reference
  MYD-010 and MYD-018.

**Acceptance**

A test where the provider accepts the request but the response is lost proves
that exactly one provider document exists and Ekdosi adopts its MARK.

### PROV-002 — Provider delivery notes have no status recovery

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

[DeliveryNoteSubmitter::submitViaProvider](app/Services/Delivery/DeliveryNoteSubmitter.php)
records `PROVIDER_FAILED` and throws on every transport exception. It never calls
InvoSign status and never leaves a durable pending/in-doubt lock. The transport
interface exposes status only for `Invoice`, even though InvoSign identifies
documents using issuer VAT, branch, type, issue date, series and AA.

**Required change**

- Add provider status/recovery support for delivery notes using frozen issue
  coordinates.
- Apply the same durable in-doubt lock, retry/backoff and MARK adoption policy as
  PROV-001.
- Reconcile a successful remote filing before applying stock for a second time.
- Add timeout-after-accept, delayed-status and duplicate-click concurrency tests.

### PROV-003 — Preserve the official provider document and make Ekdosi PDF provider-aware

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Official requirement**

A.1112/2025 requires provider documents and their printed representation to carry
provider/issuer evidence including date/time, MARK, document identifier/UID,
authentication string, QR, provider site and the ΥΠΑΗΕΣ software licence number.
It also assigns electronic delivery of the provider-issued document to the
provider. The issuer keeps an independent accounting-record retention duty.

**Repository evidence**

- [InvoSignTransport](app/Services/EInvoice/Transports/InvoSignTransport.php)
  parses MARK, UID, authentication code and QR.
- [ProviderResult](app/Support/EInvoice/ProviderResult.php) carries UID, but
  [MyDataMark](app/Models/MyDataMark.php) has no structured UID field.
- [InvoicePdfRenderer](app/Services/InvoicePdfRenderer.php) and
  [pdf.blade.php](resources/views/invoices/pdf.blade.php) print local data, MARK
  and QR but not the provider name, provider site, licence, UID or authentication
  string.
- [SendInvoiceEmail](app/Jobs/SendInvoiceEmail.php) attaches that local PDF
  directly to the customer email.
- Provider success persistence treats MARK as the terminal hard requirement.
  UID/authentication/QR or the official artifact can therefore be absent while
  the document is already operationally presented as complete.

**Required local PDF behavior**

When `mydata_action=PROVIDER_INSERT`, Ekdosi's PDF must visibly include:

- “Εκδόθηκε μέσω iNVO Sign” / provider legal and commercial name;
- provider website;
- current ΥΠΑΗΕΣ licence number;
- MARK, UID/document identifier and authentication code;
- provider QR/verification URL;
- a visible/clickable **canonical provider document URL**, distinct from the
  verification/QR URL unless InvoSign confirms they are the same;
- issue date/time and a label making clear that the provider-hosted document is
  the authoritative provider representation.

Do not hard-code this only in a Blade file. Provider name/site/licence and
capabilities belong in immutable provider metadata/config so another transport
can render the correct evidence and a licence change does not silently rewrite
historical documents.

**Required failsafe provider-artifact archive**

After a successful MARK, Ekdosi must retrieve and privately retain the official
provider document without turning a later download failure into a failed filing.
Persist at minimum:

- `provider_key`, provider legal/commercial name and licence number at issue;
- MARK, UID, authentication code and cancellation MARK where applicable;
- QR/verification URL and a separate canonical document/download URL;
- private storage path/object key, original filename and MIME type;
- byte size, SHA-256, downloaded timestamp and last verified timestamp;
- retrieval HTTP status/error, artifact state
  (`pending|stored|verify_mismatch|unavailable`) and source response/audit row.

Security and retention requirements:

- private tenant-scoped storage only; never a public-disk URL;
- HTTPS plus allowlisted provider hosts, bounded redirects, timeout and maximum
  size to avoid SSRF/unbounded downloads;
- validate that the returned content is the expected PDF/document type;
- a filing with a MARK remains legally VALID, but customer delivery/PDF state
  stays `evidence_pending` until the required UID/authentication/QR/artifact is
  complete;
- queue retries download only — never re-submit the invoice;
- keep the first successful artifact immutable. A later remote byte difference
  stores a new forensic version or alert and must not overwrite history;
- include the artifact in tenant export/backup/retention policy.

**Invoice-card/debugging behavior**

The invoice page must show, permission-gated:

- provider, licence, channel/environment and delivery state;
- MARK, UID and authentication code;
- QR/verification URL;
- canonical provider document URL;
- local archived provider document download;
- SHA-256, size, downloaded/verified times and archive status;
- exact sent XML and raw response;
- a “compare” result for identity, type/series/AA/date, counterparty, net/VAT/gross
  and line count between local snapshot, provider response/document and AADE
  reconciliation.

If no documented provider download endpoint exists, this issue remains blocked:
obtain from InvoSign the supported document URL/download API and retention
contract. Do not silently archive the QR landing-page HTML as if it were the
official document.

**Interim safety**

Until these requirements pass, hide or clearly disable “Αποστολή PDF στον
πελάτη” for provider documents unless the attachment is the official provider
artifact or a provider-approved, fully compliant Ekdosi representation.

### PROV-004 — Credit-type compatibility is not enforced

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

[ViewInvoice::creditTypes](app/Filament/Resources/Invoices/Pages/ViewInvoice.php)
returns every tenant type with `is_credit=true`.
[IssueCreditNote](app/Actions/IssueCreditNote.php) checks only that flag. The UI
can therefore describe a provider cancellation as correlated `5.1` while the
operator selects non-correlated `5.2` or a retail credit.

Implement the compatibility matrix in this audit in one domain service used by
UI and action-level validation. Default the only valid type when unambiguous;
do not rely on helper text. Add tests for B2B full/partial 5.1, deliberate 5.2,
retail 11.4 and an incompatible crafted action request.

### PROV-005 — Provider preflight can return a false green

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

- [InvoSignTransport::ping](app/Services/EInvoice/Transports/InvoSignTransport.php)
  performs an unauthenticated GET to the base URL; an invalid token can pass.
- [ProviderPreflight](app/Services/EInvoice/ProviderPreflight.php) checks issuer
  AFM only. InvoSign's extension requires issuer name, profession/activity, tax
  office, street, postcode and city.
- myDATA read credentials are combined across sandbox/production rather than
  validating one complete pair for the active reconciliation environment.
- The preflight cannot prove contract/declaration activation or remaining quota.

Require an authenticated, non-issuing credential probe/status operation approved
by InvoSign, every mandatory issuer field, active-environment credential pairing,
compatible document types and current activation status. Never issue a dummy
production invoice merely to test credentials.

### PROV-006 — Anonymous retail counterpart convention is not confirmed

**Status:** VERIFY · **Priority:** P0 for retail · **Research:** VENDOR/SANDBOX

The public InvoSign guide marks `CounterpartName` and `CounterpartVat` as
required. [InvoSignDocument::invoiceCounterpartFields](app/Services/EInvoice/Transports/InvoSignDocument.php)
can emit both empty for anonymous 11.1/11.2 retail, while the AADE core correctly
omits a retail counterpart. Delivery notes already use an explicit internal
fallback, but invoices do not.

Obtain InvoSign's written B2C convention and prove 11.1 and 11.2 in sandbox. Do
not invent `000000000` for invoices unless the provider confirms it. Provider
production retail remains blocked until accepted examples and regression tests
exist.

### PROV-007 — InvoSign print-extension discount semantics are unproven

**Status:** VERIFY · **Priority:** P1 · **Research:** VENDOR/SANDBOX

[InvoSignDocument::appendLineFields](app/Services/EInvoice/Transports/InvoSignDocument.php)
derives `api_NetPriceBeforeDiscount`, `api_UnitPrice` and
`api_DiscountValue` from line fields. Canonical AADE totals can additionally
allocate a header discount. The InvoSign class itself calls these semantics
best-effort and requires sandbox confirmation.

Test no discount, line discount, header discount and both together, including
rounding over multiple VAT rates. The provider document, Ekdosi PDF, sent AADE
XML and stored totals must match to the cent. If the InvoSign extension needs a
different allocation, derive both outputs from one canonical allocation service.

### PROV-008 — Transmission Failure_1/2 lifecycle is absent

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED CAPABILITY GAP

A.1112/2025 defines provider issue behavior and mandatory indications for loss of
issuer→provider connectivity (Transmission Failure_1) and provider→AADE
connectivity (Transmission Failure_2), with later delivery/transmission within
the prescribed window. Ekdosi currently treats an unreachable provider as a
failed action and has no unsigned/offline document state, indication, queue or
recovery workflow.

Design this with InvoSign before implementation. It must not be improvised from
the direct-myDATA retry path. Retail also requires the connectivity fallback
specified in the provider contract. Acceptance must cover issue time, immutable
numbering, visible failure indication, one-day recovery deadline, customer
document update and exact-once MARK adoption.

### PROV-009 — Provider operational evidence is discarded

**Status:** OPEN · **Priority:** P2 · **Research:** CONFIRMED 2026-08-30

InvoSign returns `invoiceUid`, `receptionEmails` and
`remaining_invoices`. UID is parsed but not stored in a structured column; the
other fields are ignored. Raw XML is useful forensic evidence but cannot drive
alerts, filtering or a readable support workflow.

Persist UID and normalized provider delivery/quota data while retaining the raw
response. A MARK-only recovery may adopt the legal filing, but must set an
explicit `evidence_pending` state when UID, authentication code, QR or provider
artifact is incomplete; it must never refile merely to fill those fields. Warn on
delivery failure and low quota, expose the information in the invoice/provider
console, and add a scheduled quota/health check only if InvoSign provides a
non-issuing endpoint.

### PROV-010 — Provider contract/declaration activation is not a go-live gate

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED READINESS GAP

Production mode can be selected from technical configuration without proof that:

- the InvoSign contract is active for the tenant and intended transaction scope;
- the Provider submitted the start declaration within its ten-day window;
- the issuer accepted it, or the acceptance period elapsed;
- the declared effective date/scope covers the production issue date;
- production token, portal access and document quota are active;
- the issuer has completed its independent retention/backup plan.

Add an onboarding checklist with evidence fields and an explicit production
arming action. This is partly operational/manual; Ekdosi must not claim automated
AADE verification unless a supported API actually proves it.

### PROV-011 — InvoSign API contract needs written/versioned confirmation

**Status:** VERIFY · **Priority:** P1 · **Research:** VENDOR

The public guide has no clear version/changelog aligned with current AADE
production v2.0.1. Its cancellation section names
`iNVOSign_CancelDeliveryNote.php`, while the example request targets
`invoice_status.php`. Ekdosi uses the named CancelDeliveryNote endpoint, which
is the plausible path but must be confirmed.

Request a versioned integration contract covering:

- supported AADE production schema/API version and upgrade notice period;
- production/demo endpoint and token lifecycle;
- deterministic rejection versus ambiguous response codes;
- status eventual-consistency window and safe retry policy;
- invoice and delivery-note status coordinates;
- canonical provider document/download endpoint;
- anonymous retail counterpart rules;
- credit-note and wrong-credit correction rules;
- quota and recipient-delivery response semantics;
- Transmission Failure_1/2 procedure.

### PROV-012 — Provider capabilities are not gated by licensed scope

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

AADE's current register lists iNVO Sign as licensed but does not mark it for
public-contract e-invoicing or All-in-one Cash Register/POS. Ekdosi should store
the selected provider's current capability scope, show its checked date and block
features that require an absent certification. Ordinary B2B/B2C capability must
not be confused with those two special scopes.

Use the official register as a reviewed input, not an unaudited runtime scraper.
Add a renewal/revocation watch because a provider licence/capability can change.

### PROV-013 — No complete InvoSign sandbox failure matrix

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED TEST GAP

The HTTP-fake tests cover useful response parsing and payload shape, and previous
delivery work records isolated sandbox validation. They do not prove the complete
matrix listed in this audit against InvoSign plus provider portal/AADE evidence.

Create a repeatable, credential-gated acceptance suite and a signed go-live
report. It must never run against production by default and must cleanly label
which cases require controlled provider-side fault injection rather than faking
the HTTP response locally.

### PROV-014 — Provider issue is not a single-flight state transition

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Repository evidence**

- [GrProviderSubmitter::submit](app/Services/EInvoice/GrProviderSubmitter.php)
  checks `mydata_state` before the network call, but it does not claim the invoice
  with a row lock, compare-and-set state, distributed lock or durable attempt row.
- [DeliveryNoteSubmitter::submit](app/Services/Delivery/DeliveryNoteSubmitter.php)
  has the same check-then-POST shape.
- Success persistence de-duplicates only an already-known identical MARK. There
  is no database uniqueness rule preventing two different provider MARKs for one
  local document, and the provider POSTs have already happened before that check.
- The document remains locally editable and locally cancellable while the remote
  request is in flight. A second operator/request can therefore change lines,
  cancel locally or submit the same numbered document before the first response
  commits.
- A provider success followed by a database error is another unclaimed window:
  local state remains unfiled, so the next attempt POSTs first and reconciles only
  if that new POST throws.

**Risk**

Two workers/double-clicks can create two legal provider documents. A concurrent
edit or local cancellation can also leave Ekdosi showing content/status different
from the exact payload that received the MARK.

**Required change**

- Introduce a durable issue attempt/state machine
  (`ready → issuing → in_doubt|valid|rejected`) with immutable payload hash,
  issue coordinates, provider/environment profile and attempt ID recorded
  **before** network I/O.
- Atomically claim one active attempt per local document and environment. Do not
  hold a database transaction open during the HTTP call.
- Block document/type/series edits, local cancellation, channel changes and every
  other submitter while `issuing` or `in_doubt`.
- Finalize with compare-and-set semantics and database constraints; a stale
  response must not overwrite a newer terminal state.
- If the provider supports a client idempotency key, bind it to the durable
  attempt. It complements rather than replaces the local claim/reconciliation.
- Apply the same mechanism to invoices and delivery notes.

**Acceptance**

Parallel-process tests prove one outbound POST, one legal MARK and one immutable
local snapshot. Separate tests cover edit/cancel during the call and database
failure after a provider success.

### PROV-015 — Provider cancellation is neither evidence-strict nor recoverable

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Repository evidence**

- [InvoSignTransport::parse](app/Services/EInvoice/Transports/InvoSignTransport.php)
  returns a successful result for a cancellation `statusCode=Success` even when
  `cancellationMark` is empty.
- Both [GrProviderSubmitter::cancel](app/Services/EInvoice/GrProviderSubmitter.php)
  and
  [DeliveryLifecycleService::cancelViaProvider](app/Services/Delivery/DeliveryLifecycleService.php)
  substitute the original issue MARK when the cancellation MARK is absent, then
  persist a terminal cancellation and mark the local document `CANCELLED`.
- Invoice provider cancellation stores returned cancellation evidence in the
  generic `mark` field while direct cancellation has a dedicated
  `cancellation_mark`; DeliveryMark has no dedicated cancellation column. This
  prevents one consistent audit interpretation across channels.
- A timeout/connection loss writes a failure row and leaves the document
  `VALID`; there is no durable cancellation-pending state or status recovery.
  The provider may nevertheless have completed the cancellation.
- The current InvoSign guide's successful example includes a distinct
  `cancellationMark`, but it does not document a dedicated cancellation-status
  recovery call. This part needs a written provider contract or an authoritative
  AADE read-path reconciliation.

**Risk**

Ekdosi can falsely declare a document cancelled without cancellation evidence, or
show it VALID after the provider has cancelled it. A retry can then double-cancel
or become permanently rejected while the two systems remain split-brain.

**Required change**

- Persist a cancellation attempt before the POST and use
  `cancel_pending|cancelled|cancel_rejected|cancel_in_doubt` states.
- Require a non-empty, distinct cancellation MARK for a normal success. Never
  store the issue MARK as cancellation evidence.
- Preserve the original issue MARK and returned cancellation MARK in dedicated,
  consistently named fields across invoice/delivery and direct/provider paths.
- Treat lost/malformed responses and `Success` without a cancellation MARK as
  ambiguous; block a new cancel until provider/AADE reconciliation resolves it.
- Route recovery through a vendor-supported cancellation lookup or authoritative
  AADE status, retaining raw evidence and an audited manual-resolution fallback.
- Make terminal cancellation idempotent and single-flight across invoice and
  delivery-note paths.

**Acceptance**

Tests cover lost response after remote success, delayed visibility, duplicate
cancel clicks, `Success` without `cancellationMark` and adoption of an already
cancelled remote document.

### PROV-016 — Historical provider and environment identity is mutable

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

A successful provider audit row stores `provider_key`, but not the issuing
environment, canonical endpoint identity, provider licence snapshot, credential
identity/version or full issue-channel profile.
[EInvoiceSubmitterFactory](app/Services/EInvoiceSubmitterFactory.php),
[GrProviderSubmitter::cancel](app/Services/EInvoice/GrProviderSubmitter.php) and
[DeliveryLifecycleService::cancelViaProvider](app/Services/Delivery/DeliveryLifecycleService.php)
resolve the tenant's **current** provider, mode, endpoint and token when the later
operation runs. Provider cancellation lookup also accepts historical direct
`INSERT` rows and sends their MARK to the current provider.

The same drift applies to credits/reissues: their submitter is selected from the
company at action time, not from an explicit correction policy linked to the
original provider filing.

**Risk**

After sandbox→production, direct-myDATA→provider or provider-A→provider-B
cutover, a historical MARK can be sent to the wrong endpoint/credential owner.
A correction may also be transmitted through a legally incompatible channel,
while historical PDFs/metadata cannot prove the exact provider licence and
environment used at issue.

**Required change**

- Freeze on every successful issue and attempt: channel, provider key/legal name,
  sandbox/production, canonical endpoint ID/host, licence number/version,
  non-secret credential key/version, branch and immutable issue coordinates.
- Cancel through the historical issue channel. A direct `INSERT` follows the
  direct-AADE rule; a provider MARK follows that provider/environment unless a
  written migration procedure says otherwise.
- Define and enforce the legal channel policy for correlated credits and
  reissues. Do not silently inherit the current tenant dropdown.
- Add an audited provider-cutover workflow with effective date, covered series/
  branches, open attempts/documents and contract/declaration evidence.
- Historical rendering must use the frozen snapshot, not current provider config.

### PROV-017 — Provider base URL is an unrestricted data-exfiltration sink

**Status:** DONE 2026-08-31 · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Fix:** new `App\Support\EInvoice\ProviderEndpointGuard::assertSafeBaseUrl()` accepts
only a plain PUBLIC HTTPS endpoint — rejects non-https, userinfo (`user:pass@`),
query/fragment, ports ≠ 443, and any host resolving to a private/reserved/loopback/
link-local address (SSRF; literal IP checked directly, hostname resolved best-effort,
DNS failure does not block). It is enforced at `InvoSignTransport::resolve()` (the
choke-point for send/sendDelivery/cancel/status/ping — so CLI/API callers are covered),
in `ProviderPreflight` (a bad active-env base URL is a `fail`), and on the `*base_url`
form fields. Provider HTTP calls are now `withoutRedirecting()` so a rogue endpoint
cannot 302 the token+payload elsewhere. Tokens stay out of the thrown messages.
Unit tests cover every blocked URL class + valid public https; transport tests prove
an http/private base makes NO outbound request. Deferred hardening (request-time
DNS-rebinding pin, provider-managed endpoint-profile registry) is logged in
`docs/BACKLOG.md`. See `CHANGELOG.md` [Unreleased] → Security.

*Notes (whole-PR review):* the guard is **best-effort accident-prevention** — full
anti-SSRF (resolver-consistency + IP-pin, parse_url-vs-curl host confusion,
non-blocking DNS) is the deferred BACKLOG item. No live provider base URL exists to
grandfather (provider mode is off for all tenants), and https/443 is what InvoSign
requires anyway, so enforcing it forward is correct rather than a migration risk.

Provider URL fields in
[CompanyForm::providerCredentialFields](app/Filament/Resources/Companies/Schemas/CompanyForm.php)
are length-limited strings only.
[InvoSignTransport::resolve](app/Services/EInvoice/Transports/InvoSignTransport.php)
uses the saved value directly and POSTs the provider token plus the complete
invoice XML. There is no HTTPS requirement, approved-host check or URL-shape
validation. [ProviderPreflight](app/Services/EInvoice/ProviderPreflight.php)
checks only that the field is non-empty.

A typo such as `http://...`, a copied URL containing userinfo/query data, or a
compromised/misconfigured operator value can therefore disclose the provider
token, issuer/customer data and legal payload or target internal network
services.

**Required change**

- Prefer provider-managed endpoint profiles over arbitrary free-text URLs.
  InvoSign uses per-customer URLs, so validate them against a vendor-confirmed
  HTTPS domain/port pattern or an explicitly approved endpoint record.
- Reject HTTP, userinfo, query/fragment components, loopback/private/link-local
  destinations and unexpected ports; resolve and re-check DNS safely.
- Do not follow cross-host/scheme redirects with credentials.
- Add URL validation in the form, service/transport and provider preflight;
  service-level enforcement must protect CLI/API callers.
- Keep tokens out of logs/exceptions and add tests proving no request is sent for
  every blocked URL class.

### PROV-018 — Full-reversal actions break after a partial credit

**Status:** OPEN · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

Both `cancel_via_credit` in
[ViewInvoice](app/Filament/Resources/Invoices/Pages/ViewInvoice.php) and
[StornoAndReissue](app/Actions/StornoAndReissue.php) select each original line's
full quantity. Their UI remains visible after a partial credit.
[IssueCreditNote](app/Actions/IssueCreditNote.php) correctly subtracts already
returned quantity and rejects a request above the remainder. The advertised
“credit the rest”/storno path therefore fails as soon as any line has been
partially credited.

Build the reversal selection from locked, live **remaining quantities**, skip
zero-remainder lines and fail clearly only when no remainder exists. Reuse one
domain service for both actions and recompute under the existing original-row
lock. Tests must cover one partially credited line, mixed full/partial lines,
cancelled prior credits and two concurrent remainder reversals.

### PROV-019 — Draft credit is confused with legal provider reversal

**Status:** OPEN · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Repository evidence**

- Provider `cancel_via_credit` and `storno_and_reissue` default
  `submit_now=false`; they create a local draft credit without a provider MARK.
- [InvoiceBalance::creditedTotal](app/Services/InvoiceBalance.php) deliberately
  counts correlated draft credits as live local reductions.
- [Invoice::isFullyCredited](app/Models/Invoice.php) then drives the
  “Ακυρώθηκε με πιστωτικό” presentation, hides remaining correction actions and
  exposes `reissue_only`.
- `storno_and_reissue` creates the replacement draft in the same local
  transaction. If optional credit submission later fails, that replacement still
  exists and can be filed normally; no dependency requires the credit to become
  provider-`VALID`.
- Success notifications use “Εκδόθηκε/Έγινε ακύρωση” even when the credit remains
  an unsubmitted draft.

**Risk**

The operator can believe the original was legally reversed and send a replacement
while the provider/AADE still sees only the original. This produces duplicate
turnover/VAT exposure and a misleading audit/UI state.

**Required change**

- Separate local commercial balance allocation from legal provider correction
  state. A draft credit may reserve quantities but must not label the original
  legally reversed.
- Introduce a correction bundle/state machine linking original, credit and
  replacement. Provider cancellation wording becomes true only after the credit
  is provider-`VALID` with its own evidence.
- Lock replacement submission until the required credit is `VALID`; surface
  `credit_draft|credit_pending|credit_failed|reversed|replacement_ready`.
- If credit submission fails or is in doubt, preserve both drafts but block the
  replacement and guide recovery. Never auto-delete legal/audit records.
- Keep off/PDF-only tenant accounting semantics explicit rather than weakening
  their deliberate draft-credit behavior globally.

**Acceptance**

Default-`submit_now=false`, failed credit submission and ambiguous credit
response all leave the original visibly not legally reversed and make replacement
filing impossible. A provider-VALID compatible credit unlocks the replacement
exactly once.

### PROV-020 — Normal online InvoSign issue date is not preflighted

**Status:** DONE 2026-08-31 · **Priority:** P1 · **Research:** CONFIRMED 2026-08-31

**Fix:** new `App\Support\EInvoice\ProviderIssueDateGuard::assertIssuedToday()`
(Europe/Athens local date) is called at both provider issue paths —
`GrProviderSubmitter::submit` (before build/POST) and
`DeliveryNoteSubmitter::submitViaProvider` — so a backdated/future `issued_at`
throws an actionable local error and NO outbound request is made. The direct
myDATA path is intentionally untouched (AADE accepts backdating within its
window). Tests cover yesterday (with a no-outbound assertion), tomorrow and an
Europe/Athens midnight boundary for the invoice path, plus a backdated delivery
note. The legitimate offline/backdated route (Transmission Failure) remains
**PROV-008** — the guard message points to it. See `CHANGELOG.md` [Unreleased] → Fixed.

*Notes (review):* the guard is a pre-send preflight; recovering an in-doubt
filing whose response was lost (adopt the existing MARK by frozen coordinates)
is owned by **PROV-001/PROV-014**, not by re-filing on a later day. Correcting the
date of an already-finalised (active) document is by design a revert-to-draft →
edit → refile (EditInvoice is draft-only; the audit forbids silently rewriting an
allocated legal issue date) — the guard message says so.

The [InvoSign calls/responses guide](https://invosign.gr/site/help_site/?page=kliseis_apantisi)
documents validation error 238: `IssueDate` must equal the current date for the
normal online issue path. The same response is represented in Ekdosi's transport
tests. Invoice and delivery forms default to now but allow an arbitrary
`issued_at`, and ProviderPreflight/service submission does not reject yesterday
or tomorrow before sending.

Add a service-level Greece-local-date guard for ordinary provider issue, with a
clear correction message and midnight/timezone tests. Do not silently rewrite an
already allocated legal issue date. A genuine connectivity-delay case must enter
the documented provider Transmission Failure procedure under PROV-008 and the
vendor contract, not masquerade as an ordinary backdated call.

**Acceptance**

Invoice and delivery tests cover yesterday, tomorrow, Europe/Athens midnight and
the agreed failure-recovery path; invalid normal issue dates make no outbound
request.

### Provider path verified baseline — do not regress

- InvoSign is currently licensed by AADE and publicly advertises ordinary B2B/B2C
  API integration.
- Provider mode uses environment-specific base URL/token fields.
- The transport form-posts canonical AADE XML plus InvoSign's required extension.
- Exact augmented request XML and raw response are retained.
- Successful issue stores MARK, authentication code and QR and marks the invoice
  VALID atomically with the audit row.
- Normal provider value invoices are not sent to a generic AADE cancel endpoint.
  The UI directs them to credit correction; `CancelDeliveryNote` is restricted
  to the supported 9.3 delivery-note path.
- Provider 9.3 cancellation parses a returned cancellation MARK; PROV-015 tracks
  strict presence, recovery and consistent persistence before this can be called
  complete.
- Provider responses are XML-parsed with network entity resolution disabled.
- A duplicate `PROVIDER_INSERT` row with the same invoice/MARK is de-duplicated.
- Provider payload preview exists without exposing the token.

### STOCK-001 — Document cancellation does not fully compensate stock

**Status:** OPEN · **Priority:** P1

**Evidence**

- [StockService::recordSaleForDeliveryNote()](app/Services/Stock/StockService.php)
  records sale stock-out for a sale-purpose delivery note.
- [DeliveryLifecycleService::persistCancellation()](app/Services/Delivery/DeliveryLifecycleService.php)
  changes status but does not reverse a delivery-note stock movement; the stock
  service itself calls this a follow-up.
- Cancelling a normal invoice has reverseSaleForInvoice().
- Cancelling a credit note does not reverse its prior return-IN movement; the
  observer intentionally excludes credit notes from the cancellation branch and
  the stock service documents the edge.

**Risk**

On-hand stock stays too low after cancelling a delivery note that moved it, or too
high after cancelling a credit note that returned it. Because stock is a derived
ledger sum, the error persists until a manual adjustment.

**Required change**

Add idempotent compensating movements for delivery-note sale-outs and credit-note
return-ins. Preserve the linked invoice/delivery “whichever first” rule and reverse
only the movement actually created by the cancelled document.

**Acceptance**

- Issue/cancel/repeat-cancel tests leave the stock ledger at the original balance
  for standalone delivery notes, linked invoice+delivery groups and credit notes.
- Reconciliation-driven remote cancellation uses the same compensation path.

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
| 2026-08-30 | Final myDATA/lifecycle pass: added MYD-017–MYD-020 and STOCK-001 | Documentation-only audit |
| 2026-08-30 | Added Provider/InvoSign/ΥΠΑΗΕΣ audit, compatibility matrices and PROV-001–PROV-013 | Documentation-only audit |
| 2026-08-30 | Provider hardening sweep: added PROV-014–PROV-019 and expanded the sandbox matrix | Documentation-only audit |
| 2026-08-31 | Critical myDATA/provider integrity sweep: added MYD-021–MYD-026 and PROV-020; expanded snapshot, evidence and sandbox requirements | Documentation-only audit |
| 2026-08-31 | **MYD-001 DONE** — third-country 1.3/2.3 → E3_561_006 (was 561_005); tests added | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **MYD-015 DONE** — POS return 8.5 now reduces the myDATA VAT picture (−sign); tests added | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **MYD-020 DONE** — «Ψηφιακό Τέλος Συναλλαγής» terminology + corrected §8.5/8.6/8.7 refs; payload/columns unchanged | `CHANGELOG.md` [Unreleased] → Changed |
| 2026-08-31 | **MYD-013 DONE** — RegisterTransfer requires valid transportType 1–7 + vehicle (except type 7) at the service boundary | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **MYD-016 DONE** — delivery measurementUnit must be a valid §8.13 1–6; missing/out-of-range/unit-7 blocked (unit-7 full support → BACKLOG) | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **PROV-020 DONE** — provider online issue rejects non-today issue date (Europe/Athens) before any outbound; Transmission Failure route stays PROV-008 | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **PROV-017 DONE** — provider base URL constrained to public https (guard at transport/preflight/form) + no credentialed redirects; TOCTOU/endpoint-profile deferred → BACKLOG | `CHANGELOG.md` [Unreleased] → Security |
