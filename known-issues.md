# Known issues and readiness ledger

This file is the working source of truth for installer, first-run setup, myDATA,
scheduler/queue, Provider/ΥΠΑΗΕΣ and production-readiness work. Keep completed entries in the file:
change their status to **DONE**, add the implementing commit/PR and record the date
in the change log.

> **Start at «Go-live triage — 2026-09-02».** The dated audit passes below are
> *source* audits: they rate each finding on its own, never against a business
> scope or a date. The triage section re-sequences all of them against the
> 2026-10-01 cutover and is authoritative for what gets worked on. A P0 in the
> board that sits in bucket C describes a document these tenants do not issue.

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

## Go-live triage — 2026-09-02 (READ THIS FIRST)

**Cutover:** ekdosi replaces the legacy C++Builder app for real invoicing; the
ΥΠΑΗΕΣ/provider obligation lands **2026-10-01**. This section re-sequences the
ledger below against that date. **It is authoritative for what gets worked on;
the per-issue sections stay as the technical record.**

Triaged at [`e6c7899`](https://github.com/chrismfz/ekdosi/commit/e6c78995a95d4bbaef7bcacc4680c212c0572a2e).
**Corrected 2026-09-02 (operator)** — see «Operator corrections» below; the scope
claims were wrong in one important direction and are fixed in place.

### Operator corrections (2026-09-02)

The first cut of this triage read the *new* app's live data as the business's whole
scope. That was wrong. The record:

- **These tenants DO issue delivery notes (δελτία αποστολής) and retail receipts
  (αποδείξεις λιανικής)** — routinely, in the **legacy** program. They have simply
  not *cut over* those document types into the new app yet. «Not yet issued in
  ekdosi» is not «out of business scope», and I conflated the two.
- **Ψηφιακή διακίνηση (digital delivery notes) becomes mandatory too**, on its own
  AADE deadline, exactly like the provider. The 9.x family is *coming*, not
  *excluded*. It moves from bucket C to bucket B, and it must be real before that
  deadline — not before 1 Oct.
- **PROV-010 is DONE.** The contract, «Δήλωση Έναρξης» and issuer acceptance for
  iNVO Sign are in place; the environment in view is the dev/test one we are
  rehearsing on.
- **The «cutover dry-run» is in progress** — it is precisely what the dev/test
  environment is for. Keep it as the gate, but it is not an unstarted task.
- **MYD-007's ~12% is intra-community (ενδοκοινοτικό)**, established from the
  Firebird import. The legacy program had no field to declare the exemption reason,
  so it was never recorded — which is why the seeded hint governs by default. So the
  fact is now known; the open question is only the exact §8.3 reason code, with the
  accountant.

What survives unchanged: the method critique (per-issue rating, never against date ×
business), the provider-dedup runtime evidence, PROV-014 being already-DONE, and the
genuinely-out-of-scope C items (multi-branch, island/ν.5057 VAT, B2G/POS scopes,
fresh-install onboarding, the UPD-* family).

### Why a re-triage was needed

Every pass in this ledger was a **source audit** — deliberately, and it says so.
That method is blind to three things that are now established, and each of them
moves real findings:

1. **Runtime evidence we already have.** `docs/archive/mydata-sandbox-myd2-retry-2026-07-07.md`
   proved by experiment that the **InvoSign channel de-duplicates** a blind re-POST
   of the same `(series, ΑΑ)` — both sends returned MARK `400001965179246` — and that
   `invoice_status.php` answers in **real time**. The direct AADE ERP channel does
   the opposite (two MARKs), which is why the in-doubt gate was built there and
   explicitly **not** on the provider path. Findings written from source alone
   re-raise a duplicate-legal-document risk that was measured away two months
   earlier.
2. **What flows through the new app on day one — versus the whole business.** Live
   data, 2026-09-02: the mix *already cut in ekdosi* is **ΤΠΥ (2.1)**, some **ΤΙΜ
   (1.1)** and **ΠΙΣ (5.x)** — domestic Greek B2B/B2C services (myip **840 docs /
   12 months**, €133k; nexon **15**). **But the business also issues δελτία
   αποστολής and αποδείξεις λιανικής** in the legacy program — those are in scope,
   just not cut over yet — and **digital delivery becomes mandatory** on its own
   deadline. So the right cut is by **timeline**, not by «do they issue it»:
   invoices+retail at the 1 Oct provider cutover, delivery notes at the digital-
   delivery deadline. What genuinely does NOT apply here is narrower than the first
   draft claimed: 3%/island VAT, ν.5057 4%, goods exports, multi-branch, B2G/POS.
   *(Corrected from the first draft, which wrongly read «not yet in the new app» as
   «out of scope» — see «Operator corrections».)*
3. **The deployment already exists and is healthy.** `ops:health` on the live host,
   2026-09-02: worker heartbeat 1.3 min old, 0 pending / 0 failed jobs, scheduler
   green on every enabled task, nightly backup 44 MB, mail clean. Every
   installer/provisioning finding (OPS-*, SETUP-*, TEST-001) is about **someone
   else installing ekdosi from scratch**, not about this cutover.

Nothing below is a claim that a finding is *wrong*. Most are correct. The claim is
that **priority was assigned per-issue, never against a business scope or a date**,
so a P0 that cannot occur here outranks a P1 that will occur on day one.

### The buckets

| Bucket | Meaning | Count |
|---|---|---:|
| **A — BLOCKER** | Must be true before the first live document on 1 Oct | 4 + 1 rehearsal (PROV-010, OBS-001, PROV-003-print now DONE; dry-run in progress). Remaining: MYD-004, MYD-006, MYD-007, PROV-006(conditional) |
| **B — AFTER** | Real, do it after the 1 Oct cutover (incl. the whole delivery-note family, due at the digital-delivery deadline) | ~21 |
| **C — NOT-FOR-US** | Genuinely out of these tenants' scope (island/ν.5057 VAT, multi-branch, B2G/POS, fresh-install). **Re-raise if the scope changes** | ~9 (+15 already-disarmed UPD-*) |
| **D — STALE** | The ledger says OPEN; the code already fixes it | 2 |

> The delivery-note family (MYD-013 ✅, MYD-016 ✅, MYD-019, MYD-026, PROV-002,
> STOCK-001, delivery half of MYD-023) is **B, not C** — these tenants issue δελτία
> αποστολής today in the legacy app and digital delivery becomes mandatory on its
> own deadline. Retail 11.x via the provider (PROV-006) is **A-conditional** — see
> the A table.

### A — BLOCKERS (the whole list; nothing else is)

| ID | Why it blocks | Shape of the work |
|---|---|---|
| ~~**PROV-010**~~ **DONE** | Contract + «Δήλωση Έναρξης» + issuer acceptance for iNVO Sign are **in place** (operator confirmed 2026-09-02); the environment in view is the dev/test one. No longer a blocker. | — |
| **PROV-006** *(A-conditional)* | Retail (αποδείξεις λιανικής, 11.x) **is** part of the business. IF retail is filed through the provider at the 1 Oct cutover, the anonymous-counterpart convention must be sandbox-confirmed first: the public InvoSign guide marks `CounterpartName`/`CounterpartVat` required, and ekdosi can emit both empty for 11.1/11.2. | Prove 11.1 + 11.2 in the dev sandbox now (part of the dry-run). If retail stays on the direct-myDATA path at cutover and moves to the provider later, this drops to B. **Decide which channel retail uses on day one.** |
| ~~**PROV-003** (print half)~~ **DONE (#406)** | A.1112/2025 requires the *printed representation* of a provider document to carry provider identity, licence number, UID, authentication code and QR. **Shipped:** the PDF renders the «Εκδόθηκε μέσω παρόχου (ΥΠΑΗΕΣ)» block on any `PROVIDER_INSERT` invoice, from immutable `ProviderIdentity` config, and the UID is now persisted. No emailed document is non-conforming any more. **The archive half of PROV-003 is bucket B** — see below. | — |
| **MYD-007** | ~12% of myip's net is not at 24% (`vat_summary`: €107,264 net → €22,671 VAT ≈ 21.1%). Operator-confirmed: this is **intra-community (ενδοκοινοτικό)**, established from the Firebird import. The legacy program had **no field** to record the exemption reason, so it was never declared — which is why whatever the seeded 0% hint says governs by default, tenant-wide. | The fact is now known, so this is smaller than the first draft implied. Remaining work: (1) confirm the exact §8.3 reason with the accountant — intra-community **services** (B2B, recipient self-accounts) vs intra-community **goods** (άρθρο 33 = reason 14) land on different codes, and the current seed points at reason 16/άρθρο 45; (2) make the chosen reason the tenant/VAT-row default so it stops depending on a global; (3) spot-check a few imported ενδοκοινοτικά documents' `mydata_marks` request XML to see what, if anything, was filed. **Confirm with the accountant before changing the code.** No per-line exemption model needed. |
| **MYD-004** (0% half only) | Same root as MYD-007: a 0% row used with no exemption reason is only a **warning** and `mydata:preflight` exits 0 on warnings alone. That is the false-green that lets a wrong filing through. | Make «0% row in use without a reason» a **blocking** preflight error. The 3%/code-9/6-vs-10 half is bucket C — these tenants have no island or ν.5057 rate. |
| **MYD-006** (config, not code) | Classification is already resolvable per line (`AadeInvoiceDocument::resolveIncomeClass`, product-category override). What is missing is that **someone chose** the values for ΤΠΥ / ΤΙΜ / ΠΙΣ on these two tenants. | ~30 minutes of configuration review + `mydata:preflight`. No feature. |
| ~~**OBS-001** *(new — not in the ledger)*~~ **DONE (#405)** | Not legally required. It is the item that decides whether a day-one problem costs minutes or a day: nothing outside the panel could answer «γιατί απορρίφθηκε αυτό;». **Shipped:** five read-only MCP tools on the `SuperAdminMcpTool` pattern — `invoice_filing` (the important one), `mydata_failures`, `stuck_documents`, `mydata_discrepancies`, `preflight`. Acceptance met: name a failing document → its rejection code + exact XML, no panel. | Optional tail (per-success INFO line) → BACKLOG. |
| **Cutover dry-run** *(in progress — this is what dev/test is for)* | The last *production* ekdosi MARK is **2026-06-10** (myip) / **2026-06-16** (nexon), and `ops:health` on prod reports **92 / 2** open myDATA discrepancies. The rehearsal on the dev/test environment is already the gate; keep it explicit so nothing ships un-rehearsed. | Cover every day-one document type end-to-end on the provider sandbox — ΤΠΥ, ΤΙΜ, ΠΙΣ **and αποδείξεις λιανικής 11.x** (issue → PDF → reconcile). Then, on prod, clear the 92/2 discrepancy backlog so day-one noise is real signal. Delivery notes 9.x join this rehearsal ahead of the digital-delivery deadline, not 1 Oct. |

### B — AFTER cutover (real, but nothing burns on 1 Oct)

| ID | Downgraded to | Reason |
|---|---|---|
| PROV-001 | **P2, provider-specific** | The provider de-dups and status is real-time (sandbox, 2026-07-07). A blind retry on the InvoSign channel cannot create a second legal document. The residual — a malformed HTTP-200 / «Success without MARK» being recorded as a rejection without a status lookup — is real but harmless *on this provider*. **Re-raise to P0 the day we point at a provider that does not de-dup (e.g. SBZ).** |
| PROV-003 (archive half) | **P2** | Blocked on an InvoSign download endpoint that may not exist; the SHA-256/versioned/immutable-artifact design is disproportionate at 70 documents/month. The provider retains the document; our independent duty is met by `mydata_marks` (byte-exact request + response) plus the nightly backup. |
| PROV-005 | **P2** | `ping()` is an unauthenticated GET, so «Έλεγχος σύνδεσης» can be green with a dead token. Annoying, self-revealing on the first real filing, and the cutover dry-run in bucket A catches it anyway. |
| PROV-009 | **P2 (as filed)** | UID lives in the raw response but not in a column. Worth a column; not worth a queue/alerting subsystem. |
| PROV-011 | **P2** | Ask InvoSign for a versioned contract. The cancellation-endpoint ambiguity is already resolved empirically (`[283]`, sandbox 2026-07-07). |
| PROV-018 | **P2** | Full reversal after a partial credit. Real bug, low frequency (ΠΙΣ is a handful of documents a year), no wrong data — it fails loudly. |
| PROV-019 | **P1, after** | «Draft credit treated as legal reversal» matters once credits are routine on the provider channel. |
| MYD-023 | **PARTIAL** (storage DONE via #404) | The distinct cancellation MARK is now stored in its own field on **every** path — `mydata_marks` and (as of #404) `delivery_marks` both have `cancellation_mark`. Only the strict-refusal half (refuse a terminal cancel that returns no cancellation MARK) is deferred → BACKLOG. |
| MYD-024 | already PARTIAL | Series is frozen (MYD-018); ΑΦΜ/ΓΕΜΗ edits warn. Snapshotting issuer name/address is a nicety at two single-branch tenants. |
| **Delivery-note family** — MYD-019, MYD-026, PROV-002, STOCK-001, delivery half of MYD-023 | **P1, before the digital-delivery deadline** | These tenants issue δελτία αποστολής today (legacy) and digital delivery becomes mandatory on its own AADE deadline. Real work, correctly scoped — just **not gated on 1 Oct**. MYD-013 and MYD-016 in this family are already DONE. Do the rest as one block before the ΔΑ deadline, with a sandbox rehearsal of 9.3 issue/register/confirm/cancel. |
| MYD-005, SETUP-004, OPS-002, OPS-003, TEST-001, DEP-001 | unchanged P2/WATCH | Correctly parked already. |

### C — NOT FOR US (genuinely out of these tenants' scope)

**Exotic VAT regimes.** 3% (code 9), island 4% (code 6) vs ν.5057 4% (code 10),
goods export exemptions. → the **non-0% half of MYD-004** and the goods rows of
MYD-007. No island or ν.5057 activity here. *(The intra-community 0% case IS in
scope — that is MYD-007, bucket A.)*

**Multi-branch.** → **MYD-010** (already WATCH, correctly). Both tenants are
single-establishment; `branch=0` is the truth, not a shortcut.

**Special provider scopes.** → **PROV-012** (public contracts, All-in-one POS).
Neither tenant does B2G or POS. The real requirement is only that ekdosi does not
*claim* those capabilities — it doesn't.

**Offline / Transmission Failure.** → **PROV-008**. Legally shaped, genuinely
absent, and correctly flagged as «design with InvoSign, do not improvise». At 70
documents/month a provider outage is handled by *waiting*, not by an offline
issuing subsystem. **Not a 20-day project.**

**Installer & fresh-install onboarding.** → **SETUP-001, SETUP-002, OPS-001,
TEST-001.** The host is installed, provisioned and verified green. These are
product-quality items for the *next* installation, not cutover items.

**The whole UPD-* family** is already DISARMED — in-app apply is off and
`deploy/update.sh` is the documented path. Leave it there.

**PROV-013 / the mandatory sandbox acceptance matrix** as written (≈30 rows,
including provider-side fault injection) is not achievable in 20 days and is not
proportionate. What it is *really* asking for is bucket A's cutover dry-run, on
the document types these tenants issue. Do that; keep the matrix as the aspiration.

**PROV-007** (InvoSign discount semantics) — verify inside the dry-run by
comparing one discounted invoice cent-for-cent. It does not need its own project.

**PROV-015 / PROV-016** — provider cancellation evidence and historical channel
freeze. Note the empirically-established fact that removes most of the urgency: a
**provider-filed 2.1/11.x cannot be cancelled at all** (AADE `[249]`, InvoSign
`[283]` — sandbox 2026-07-07); reversal is a 5.1 credit, and ekdosi already gates
the action to 9.3 only. The cancellation-evidence machinery therefore has almost
no live surface here.

**PROV-004** (5.1/5.2/11.4 compatibility matrix) — real, but at these volumes the
practical fix is: set the ΠΙΣ type to **5.1** and leave one credit type configured.
The operator cannot then pick a wrong one. Enforce the matrix in code later.

### D — STALE: the ledger is behind the code

| ID | Ledger says | Code says |
|---|---|---|
| **PROV-014** | OPEN P0 — «issue is not single-flight» | **Fixed.** `GrProviderSubmitter::submit()` takes `Cache::lock('mydata-submit:'.$id, 120)` and re-reads under it (`97c23de`); `DeliveryNoteSubmitter::submit()` takes `delivery-submit:` (`23b1fa4`). Both were the audit's cited evidence. → **DONE** |
| **SETUP-003** | OPEN P1 — «missing payment method silently becomes cash» | Half stale, and the required change is **wrong**. An unmapped-but-chosen method logs a warning and `MyDataConfigAudit` surfaces it in preflight; only a *null* method defaults to cash silently. Hard-blocking a live filing over a payload-quality nit is worse than filing type 3. → **P2**, plus one config check that the tenants' methods are mapped. |

*(PROV-017 is correctly recorded as PARTIAL, not stale — noted only because its
remaining half, host allowlist + DNS-rebinding pin, is bucket C: the base URL is set
once, by us, to a known InvoSign host.)*

### What this means in practice

With PROV-010 done and the dry-run already running on dev/test, the day-one list
is: **one Blade block (PROV-003 print), the intra-community VAT reason (MYD-007 —
accountant, then a default), a one-line preflight severity change (MYD-004 0%), a
classification config review (MYD-006), five small read-only MCP tools (OBS-001),
and — IF retail files via the provider at cutover — a sandbox proof of 11.x
(PROV-006)**, all inside the rehearsal. The delivery-note family (MYD-019/026,
PROV-002, STOCK-001, MYD-023 delivery half) is the *next* deadline's block, not
this one. Still days of work, not the ~45 open items the board implies.

The gate discipline in `CLAUDE.md` — «merge when strictly better than main, no
known P0/P1, suite green, reversible» — was written for **changes**. This ledger
is not a change; it is a wish list, and running the same round-cap discipline
against it is what produced ten-round PRs for P2-shaped work. **A finding's
priority is not a property of the finding. It is a property of the finding × this
business × this date.**

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
| MYD-011 | DONE | P0 | Foreign supplier/manual delivery recipients are reported as GR |
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
- **PARTIAL** — a meaningful subset is fixed and verified; the remaining hardening is
  deliberately deferred and tracked in `docs/BACKLOG.md` (not merely pending verification).
- **WATCH** — currently correct; re-check when an upstream dependency/spec changes.
- **DONE** — fixed and verified; retain the entry for history.

Priorities:

- **P0** — can file incorrect data, expose the wrong filing path or falsely declare go-live readiness.
- **P1** — blocks or materially confuses a normal first-time operator.
- **P2** — operations, resilience, documentation or coverage gap.

## Work board

> **Sequencing lives in «Go-live triage — 2026-09-02» at the top of this file.**
> The `Bucket` column below is that triage: **A** = blocker before 1 Oct · **B** = after
> cutover · **C** = out of these tenants' business scope (re-raise if it changes) ·
> **D** = ledger stale vs code. Priority is the original per-issue rating; bucket is
> priority × this business × this date, and bucket wins.

| ID | Priority | Status | Bucket | Area | Summary |
|---|---:|---|---|---|---|
| MYD-001 | P0 | DONE | — | Classification | Third-country 1.3/2.3 use the intra-EU E3 code |
| MYD-002 | P0 | DONE | — | ΤΔΑ | Seeded label promises a combined invoice/delivery payload that is not emitted |
| MYD-003 | P0 | DONE | — | Delivery notes | 9.x movement-only types are exposed in the monetary invoice picker |
| MYD-004 | P0 | OPEN | A | VAT validation | 0%-without-reason must block preflight (the intra-community case). 3%/dual-4% half is C |
| MYD-005 | P2 | OPEN | B | Quantity units | Ordinary invoice XML omits optional myDATA measurementUnit |
| MYD-006 | P1 | OPEN | A | Classifications | Readiness does not require a business-specific classification policy |
| MYD-007 | P0 | OPEN | A | VAT exemption | The ~12% 0% is intra-community (ενδοκοινοτικό, from import); confirm exact §8.3 reason + make it the default (accountant) |
| MYD-008 | P0 | DONE | — | Provider credits | Correlated credit cannot find a provider-issued original MARK |
| MYD-009 | P0 | DONE | — | Counterpart identity | Submitted AFM/name can come from live customer instead of the frozen invoice snapshot |
| MYD-010 | P2 | WATCH | C | Branches | Issuer and counterpart branch are always filed as head office 0 |
| MYD-011 | P0 | DONE | — | Delivery recipient | Supplier/manual recipient country is lost and filed as GR |
| MYD-012 | P0 | DONE | — | Delivery correlation | Seeded 9.1 is offered without any correlated MARK payload |
| MYD-013 | P1 | DONE | — | Delivery lifecycle | RegisterTransfer can omit the mandatory transportType |
| MYD-014 | P1 | DONE | — | Expense sync | Supplier cancellation is detected but cannot update an existing local expense |
| MYD-015 | P1 | DONE | — | VAT picture | Type 8.5 POS return is added with a positive sign |
| MYD-016 | P1 | DONE | — | Delivery units | Invalid or missing coded unit is silently filed as pieces |
| MYD-017 | P0 | DONE | — | Reconciliation | Same MARK/state is called matched without comparing amount, type or identity |
| MYD-018 | P0 | DONE | — | Filing identity | Numbered invoices still read mutable series/type/classification defaults |
| MYD-019 | P1 | OPEN | B | Delivery sync | Remote cancellation leaves mydata_state/local_status unchanged — do before the ΔΑ deadline |
| MYD-020 | P2 | DONE | — | Digital Transaction Fee | Legacy stamp-duty names and § references remain in UI/code |
| MYD-021 | P0 | DONE | — | Direct idempotency | Direct issue is not protected by a durable pre-POST attempt; delivery notes also lack single-flight |
| MYD-022 | P0 | DONE | — | Tenant isolation | Filing services do not prove that document, relations and credential tenant agree |
| MYD-023 | P0 | PARTIAL | B | Cancellation evidence | Issue/cancellation MARKs now in distinct fields on every path (#404); strict-refusal half → BACKLOG |
| MYD-024 | P2 | PARTIAL | B | Issuer identity | Series frozen (MYD-018); issuer name/address snapshot deferred, ΑΦΜ/ΓΕΜΗ edit now warns |
| MYD-025 | P1 | DONE | — | Legal retention | Company delete/wipe can hard-delete documents, MARKs and audit evidence |
| MYD-026 | P1 | OPEN | B | Delivery lifecycle | Register/confirm events lack a durable single-flight/recovery state — do before the ΔΑ deadline |
| PROV-001 | P2 | OPEN | B | Provider idempotency | InvoSign **de-dups** + real-time status (sandbox 2026-07-07) ⇒ no duplicate document possible on this provider. **Re-raise to P0 on a provider that does not de-dup** |
| PROV-002 | P0 | OPEN | B | Provider delivery notes | Timeout has no status recovery and can create a duplicate 9.3 — do before the ΔΑ deadline |
| PROV-003 | P0 | PARTIAL | A✓/B | Provider documents | Day-one (print) half DONE (#406) — PDF shows provider identity/licence/MARK/UID/auth-code, UID persisted; nothing blocks 1 Oct. Remaining is bucket B: official-artifact archive + per-doc licence snapshot + invoice-card compare → BACKLOG |
| PROV-004 | P0 | OPEN | C | Provider credits | UI/service do not enforce the 5.1/5.2/11.4 compatibility matrix |
| PROV-005 | P1 | OPEN | B | Provider preflight | Reachability is not token authentication and mandatory issuer fields are unchecked |
| PROV-006 | P0 | VERIFY | A? | Provider retail | Retail IS in scope; confirm the anonymous 11.x counterpart convention in sandbox IF retail files via provider at cutover |
| PROV-007 | P1 | VERIFY | C | Provider totals | Header/line discount semantics of InvoSign api_* fields are not proven |
| PROV-008 | P1 | OPEN | C | Provider outage | Transmission Failure_1/2 issue and recovery lifecycle is absent |
| PROV-009 | P2 | OPEN | B | Provider observability | UID, reception feedback and remaining quota are not structured/surfaced |
| PROV-010 | P0 | DONE | — | Provider activation | Contract + «Δήλωση Έναρξης» + acceptance in place (operator-confirmed 2026-09-02) |
| PROV-011 | P1 | VERIFY | B | Provider API | Version support and contradictory cancellation example need written confirmation |
| PROV-012 | P1 | OPEN | C | Provider scope | Public-contract/All-in-one POS capabilities are not gated from the AADE register |
| PROV-013 | P0 | OPEN | C | Provider tests | Required InvoSign sandbox success/failure matrix has not been completed |
| PROV-014 | P0 | DONE | D | Provider concurrency | Single-flight lock landed (`97c23de` invoices, `23b1fa4` delivery); serialisation vs document mutation → BACKLOG |
| PROV-015 | P0 | OPEN | C | Provider cancellation | Missing/lost cancellation evidence can create a false or split-brain terminal state |
| PROV-016 | P0 | OPEN | C | Provider cutover | Historical issue channel/environment is not frozen or used for later actions |
| PROV-017 | P1 | PARTIAL | C | Provider endpoint security | Base URL now public-https-only (hygiene DONE); approved-host allowlist + DNS-rebinding pin OPEN → BACKLOG |
| PROV-018 | P1 | OPEN | B | Provider partial credits | Full-reversal actions reuse original rather than remaining quantities |
| PROV-019 | P0 | OPEN | B | Provider correction state | Draft credit is treated as legal reversal and replacement is not filing-gated |
| PROV-020 | P1 | DONE | — | Provider issue date | Backdated/future online issue reaches InvoSign instead of failing actionable preflight |
| STOCK-001 | P1 | OPEN | B | Stock ledger | Cancelling delivery/credit documents does not fully compensate stock — with the ΔΑ work |
| SETUP-001 | P1 | OPEN | C | Onboarding | Fresh tenant is not guided to a first valid invoice |
| SETUP-002 | P1 | OPEN | C | Issuer identity | Installer accepts insufficient legal/myDATA issuer data |
| SETUP-003 | P2 | OPEN | D | Payment | An *unmapped* method already warns + shows in preflight; only a null method defaults to cash. Hard-blocking a filing over this is worse than type 3 |
| SETUP-004 | P2 | OPEN | B | Estonia | EE tenant skips even non-AADE standard lookups |
| OPS-001 | P1 | OPEN | C | Scheduler/queue | Installer does not provision or prove OS cron and worker |
| OPS-002 | P2 | OPEN | B | Installer | Writable env/application root is not a hard preflight |
| OPS-003 | P2 | OPEN | B | Shared hosting | No cPanel/shared-hosting queue recipe or direct completion link |
| TEST-001 | P2 | OPEN | B | Tests/CI | No full web installer success-path test; inspected CI was not green |
| DEP-001 | P2 | WATCH | B | Dependency | firebed/aade-mydata is current; watch AADE v2.0.2 |
| OBS-001 | P1 | DONE | — | Observability | 5 read-only forensic MCP tools shipped (#405): invoice_filing / mydata_failures / stuck_documents / mydata_discrepancies / preflight. Optional per-success INFO line → BACKLOG |
| UPD-001 | P2 | DISARMED | C | Queue safety | PHP update/rollback does not drain an in-flight worker |
| UPD-002 | P2 | DISARMED | C | Failure recovery | Partial apply failure lifts maintenance and can serve inconsistent code |
| UPD-003 | P2 | DISARMED | C | Update integrity | UI queues a mutable tag, not a verified immutable commit SHA |
| UPD-004 | P2 | DISARMED | C | Rollback readiness | Apply can start without a known current ref or proven rollback path |
| UPD-005 | P2 | DISARMED | C | Maintenance mode | Live UI and opcache self-hit are blocked while the app is down |
| UPD-006 | P2 | DISARMED | C | Crash recovery | A killed process can leave a permanent running row and maintenance state |
| UPD-007 | P2 | DISARMED | C | Health result | Critical health/advisory failures still end as succeeded |
| UPD-008 | P2 | DISARMED | C | Snapshot retention | PHP update/rollback snapshots are never pruned by --keep=10 |
| UPD-009 | P2 | DISARMED | C | Preflight | Button does not prove cron, binaries, space, permissions or clean target |
| UPD-010 | P2 | DISARMED | C | Script strategy | Bash deploy script is invoked through sh |
| UPD-011 | P2 | DISARMED | C | Tests | Apply, migration, failure and rollback paths are not executed in tests |
| UPD-012 | P2 | DISARMED | C | Safety controls | “Read-only” update setting also arms one-click apply |
| UPD-013 | P2 | DISARMED | C | Credentials | Git token remains in the updater process environment after fetch |
| UPD-014 | P2 | DISARMED | C | Discovery | Future GitHub Releases can mask newer tag-only releases |
| UPD-015 | P2 | DISARMED | C | Concurrency | Single-flight is UI/scheduler based, not an atomic command-level lock |

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

**Status:** DONE 2026-08-31 · **Priority:** P0 · **Research:** CONFIRMED, WORDING CORRECTED 2026-08-30

**Fix (safe interim):** the misleading «ΤΔΑ / Δελτίο Αποστολής» row is removed from
`MyDataLookupSeeder::INVOICE_TYPE_SEED`, so a fresh install no longer offers a type
that emits a plain 1.1 while its name promises delivery-note behaviour (the audit's
acceptance: «or it is not offered as available»). Plain 1.1 sales use ΤΙΜ. Existing
tenants keep their ΤΔΑ (the seeder never deletes) and can hide it — note that issuing
under it files a **valid, correct 1.1 invoice** (the movement aspect is simply not
emitted), so this is a cosmetic naming mismatch, not a wrong filing. The
`InvoiceTypeClassSuggester` is intentionally left as-is: it maps a
«Τιμολόγιο … Δελτίο Αποστολής» name to 1.1 (the correct base type) BEFORE the pure
delivery block, so it never misclassifies such a name as a 9.3 movement note. The
real combined document — a 1.1 with `isDeliveryNote=true` + movement/loading/delivery
data — is a BACKLOG feature. Seeder-count tests updated (15→14). See `CHANGELOG.md`
[Unreleased] → Fixed.

**Review follow-up (post-#387):** the «Εισαγωγή τυπικών» modal
(`ListInvoiceTypes`) still listed «Τιμολόγιο/Δελτίο Αποστολής» among the seeded set —
a stale promise for a type no longer seeded. Corrected the modal description to the
actual set. Per operator decision (χρησιμοποιείται ΤΔΑ ως 1.1 από συνήθεια), existing
tenants' ΤΔΑ rows are deliberately left untouched — **no data migration**; the real
combined 1.1+isDeliveryNote stays in BACKLOG.

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

**Status:** DONE 2026-08-31 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix:** new `Codes::isMovementOnlyType()` (prefix `9.`). The rule now applies to
**every** monetary invoice-type selector via a shared `InvoiceType::scopeMonetary()`
(null-safe — a legacy type with no `mydata_type` stays selectable): the main invoice
picker (`PickerOptions`), quote→invoice **and** quote→service conversions (`ViewQuote`),
and the service-contract renewal type (`ServiceContractForm`). **Defence-in-depth at the
choke-point:** `InvoiceNumberer::allocate()` — through which every creator funnels
(CreateInvoice, IssueCreditNote, ConvertQuoteToInvoice, StageServiceRenewal,
WhmcsInvoiceFiler) — throws for a 9.x type *before* the counter bump (no ΑΑ gap), so no
non-UI caller can file a Δελτίο Αποστολής as a monetary invoice. `AadeInvoiceDocument::
build()` keeps its own guard. Movement documents remain in the Delivery Notes flow
(`DeliveryNoteSubmitter`). Tests cover the picker exclusion, the shared scope, and the
numberer guard. See `CHANGELOG.md` [Unreleased] → Fixed.

**Review follow-up (post-#387):** the initial fix only filtered `PickerOptions`; the
quote-conversion, service-renewal and WHMCS creation paths still exposed/allowed 9.x. The
shared `scopeMonetary` + the `InvoiceNumberer` backstop close all of them (external review).

**Second review pass (2026-09-01):** a stricter read found the literal «every monetary
selector» claim still open — the three WHMCS default-type selectors (invoice/receipt/unpaid,
`CompanyForm`), the two third-party split selectors (`WhmcsInboxTable`) and the shared
credit-note picker (`ViewInvoice::creditTypes`) were not yet using the scope. The security
was already sound (the `InvoiceNumberer` backstop rejects a 9.x before the ΑΑ bump), but the
acceptance was not literally met. Now ALL of them apply `->monetary()`; the WHMCS default and
split queries were extracted into shared testable helpers (`CompanyForm::whmcsDefaultTypeOptions`,
`WhmcsInboxTable::splitTypeOptions`) with reflection tests asserting 9.x exclusion. The WHMCS
default selectors also re-inject a value the field ALREADY holds when it is no longer selectable
(a legacy 9.x mis-stored before MYD-003), flagged «μη έγκυρο», so a save can't silently null it —
same pattern as `DeliveryNoteForm` (final whole-PR review finding).

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

**Status:** OPEN · **Priority:** P0 · **Bucket:** A · **Research:** NEW, CONFIRMED 2026-08-30 · **Operator mapping added 2026-09-02**

> **Operator-confirmed mapping (2026-09-02) — the concrete target for this tenant.**
> There is NO single «intracommunity» code; goods and services diverge, and the
> current seed's **16 / άρθρο 45** is a *domestic reverse-charge* code, wrong for
> both. For these tenants' ordinary case — **B2B services (hosting) to an EU
> business** — the correct triple is:
>
> | myDATA field | Code |
> |---|---|
> | `invoiceType` | **2.2** — Ενδοκοινοτική Παροχή Υπηρεσιών |
> | `vatCategory` | **7** — Άνευ ΦΠΑ |
> | `vatExemptionCategory` | **4** — άρθρο 18 (πρώην άρθρο 14) |
>
> Intra-community **goods** are different — `invoiceType` **1.2**, exemption **14 /
> άρθρο 33** (πρώην 28). v2.0.1 distinguishes 1.2 vs 2.2 explicitly, and §8.3 maps
> code 4→άρθρο 18 and code 14→άρθρο 33 (same mapping in Epsilon Net's worked
> example). So: **4, not 14, and never 16** for hosting-to-EU-business.
>
> **This confirms the modelling defect, it does not close it.** The exemption
> reason must become a per-line / per-VAT-row choice with the RIGHT domestic vs
> intra-community vs export options, not one tenant-wide 0% setting. The Firebird
> import gives country + VIES (and usually «this is a service»), but never stored
> the actual exemption reason — so the automation must select it, and the operator
> must be able to review it. **Needs a real split** (see «Required change» +
> the design note below). Confirm the exact codes with the accountant before
> shipping the defaults.

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

**Status:** DONE 2026-09-01 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix:** `AadeInvoiceDocument::originalInsertMark()` — the ONE shared resolver used by
both the direct (`MyDataSubmitter`) and provider (`GrProviderSubmitter`) flows — now
reads the original filing MARK from BOTH `INSERT` **and** `PROVIDER_INSERT` rows (was
`INSERT` only), so a 5.1 credit against a provider-issued original correlates exactly
like one against a directly-filed original. `whereNotNull('mark')` still excludes
rejected/failed attempts (`PROVIDER_REJECTED`/`PROVIDER_FAILED` carry a null mark). The
original lookup is now scoped to the credit note's `company_id` (the `CompanyScope` is a
no-op off-request), so a `credited_invoice_id` pointing at another tenant can never
resolve — AND the `MyDataMark` lookup itself is scoped to the same `company_id`, so an
inconsistent audit row belonging to another tenant (whose `company_id` disagrees with its
invoice's) can never be used as the correlated MARK either. 5.2 (non-correlated) never calls
the resolver, so it is unaffected. The resolver also guards that the MARK is pure-numeric
before the `addCorrelatedInvoice((int) …)` cast, so a malformed MARK fails loudly rather than
being silently truncated. Tests cover direct + provider correlation, a rejected-attempt
refusal, the cross-tenant-original refusal, a foreign-company MARK-row refusal, and the
non-numeric-MARK refusal. See `CHANGELOG.md` [Unreleased] → Fixed.

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

**Status:** DONE · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30 · **Fixed:** 2026-09-02

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

**Resolution (2026-09-02)**

The legal counterpart is now built ENTIRELY from the invoice's party snapshot, through
three `Invoice` helpers — `counterpartAfm()`, `counterpartName()`, `counterpartCountryIso()`
— shared by the AADE payload and the provider payload so one document can never name two
parties. `AadeInvoiceDocument::buildCounterpart()` no longer touches `customer` at all; the
"requires a customer with AFM" guard became "requires a counterpart ΑΦΜ", so an invoice with
a frozen ΑΦΜ and a deleted customer stays issuable.

**The live-customer fallback is doubly narrowed**, because each hole reports a party that was
never agreed:

- **It stops at transmission.** `mayFallBackToLiveCustomer()` requires `! hasBeenFiled()`
  (`mydata_sent || mydata_mark`). After filing, the snapshot is the only source — reading
  through would show a party the AADE record never carried.
- **The linked customer must actually BE the counterpart.** The party fields are editable
  while `customer_id` stays put, so an operator can overtype «ΑΦΜ/Επωνυμία» with someone else;
  `counterpartIsTheLinkedCustomer()` refuses to borrow that customer's country or address.
  Same hole, same predicate as MYD-011 — the ΑΦΜ comparison keeps LETTERS (shared
  `Afm::comparisonKey()`), so «DE811234567» never matches a Greek customer's «811234567».

**The resolved party is FROZEN at the moment of filing.** `Invoice::frozenPartyColumns()`
fills only blank snapshot columns and is merged into the SAME `forceFill` as the MARK, on both
the direct and the provider path — otherwise a legacy/ETL row's reported party would become
unreadable the instant `mydata_sent` closed the fallback (the MYD-011 lesson, applied up front).
The stored country is the NORMALISED ISO-2, so the column means what it claims.

**A blank country still defaults to GR; a present-but-unresolvable one still throws** — turning
«Neverland» into a confident domestic filing is the MYD-011 round-7 misreport, and an existing
test caught the regression when the first cut of this change introduced it.

**Provider parity:** `InvoSignDocument::invoiceCounterpartFields()` builds the LEGAL fields
(name/ΑΦΜ/profession/address) from the same helpers. Tax office, phone and email are contact
details, absent from the AADE payload and used by InvoSign for delivery/printing — they stay
LIVE deliberately, and that distinction is now stated in code rather than being an accident of
a per-field fallback chain.

**Round-1 review corrections (the fix's own bugs, caught by the gate):**

- **The country chain was re-implemented in the builder instead of using the helper**, and
  «blank → GR» was applied to the result of a CLOSED fallback — so "no country evidence
  anywhere" and "a country exists but this document may not read it" gave the same answer. An
  Italian party whose customer link had drifted was filed as **GR with no name and no address**,
  where the previous code loudly refused. That is the MYD-011 round-7 misreport reintroduced on
  the invoice side; the three outcomes are now explicitly distinct.
- **The ΑΦΜ was filed verbatim from free text.** `invoices.vat_no` is a bare TextInput and an
  ETL copy of the legacy column, so «IT 12345678901» / «EL123456789» reached AADE. The old code
  filed `customers.afm`, which the form and GSIS/VIES keep canonical — new `Afm::canonicalVat()`
  restores that (separators dropped, letters kept, a Greek prefix removed only when what remains
  is a bare nine-digit ΑΦΜ, so a foreign id stays intact).
- **The freeze was incomplete**: it covered ΑΦΜ/name/country but not the ADDRESS, which a non-GR
  counterpart actually files — so a filed foreign document could no longer reproduce its own
  counterpart and threw "requires a full address" while AADE held the real one.
- **The freeze could roll back a successful filing.** `customers.name` is varchar(191) and
  `invoices.company_name` varchar(120) under MySQL strict mode, so an over-long copy raised
  inside the SAME transaction as the MARK audit row — discarding the record of a filing AADE had
  already accepted and leaving the invoice permanently stuck. Values are truncated per column.
- **Retail (11.x) froze a party that was never declared** (AADE files no counterpart there), and
  the PDF keys its counterpart block on `vat_no`, so a receipt would have started printing one.
- **The provider could receive an empty `CounterpartName`** (legal for a GR counterpart in the
  AADE payload, rejected by InvoSign as `[88-001]`) — it now refuses with the field to fill.

**Round-2 review corrections (the same root cause, twice):**

The round-1 fix left the country policy in TWO places — a helper on the model and a copy in the
builder — and the copies disagreed. That is what produced the round-1 P0 and produced another
here, so the policy is now a single method (`Invoice::counterpartCountryForFiling()`) called by
the payload, the provider document and the freeze.

- **A foreign party was still filed as GR when the customer was unreadable.** The "nothing
  recorded anywhere" test was `blank($customer?->country)`, which is also true for a
  soft-deleted or absent customer — so «IT12345678901» with a deleted customer filed as
  domestic, with no name and no address. The GR default now yields to CONTRARY EVIDENCE, and
  that includes the ΑΦΜ's own country prefix (`Afm::countryPrefix()`): an «IT…» identifier says
  the party is not Greek whatever the country columns do or don't say.
- **The sanctioned GR default was never frozen.** It lived only in the builder, so a filed
  invoice recorded no country — and an operator later filling `customers.country`, a routine
  edit, made that already-filed document un-renderable.
- **The freeze gate inspected only the identity columns.** A foreign invoice with a complete
  identity but a blank address filed the live customer's address and froze nothing, then threw
  "requires a full address" on re-render while AADE held the real one. The address is now frozen
  exactly when it is filed — non-GR only, since AADE forbids it for a GR counterpart and
  recording it there would assert something never reported.
- **Every retail document would have been rejected by the provider.** The empty-name guard was
  applied to 11.x too, but retail has no legal counterpart to protect — only a printable name —
  and InvoSign hard-rejects an empty `CounterpartName` with `[88-001]`.
- **`canonicalVat()` and `comparisonKey()` contradicted each other inside one commit**: one
  stripped the «EL» prefix on the way out, the other kept it when deciding whether two parties
  are the same — so one taxpayer read as two and an invoice that used to file was refused.
  `customers.afm` legitimately carries the prefix (the VIES form-fill seeds a full VAT id).
- **An unresolvable snapshot country was silently replaced by the customer's** — «Germania»
  became the customer's «GR», breaking the recorded-value-is-evidence rule again.
- **`invoices.company_name` was varchar(120) against `customers.name` varchar(191)**, so the
  freeze could raise under strict mode inside the MARK transaction — discarding the record of a
  filing AADE had accepted. Fixed at the source by widening the column (migration), with the
  per-column truncation kept as a guard.

**Round-3 review corrections:**

- **Half of Europe was still filed as GR.** The ΑΦΜ country-prefix test matched «two
  letters then digits», but a real EU VAT id is rarely that shape — ATU12345678, CY12345678L,
  NL123456789B01, IE1234567FA, ESX1234567X. Only the digits-only form (IT) was recognised, and
  it was the only one a test covered. Worse, for a soft-deleted customer this was a strict
  REGRESSION: `origin/main` refused, the new code filed GR silently. The matcher now accepts any
  alphanumeric body but requires a digit in it, so free text starting with two letters
  («ΙΤΑΛΙΑ ΑΕ») is not read as a country claim.
- **A routine customer rename made legacy invoices unissuable.** The identity check treated a
  NAME difference as fatal even when the ΑΦΜ matched exactly, so every legacy row with a blank
  country (the majority, by the code's own comment) — and every credit note against one — was
  refused. The ΑΦΜ is the identity; a rename is not a different taxpayer, so a matching ΑΦΜ now
  short-circuits the name comparison. (The delivery-note twin stays stricter on purpose: its
  recipient name is operator-typed free text on a document whose whole point is naming a party.)
- **The provider's `API_Counterpart` field order had changed.** Splitting the block into two
  `array_merge` branches moved tax office / phone / email to the front, against the vendor
  reference and against the delivery twin — in a file that already documents InvoSign as a
  picky parser. Both branches now go through one assembler that emits the documented order.
- **The address is frozen for a GR counterpart too.** AADE omits it there, but the provider
  document carries it and so does our PDF, so a domestic invoice that froze no address could not
  reproduce its own provider payload once filed — the same "unreadable once filed" failure, one
  surface over.

**Consciously declined:** routing `SalesReconciler` through `Invoice::counterpartAfm()`. The
helper cuts the live-customer fallback off once a document is filed, which is right when
BUILDING a payload; reconciliation is the opposite problem — every row there is filed by
definition, and for an ETL row with a blank snapshot the customer's ΑΦΜ is the best available
evidence of what that MARK carried. Making the change failed all eight legacy-row reconciliation
tests as `contentIncomplete`, which is the permanent exit-2 that code's own comment warns about.
The reason is recorded at the call site.

**Round-4 review corrections (no P0/P1 — the fixes below close the remaining P2s):**

- **The ticket's own acceptance criterion — «ΑΦΜ, χώρα και όνομα σχηματίζουν ΕΝΑ πρόσωπο» — was
  still unmet.** Both `CreateInvoice` and the WHMCS mapper default a blank customer country to
  `'GR'`, so a «DE811234567» customer whose country was never filled in gets a GR snapshot — and
  because a country IS recorded, the ΑΦΜ's prefix evidence was never consulted. The original
  MYD-009 defect wearing a different hat. A recorded country that CONTRADICTS the VAT prefix is
  now refused, naming both values.
- **Free text in `vat_no` was read as a country claim.** The column is an unvalidated TextInput
  and a raw ETL copy, so «INV-2024-01» resolved to India, «VAT123» to the Vatican, «LTD 12» to
  Lithuania — each refusing an invoice with nothing wrong with it. A real VAT body carries at
  least seven digits (Ireland is the shortest), which every junk value falls under.
- **A punctuation-only ΑΦΜ passed two parties as one.** «-» trims non-empty but canonicalises to
  nothing, and an empty key equals a null customer ΑΦΜ, so the name check was skipped entirely.
- **Two definitions of "is this retail?"** — `filesNoCounterpart()` read the cache first while
  the builder files from the relation. It now reads the relation first, matching the builder.
- **The freeze covered a subset of what the PDF prints** (`address2`, `vies_vat` were missing),
  so a legacy invoice gained a partial address at filing. Both are frozen now — and the customer
  column is `vat_vies`, not `vies_vat`, which the first cut had wrong so it always froze null.

**Round-5 review corrections (no P0/P1 — the main action was REMOVING a round-4 check):**

- **The coherence refusal was wrong and is gone.** Throwing when the recorded country disagrees
  with the ΑΦΜ's prefix blocked legitimate documents — Monaco files under an FR VAT id, the Isle
  of Man under GB, Northern Ireland under XI — and told the operator to "correct" values that
  were already right, with no truthful way to proceed. A RECORDED country is the operator's
  explicit statement about the party; the prefix is an inference from a free-text column. The
  prefix stays what it should always have been: evidence for the case where nothing is recorded.
- **The prefix matcher accepted any ISO-2 code**, so «AE997073525» — a real nine-digit ΑΦΜ with
  two stray letters — read as the UAE and, combined with that refusal, made an ordinary domestic
  invoice unissuable. It is now restricted to prefixes actually used in front of a VAT id (EU +
  GB/XI/CH/NO), which also let the digit floor drop to 6 so Romania's short id (RO361902) keeps
  its evidence.
- **A «GR» prefix answered the question but was discarded**, falling through to a refusal that
  demanded a country the document already implied.
- **`vies_vat` froze truncated** (varchar(20) against `customers.vat_vies` varchar(30)) — the
  column is widened, and the migration's docblock no longer claims that only `company_name`
  diverged.
- **The missing-ΑΦΜ message named the wrong remedy**: it said "its customer has none either"
  even when the customer HAS one and the fallback is closed because the invoice names a
  different party — telling the operator to fill a field that was already filled.

**Round-6 review corrections (no P0/P1 — diagnosis accuracy plus one real placeholder bug):**

- **`vat_no = '0'` became an identity.** An all-zeros value is a PLACEHOLDER meaning "no ΑΦΜ" —
  the convention the delivery-note sentinel already uses — but it was reported as the
  counterpart AND closed the country fallback, because it matched no customer. Fixed in the one
  normaliser (`Afm::canonicalVat()` → null) so every consumer agrees; the first cut patched a
  single call site and the tests caught it immediately.
- **`XI` was inert, and the round-5 commit message cited it as a reason.** «XI» is a VAT
  jurisdiction, not an ISO country, so `IsoCountry` never knew it and the allowlist entry never
  produced a prefix. Its country is GB and it now maps there. (Monaco/FR and Isle-of-Man/GB were
  real; XI was not.)
- **Two refusal messages named the wrong cause.** The country refusal reported "the invoice names
  a different party" even when the linked customer WAS the counterpart and only its country was
  unrecognisable — and, unlike the code it replaced, it did not name the offending value. The
  ΑΦΜ refusal claimed "the linked customer has one" without checking that it does.
- **The six-digit floor is documented as the ASYMMETRIC trade it is**: a false positive refuses a
  good domestic invoice, a false negative only falls back to the recorded country (or the same GR
  default this code always used), so short real shapes (a two-digit RO id, an old IE format, a GB
  government id) are knowingly given up to keep junk out.

**Round-7 review correction (P1 — created by round 6's own fix):**

- **A placeholder `vat_no` was never replaced by what was actually filed.** Round 6 taught
  `canonicalVat()` to read «000000000»/«0» as "no ΑΦΜ", but the freeze gates on `blank()` — and a
  placeholder is not blank. So the payload filed the customer's real ΑΦΜ while the column kept
  the placeholder: a half-frozen legal identity, manufactured by MYD-009's own freeze. The PDF
  printed one party while AADE held another, every later render threw, and the row was
  unrecoverable (a filed invoice is not editable and credit notes copy the column verbatim).
  Round 6's test passed because it only asserted the resolvers, never the freeze.
- Two P2s of the same shape: the ΑΦΜ refusal tested `filled($customer->afm)` raw, so a customer
  whose ΑΦΜ is itself a placeholder was described as having one to borrow; and
  `DeliveryNote::externalRecipientAfm()` recognised ONLY the exact nine-zero sentinel while the
  normaliser recognised any all-zeros value — two strictnesses for one convention, now unified.

**Deferred (recorded in `docs/BACKLOG.md`):** `PeppolInvoiceDocument` still builds the buyer from
the live customer. Not a bug today — a tenant is either `gr-mydata` or `ee-peppol`, so no single
document can disagree with itself — but it becomes the same defect the day PEPPOL goes live.

**Round-8 review corrections (no P0/P1 — one of them a wrong DIRECTION, not a wrong value):**

- **Junk in an ΑΦΜ is not a declaration.** Round 7 widened "all zeros = no ΑΦΜ" to "anything
  unusable", which swept «-» and «.» in with it — so a delivery note that previously REFUSED was
  now silently filed as an ενδοδιακίνηση, and a linked customer's KNOWN ΑΦΜ was replaced by the
  «no ΑΦΜ» placeholder. (Round 8 fixed only the linked-customer half: `isInternalMovement()`
  requires `customer_id === null`, so a note with junk and NO other identity kept being declared
  internal — discarding the country the form MADE the operator pick. Round 9 closed that half
  too: junk now blocks the internal classification outright.) That is a change to merged MYD-011 semantics in the GUESS direction,
  made inside an invoice PR. All-zeros is a DECLARATION (AADE's convention for ενδοδιακίνηση);
  junk is an accident, and an accident falls through to the real identity
  (`Afm::isZeroPlaceholder()` now separates them).
- **`blank()` and `?:` disagree about the string «0».** Both the AADE builder and the provider
  document select the address with `?:`, which treats «0» as absent, while the freeze gated on
  `blank()`, which does not — so the payload filed the customer's postcode and the freeze kept
  the «0», leaving the filed document unable to render its own counterpart. The gate now mirrors
  the selector.
- **The customer-fallback branch was not canonicalised**, so a customer row holding «00000» was
  read verbatim as a real ΑΦΜ — on the very path round 7's commit said it had fixed.
- **`EnrichInvoiceFromAade` skipped exactly the rows that need it.** It gates on `blank(vat_no)`,
  so an all-zeros placeholder — now officially "not an identity" — was treated as filled, and the
  ONE tool that can repair an already-filed legacy row would not touch it.

**Round-9 review (no P0/P1 — the gate's stopping condition under the recalibrated rule in
`CLAUDE.md`). Three P2s, all fixed in the same pass rather than deferred, because two were
genuine misreports and all three were one-liners:**

- **Junk + no other identity was still declared an ενδοδιακίνηση** — the other half of the
  round-8 fix (see above). The claim in this file that the refusal had been "restored" was only
  half-true and is corrected.
- **`isZeroPlaceholder()` and `canonicalVat()` disagreed about a PREFIXED all-zeros value.**
  «EL000000000» is the same declaration as «000000000», but only the latter stripped the prefix —
  so the prefixed form took the junk path and was replaced by the linked customer's real ΑΦΜ, i.e.
  the placeholder filed as an identity: the exact conflation the helper exists to prevent.
- **The address freeze gate mirrored `?:` for «0» but not for whitespace.** `?:` is falsy for
  `''` and `'0'` only, so a single space froze the customer's real street while the provider
  payload carried the blank one.

**Acceptance:** editing a customer after issue leaves the preview XML byte-identical (asserted);
a filed invoice with a blank snapshot refuses rather than inventing an identity; an overtyped
party does not inherit the linked customer's country; the freeze fills blanks only and never
overwrites.

### MYD-010 — All filings hard-code branch 0

**Status:** WATCH 2026-09-02 (was OPEN/P0) · **Priority:** P2 · **Research:** CONFIRMED 2026-08-30

**Downgrade (triage 2026-09-02):** the finding is factually right and the code is
**currently correct**. AADE's own rule is that branch `0` is right when the issuing
establishment is the registered head office — and there is no branch concept anywhere in
this system to contradict it: no `Branch` model, no branch column on `companies`, no branch
field on any document or customer, and all three tenants are head-office-only. So `0` is
not a hard-coded guess here, it is the accurate value for every document we can currently
issue, and «freeze the issuing establishment per document» would be freezing a field that
does not exist yet.

Building the branch model now would be speculative: it needs a real multi-establishment
tenant to define what a branch IS (its own numbering? its own myDATA credentials? its own
address on the PDF?), and getting that wrong is worse than the current honest constant.

**Re-open when** a tenant actually registers a branch at ΑΑΔΕ — that is the trigger, and it
is visible: the go-live check and `mydata:preflight` are the natural places to surface it.
Until then this is WATCH, not OPEN: nothing is being filed incorrectly today.
`DeliveryNote.startShippingBranch`/`completeShippingBranch` remain unrelated (they describe
loading/delivery locations, not the PartyType branch).

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

**Status:** DONE 2026-09-01 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix:** the recipient's country is now FROZEN on the note (`delivery_notes.recipient_country`,
ISO-2, nullable) instead of being re-derived from a relation that only a *customer* recipient
has. `DeliveryNoteForm` snapshots it from whichever party the any-party picker resolved
(customer **or supplier**, both normalised on the way out) and requires it whenever there is an
external recipient at all — ΑΦΜ **or** a linked customer **or** a typed name, mirroring the
submitter's own test so the form can't save a note the submitter will then refuse. The picker
offers the **full ISO table** (`IsoCountry::options()`), not a shortlist: since an unresolvable
country is now refused, an omitted country would make that shipment unissuable.

`DeliveryNoteSubmitter::buildCounterpart()` no longer reads `customer?->country ?: 'GR'`. It
calls `recipientCountry()`, which resolves `recipient_country` (falling back to the linked
customer for notes predating the column) through the shared normaliser and then applies the
policy the finding demands: **an external recipient with no resolvable country is REFUSED**
with an actionable Greek error, never silently filed as GR. The single sanctioned GR default is
an **ενδοδιακίνηση**, and detecting it correctly took two corrections from review: the explicit
`000000000` sentinel (written by the seeder, promised by the form/infolist text, and treated as
internal by the PDF) must NOT read as an external ΑΦΜ, and keying on the ΑΦΜ *alone* would have
let a **named foreign recipient with no ΑΦΜ** ("Müller GmbH", a non-VAT/private party) fall
through to the GR default — the same misreport. Internal now means no recipient identity at
all: no external ΑΦΜ, no `recipient_name`, no `customer_id` — and an explicitly stored sentinel
wins outright, without falling back to a linked customer's ΑΦΜ (which would file a *different*
legal counterpart than the one the operator declared).

All four readers now share ONE definition, on the model
(`DeliveryNote::externalRecipientAfm()` / `isInternalMovement()` / `recipientCountryIso()`): the
AADE payload, the **PDF** (whose local heuristic called a named foreign recipient without an ΑΦΜ
an ενδοδιακίνηση, so the printed δελτίο contradicted what was filed), the **CMR**, and the
**InvoSign provider document** (which carried its own `000000000` chain and could send one
document asserting two different recipient ΑΦΜ).

`IsoCountry` validates the alpha-2 result against a real code table rather than a bare
`strlen === 2 && ctype_alpha` passthrough — otherwise `ZZ`/`XX` would sail through (the column
is `varchar(2)`, so *every* storable 2-letter value would have passed) and the "unrecognised"
refusal would be practically unreachable. That table is firebed's `CountryCode` enum **plus
`EXTRA_ISO`**: firebed's list is a snapshot missing several current ISO codes (SS, CW, SX, BQ),
so validating against it alone would have REJECTED real countries the old passthrough accepted —
tightening the *invoice* path into a regression. Option labels come from the enum's own Greek
names (all 247), so the picker is searchable by name, and the table is memoised.

**Keeping domestic notes issuable — without guessing.** A blanket refusal would break the common
case: `customers.country` is nullable free text and is often blank, so pre-existing domestic notes
would become unissuable. Two mechanisms cover them, and neither infers a country:

1. the **name index** — `IsoCountry` resolves the spellings the data actually holds, so far fewer
   notes reach the refusal at all (see below);
2. the migration **backfill** — `recipient_country` is filled from each note's linked customer
   where that country resolves. Notes with no customer, an unusable country, an already-filed
   MARK, or a recipient that isn't that customer are deliberately left null: those are exactly
   the ones an operator must decide.

An `Afm::isGreek()` checksum test was a third mechanism for two rounds — a valid Greek ΑΦΜ read as
positive evidence of a Greek party. It was **removed in round 8** (and with it the helper, so
`Afm` is untouched by this change): a bare 9-digit foreign VAT id satisfies mod-11 about 1 time in
10, and a foreign private individual looks identical. It also had to avoid digit-stripping, since
`digits('DE811234567')` is `'811234567'`, which passes the checksum — caught by a `php -r` probe,
not by the diff looking right.

The same frozen country now also feeds the **CMR** consignment note (`CreateCmrFromSource`),
which still read `customer?->country ?: 'GR'` and printed GR for a foreign consignee on the very
document that exists for international transport. It is surfaced in the ΔΑ infolist, on the PDF
for a non-GR recipient, and in the activity log (`loggedAttributes`), so a wrong value is
visible before issuing and auditable after.

The duplicated country normaliser is gone: **`App\Support\IsoCountry`** is now the one
implementation shared by the monetary-invoice and delivery payloads, so they can no longer
drift on the `EL→GR` / `UK→GB` aliases the delivery copy was missing (a 2-letter `EL`/`UK`
used to pass straight through as a non-ISO code). It exposes `normalise()` (throws — the
invoice path) and `tryNormalise()` (null — so a caller can refuse rather than default).

**Operational note:** a delivery note with an external recipient must now carry a country. The
form enforces it for new notes; an older draft whose customer has no country will be refused at
submit with a message naming the fix, rather than misreporting the party.

**Country resolution order** (seven review rounds to settle; each earlier order had a hole):
1. **ενδοδιακίνηση** — no recipient identity of any kind → GR (the issuer's own country).
   First, so nothing can override it: not a customer's country, and not a stale
   `recipient_country` left by a party pick the operator then cleared. An earlier round put the
   explicit country first to rescue "named foreign party stored with the `000000000`
   placeholder"; that case no longer classifies as internal (see the sentinel note below), so
   this order costs nothing;
2. the note's own `recipient_country`, else the linked **customer's** country — whichever
   **normalises** to a real ISO code;
3. otherwise **refuse**, quoting the offending value.

**There is no third source.** Two narrow GR inferences for customer-linked recipients (filing
the `000000000` placeholder → GR; a mod-11-valid Greek ΑΦΜ → GR) existed to spare legacy domestic
notes whose `customers.country` is blank, and both were removed in round 8. Round 7 had already
caught them firing over *recorded* country data (a customer reading «Italy» with no ΑΦΜ was filed
as **GR**); narrowing them to absent-only was not enough, because neither is evidence in the
first place — a foreign private individual, or a foreign customer with thin legacy data, has a
blank country and a 9-digit number that satisfies mod-11 about 1 time in 10. **The filing
boundary does not guess.** Legacy domestic notes get their country from the migration backfill
or an operator edit — a data fix, not a misreport.

**The submitter FREEZES the country it filed.** A note can be issued with the country resolved
from its linked customer, and nothing wrote that back — while the same `forceFill` set
`mydata_sent`, which makes `hasBeenFiled()` true and cuts off exactly that fallback. So the
country AADE holds went invisible the moment it was filed (no «Χώρα» line on the PDF, «δεν
καταγράφηκε» in the infolist, and the CMR quietly reading the LIVE customer country instead —
the very leak the gate below exists to close). Both persist paths now write
`recipient_country`, which is what finally makes the column true to its documented name. It
records the RESOLVER's answer, not the column's prior value: an ενδοδιακίνηση files GR whatever
the column holds, so trusting a non-empty column froze «DE» onto a note AADE holds as GR — and a
filed note is no longer editable, so that would have been permanent. Freezing through the resolver
also stores the normalised code («EL» → «GR»).

**A country prefix on an ΑΦΜ is evidence, not noise.** The linked-customer identity check compares
ΑΦΜ with separators and case folded but **letters kept** — `Afm::digits()` would turn
«DE811234567» into «811234567», which matches a Greek customer's ΑΦΜ, so the note would inherit
that customer's country and file a German party as GR with its own DE prefix in the same
counterpart. Keeping letters also matches the migration's exact SQL comparison, so the two mirrors
of the predicate agree; an «EL»-prefixed ΑΦΜ against a bare one is the one false negative, and it
fails safe (refusal until someone sets a country).

**A filed note stops inheriting the customer's country — on write AND on read.** The column is a
snapshot of what was SUBMITTED while `customers.country` is live, so a customer who has since
moved (or simply had the field filled in later) would otherwise put a country the AADE record
never carried onto the PDF, the infolist and the CMR, presented as the filed one. So: the
migration backfill skips filed notes, *and* `recipientCountryIso()` cuts off the customer
fallback once `hasBeenFiled()` — guarding only the write left the read wide open (round 8). The
predicate is `mydata_sent || mydata_mark`, shared by both, because a rejected-then-repaired note
can carry the flag without a MARK. A filed note is never re-submitted (a cancellation carries no
counterpart), so null is honest; recovering the true historical value means reading
`delivery_marks.request`, a separate job. The **CMR** deliberately keeps the fallback — it is
paper, editable before printing, and a blank country line on a cross-border consignment note is
worse than a stale one.

**The refusal is only as safe as the normaliser.** Every country name `IsoCountry` fails to
resolve is a note an operator cannot issue, so it matches the vendor enum's Greek labels for all
247 (accent-folded, so legacy «ΙΤΑΛΙΑ» meets the label «Ιταλία»), Latin names, alpha-3 codes, and
the colloquial spellings people actually type (ΑΓΓΛΙΑ, ΗΠΑ, ΣΚΟΠΙΑ, ΚΑΤΩ ΧΩΡΕΣ, ΤΣΕΧΙΚΗ
ΔΗΜΟΚΡΑΤΙΑ). A unit test asserts every one of the 247 labels round-trips to its own code, so no
name can be shadowed by another.

The `000000000` sentinel is treated as "this party has no ΑΦΜ", **not** as an override — the UI
offers no other placeholder, so letting it force an internal classification misreported named
foreign recipients. `isInternalMovement()` therefore means *no identity of any kind*, and the
AADE payload, the PDF, the CMR and the InvoSign document all share that one definition.

**Known limit (→ BACKLOG):** `suppliers.country` defaults to `'GR'` (DB + form) and
`customers.country` is nullable free text, so the *source* data can still say GR for a foreign
party. Converting those forms to the shared ISO picker was **tried inside this change and
reverted**: a plain `Select` attaches an implicit `in` rule, and the stored value is compared
raw — so every record holding «ΙΤΑΛΙΑ» became unsaveable even though `IsoCountry` now resolves
that string, because nothing normalises it on *load*. The ETL also rewrites the raw string on
every re-run. It needs a normalise-on-load + a column backfill + ETL alignment, so it is logged
as a follow-up rather than shipped half-done.

Tests: customer / supplier / manual / non-EU recipients serialize their own ISO code, `EL`→GR
and `UK`→GB, the customer fallback for pre-column notes, internal movement → `000000000` + GR,
the explicit-sentinel internal case, a named foreign recipient with no ΑΦΜ (refused without a
country, filed as DE with one), and both refusal paths (absent and unrecognised `ZZ` — a value
that actually fits the `varchar(2)` column, unlike a 3-char one that only "passes" on sqlite).
Plus `IsoCountry` unit tests (ISO validation, full-table options, every option round-trips, the
Greek/Latin/alpha-3 name index, and that an unknown name still resolves to null), a
`resolveRecipient()` test proving the picker carries the country for both party kinds, CMR tests
asserting a supplier recipient's country reaches the consignment note and that the paper document
follows the SAME `isInternalMovement()`/`externalRecipientAfm()` helpers as the payload, and
`RecipientCountryBackfillTest` for the migration's data half (drafts backfilled incl. legacy free
text, filed notes never touched, unresolvable left null, existing values not overwritten,
re-runnable).

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

**Status:** DONE 2026-08-31 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix (safe interim):** only the sandbox-validated 9.3 is fileable. Enforced by an
**allowlist** — `Codes::SUPPORTED_DELIVERY_TYPES = ['9.3']` / `isSupportedDeliveryType()`
— used by BOTH the delivery-type picker (`DeliveryNoteForm::deliveryTypeOptions`) and the
`DeliveryNoteSubmitter` guard, so picker and guard cannot drift. An allowlist (not a
denylist of 9.1/9.2) means any NEW/future 9.x code is treated as unsupported until we
explicitly build it — the safe default for a legal document. `defaultDeliveryTypeId()`
also verifies the ΔΑΠ-by-code shortcut resolves to a supported type before pre-selecting
it (a ΔΑΠ series mis-mapped to 9.1 no longer becomes an unfileable default). The types
stay seeded (for when the models exist) but cannot be selected or filed. Implementing the
real 9.1 correlated-MARK payload and 9.2 aggregation is logged in `docs/BACKLOG.md`. Tests
cover the picker exclusion (incl. a future 9.4), the submitter block (9.1/9.2/9.4), and the
ΔΑΠ→unsupported default fall-through. See `CHANGELOG.md` [Unreleased] → Fixed.

**Review follow-up (post-#387):** the first fix used a denylist (`UNSUPPORTED_DELIVERY_TYPES
= ['9.1','9.2']`) — a future 9.4 would have slipped through — and `defaultDeliveryTypeId()`
returned the ΔΑΠ row without checking its `mydata_type`. Flipped to an allowlist and added
the ΔΑΠ-type check (external review).

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

**Status:** DONE 2026-09-01 · **Priority:** P1 · **Research:** CONFIRMED 2026-08-30

**Fix:** new `SyncExpenseStateFromAade` service (the expense-side twin of
`SyncInvoiceStateFromAade`) applies AADE's live state onto an EXISTING local expense —
`forceFill(mydata_state, cancelled_by_mark)` + a forensic `ExpenseMark` `STATE_SYNC` row —
so a supplier cancellation (which `ExpenseImporter` skips because the MARK already exists)
now flips our VALID expense to CANCELLED without a re-import or a duplicate. Both directions
(CANCELLED / un-cancel back to VALID clears `cancelled_by_mark`); an unknown state throws
(never silently wipes ours); idempotent. Wired as a batch operator action «Συγχρονισμός
κατάστασης από ΑΑΔΕ» on `MyDataConsoleExpenses`, visible when the last fetch found
`stateMismatch` rows, refreshing the worklist after. No migration — `expenses` already
carry `mydata_state` + `cancelled_by_mark`; both accounting views (`LedgerBook`,
`VatPeriodReport`) already exclude `mydata_state='CANCELLED'`, so a synced cancellation
leaves the books immediately. Tests: the service (flip / idempotent / unknown-throws /
un-cancel), the acceptance round-trip (stateMismatch → sync → matched, no dup), and the
console action (visible-gating + applies the cancellation + writes the audit row). See
`CHANGELOG.md` [Unreleased] → Fixed.

**Whole-PR review follow-up (integrity hardening):** three gaps closed. (1) The reconciler
lost the real cancellation MARK — it recorded `$cancelledMarks[$m] = true` (a boolean) despite
firebed's `CancelledInvoice::getCancellationMark()`; it now captures `invoiceMark ⇒
cancellationMark` and folds it as `cancelledByMark` (both the inline `<cancelledByMark>` and the
standalone `<cancelledInvoicesDoc>` path). (2) `SyncExpenseStateFromAade` now **refuses** a
CANCELLED sync whose cancellation MARK is null/blank (a cancellation with no evidence is never
written; the expense is not mutated). (3) the console action stopped trusting the (up-to-12h)
serialized snapshot: `syncStates()` runs a **fresh** `ExpenseReconciler::reconcile()` at click
time and applies only the fresh `stateMismatch` rows, after re-checking tenant + `expense_id` +
that the fresh row's MARK still matches the expense being mutated. Tests: cancellation-MARK
folded (standalone `<cancelledInvoicesDoc>`), service refuses CANCELLED-without-MARK, and the
console ignores a stale cached row that a fresh reconcile no longer reports.

**Code-review hardening (same round):** `syncStates()` now isolates each row in its own
try/catch. A single AADE-cancelled row whose cancellation MARK is missing makes the service
throw (by design); before, that throw escaped the `foreach` and aborted the WHOLE batch —
leaving rows already synced above half-applied and skipping the final refresh. Now such a row
is skipped (logged + counted, surfaced in the toast as «N γραμμές παραλείφθηκαν»), the good
rows still commit, and the worklist still refreshes. Test: a CANCELLED-without-MARK row is
skipped without aborting the batch and without touching the expense/audit trail.

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

**Review follow-up (post-#387):** added an aggregator fixture with a zero-value 8.6
«Δελτίο Παραγγελίας Εστίασης» alongside a real 100€ sale — asserting the 8.6 is
COUNTED (outputCount) yet contributes 0 to Έσοδα/ΦΠΑ (never inflates the picture),
so the +sign «zero-value» stance is exercised end-to-end, not just at the sign
policy (external review).

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

**Status:** DONE 2026-09-01 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix:** a new **`contentMismatch`** bucket, separate from `stateMismatch` (different repair).
Both live reconcilers (`SalesReconciler`, `ExpenseReconciler`) previously routed a row to
`matched` as soon as the MARK existed on both sides and the cancellation flag agreed. Now,
in that same branch, they compare the legally-relevant content via one shared pure helper
`ReconciliationContentComparator::compare(LocalDocSnapshot, AadeDocSummary)` (so sales and
expenses can't drift): **gross** (explicit ±0.01 cent tolerance), **§8.1 type**, **series /
ΑΑ**, **issue date** (normalised to Y-m-d), and **counterpart ΑΦΜ** (digits-only, and only
when AADE returns one — retail 11.x has none). A value CONFLICT routes the row to
`contentMismatch` with a Greek `problem` listing exactly which fields differ; nothing is ever
silently rewritten. `discrepancyCount()` counts the new bucket, and both consoles render it
(the blade defaults a missing bucket key to `[]` so a pre-deploy cache payload can't break the
page). The expense reconciler now also captures the doc `invoiceType` so the type compare
works on the expense side. Tests cover sales + expenses, the cent tolerance, a
retail-without-counterpart match, and type/series/gross divergences. See `CHANGELOG.md`
[Unreleased] → Fixed.

**Whole-PR review follow-up (2 rounds → `contentIncomplete`):** the first round narrowed the
comparator to flag a field ONLY when BOTH sides carry a value, so a null/blank LOCAL field
wouldn't balloon the danger bucket — but that left an incomplete record reading as a green
`matched` (still a false green). The synthesis: the comparator returns a structured
`ContentComparison` (`conflicts` + `incompletes`); a field AADE carries but the LOCAL record
LACKS is an **incomplete**, routed to a NEW separate warning bucket **`contentIncomplete`**
(unverified — complete it, don't trust it), while a genuine value clash stays the danger
`contentMismatch`. AADE-absent fields (retail 11.x ΑΦΜ) are still skipped. Both buckets count
in `discrepancyCount()`, both render on the two consoles (warning colour for the incomplete
one), and the `mydata:reconcile-sales` CLI lists both in its summary table + detail loop (they
feed exit-2, so they must be visible). Tests: `contentIncomplete` on sales + expenses, the
fixed retail test (AADE-null ΑΦΜ actually reaches the comparator via `array_merge`, not `??`).

**Code-review hardening (same round):** three follow-ups on the `contentIncomplete` change.
(1) **Relation fallback** — `SalesReconciler::snapshotFrom()` now resolves the invoice's type
and counterpart ΑΦΜ from the relations when the denormalised caches are null
(`mydata_type ?: invoiceType->mydata_type`, `vat_no ?: customer->afm`): app-issued invoices
carry the snapshot columns, but ETL-imported legacy invoices don't (the ETL snapshots the type
onto `invoice_types`, not each invoice), so without this EVERY legacy invoice — matched by
state against a real production MARK — would read as a permanent `contentIncomplete` and flip
the scheduled reconcile to exit-2 forever. The relation value is exactly what would have been
snapshotted, so it never masks a real difference (fires only when the cache is empty).
(2) **Date guard** — `normDate()` returns null (never a fabricated date) on a blank/unparseable
value, and the issueDate branch now uses `present()` + a both-non-null-and-differ check, so an
empty AADE `issueDate` can no longer be parsed into "today" and manufacture a one-sided
conflict. (3) **Net/VAT split** — comparing gross catches a total divergence but not a
same-gross/different-VAT-split one; adding net comparison is a noted follow-up (BACKLOG), not
done here. Tests: legacy-null-caches → matched via relations.

**AADE-side fail-open closed (review round 2):** the date guard above fixed a false CONFLICT
but traded it for a false GREEN — the comparator skipped any field the AADE summary did not
carry, so a blank/unparseable `issueDate` (or a missing gross/type/series/ΑΑ) read as `matched`,
and a test even locked that in. Now the **mandatory** AADE header — **gross, §8.1 type, series,
ΑΑ, issue date** — routes to `contentIncomplete` when it is absent or unreadable ("λείπει από
την ΑΑΔΕ — ανεπαλήθευτο"): we could not verify the document, so it is never green and never a
conflict (the local value isn't contradicted). **Counterpart ΑΦΜ stays optional** — myDATA
legitimately omits it for retail 11.x, so an absent AADE ΑΦΜ really is "nothing to verify".
Tests: a data-provider over all five mandatory fields, plus blank and unparseable AADE dates.

**Net/VAT split + gross basis (review rounds 3–4):** gross alone cannot catch a wrong VAT
category whose net and vat compensate to the SAME gross (100+24 local vs 110+14 at AADE), so
**net** (`<totalNetValue>`) is now compared too, as a mandatory field with the same three-outcome
routing. Fixing that surfaced the real basis bug — and the first attempt (`Invoice::payableTotal()`)
was itself wrong on two counts a follow-up review caught: (a) `payableTotal()` falls back to
`gross_total` (never null), so a null-gross invoice became a `0,00` CONFLICT instead of an
incomplete, and it deducts withholding only when `withhold_category` is set — which the Firebird
ETL never imports, so every legacy ΠΚ-3 invoice would STILL false-conflict; (b) `net_total`/
`gross_total` are a different *rounding shape* (rounded once over the sum) from the FILED summary
(`InvoiceVatBreakdown`, rounded per VAT rate), so multi-rate discounted invoices diverged by a
cent. The correct basis is a new **`FiledInvoiceTotals::for()`** that reconstructs exactly what the
submitter files (per-rate roll-up + the [208] adjustment); a null field (no lines, or withholding
without its §8.4 category) is reported as **unverified** (`contentIncomplete`), never a fabricated
conflict. `FiledInvoiceTotals` is now the single basis for the reconciler, the console «μικτό»
column AND the per-invoice «Σύγκριση με ΑΑΔΕ» (which read `gross_total` and thus contradicted the
console). Money tolerance is compared in **integer cents** (`abs($a-$b) > 0.01` was
magnitude-dependent). Expenses import the AADE summary verbatim, so their columns were already the
right basis; only `net_total` was wired in. Tests: same-gross/different-net conflict (sales +
expenses), a withholding invoice matching the adjusted gross (with a precondition that filed vs
ledger gross really differ), a legacy category-less ΠΚ-3 → unverified, a multi-rate discounted
invoice that must NOT false-conflict on rounding, and the sales fold's `<totalNetValue>` parse.

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

**Status:** DONE 2026-09-02 (series) · **Priority:** P0 · **Research:** CONFIRMED 2026-08-30

**Fix:** the **series** — the half of this finding that could cause a DOUBLE FILING — is now
frozen per document (`invoices.series`, `delivery_notes.series`). Everything that identifies a
document to AADE now reads `Invoice::filedSeries()` / `DeliveryNote::filedSeries()` instead of
the live `invoice_types.code`: the payload header (`AadeInvoiceDocument`, `DeliveryNoteSubmitter`),
the **in-doubt recovery** (`MyDataSubmitter::adoptExistingMarkIfPresent`), the provider status
lookup (`InvoSignTransport::status`), the reconciler snapshot (`SalesReconciler`), the MARK detail
audit view and the accounting ledger.

The recovery path was the real P0: it searched AADE for the CURRENT `invoiceType->code`, so a
series rename after an ambiguous POST made it look for a (series, ΑΑ) AADE had never seen. Finding
nothing, it concluded the earlier POST was lost and filed the document a **second time** — and
AADE does not dedup (proven on the sandbox 2026-07-07: the same invoiceUid yielded two MARKs).
`MyDataSubmitInDoubtTest::test_recovery_searches_the_series_the_document_was_filed_under` pins it;
reverting the one-line fix makes that test attempt exactly that second POST.

Existing rows did not have to be guessed. For a document already FILED, the request XML stored on
its issue MARK is authoritative — literally what we sent. Everything else falls back to `invcode`,
which is itself frozen and is exactly `series . code` (legacy `GET_INV_CODE` concatenates with no
padding or separator; `InvoiceNumberer` reproduces that). The two disagree in one real case, which
is why the MARK is consulted first: a draft numbered under «ΤΠΥ», the type renamed to «ΤΠΥ2», and
only then filed — AADE holds ΤΠΥ2 while `invcode` still says ΤΠΥ, so freezing the invcode value
there would turn a row the reconciler currently MATCHES into a permanent conflict, this fix causing
the very problem it exists to prevent. Only issue marks (INSERT / PROVIDER_INSERT) that carry a
real MARK are read (a CANCEL row's `request` is a free-text reason, a dry-run or rejection was never
accepted), oldest first so a re-file cannot rewrite an identity. `App\Support\DocumentSeries` +
`App\Support\FiledSeriesBackfill` are the ONE definition, shared by the migration backfill, both
models' `creating` hooks, the Firebird ETL and the Epsilon importer (both query-builder writers, so
no model hook fires there) — a stored value and a recovered one cannot disagree. The ETL runs the
filed-XML pass AFTER `copyMarks()`, since the legacy `MARK.REQUEST` XML is not local until then. A pair it cannot
read stays null and falls back to the live type code, i.e. exactly today's behaviour, so no row is
made worse. Two traps found while building it: cutting `invcode` with a BYTE offset while counting
CHARACTERS sliced «ΤΠΥ» in half (the series is routinely Greek), and reading the frozen column with
`?:` would have discarded a legitimate `'0'` series and silently fallen back to the live lookup.

**Deliberately NOT frozen here (deferred, see `docs/BACKLOG.md`):** myDATA type, per-line
income classification, the quantity flag, payment-method mapping and the VAT/exemption code.
Those change a payload's *content*, not its *identity* — they cannot cause a duplicate filing or a
missed recovery, they are deliberate configuration acts, and `mydata:preflight` already audits them.
Freezing `mydata_type` in particular is not a one-liner: `SalesReconciler` documents a load-bearing
fallback keyed on it being null for ETL-imported rows, so writing it earlier would need that path
reworked in the same change. Kept out to keep this fix minimal and reversible.

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

**Status:** DONE 2026-09-02 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Fix, in two halves.**

**Invoices — the marker is now ARMED BEFORE the POST.** The in-doubt mechanism existed and was
sandbox-proven, but `mydata_pending_since` was written only inside a `catch`, so it existed only
if the process SURVIVED. A hard kill (OOM, deploy, host failure) between AADE accepting the
request and our catch left no durable trace; the 120s cache lock then expired and the next
attempt POSTed blindly into a filing that already existed. Arming first inverts the default: the
acceptance window is protected unless we positively learn the POST created nothing. `armInDoubt()`
deliberately THROWS (a filing we cannot record is exactly the unrecoverable case), while the new
`disarmInDoubt()` clears it on the four outcomes that PROVE no MARK — 401, 429, a pre-send
protocol error, and an explicit AADE rejection. That last one matters in the other direction: an
operator who fixes rejected data must be able to retry at once, not sit out the grace window.

**Delivery notes — they had NOTHING, and now mirror the invoice gate exactly.** No lock, no
marker, no adopt-or-file, and no service-level cancelled guard: two concurrent requests could each
POST and create two AADE documents for one local δελτίο. Added: the same 120s cache lock (never a
DB row lock — it must not be held across the AADE call), a fresh re-read under it, the same
arm/disarm around every outbound call (provider branch included), `delivery_notes.mydata_pending_since`,
and adopt-or-file via `SalesReconciler` — `RequestTransmittedDocs` does not filter by type, so a
9.x δελτίο appears there exactly like an invoice and is matched on the same frozen (series, ΑΑ).
Reusing the proven reader beat writing the lookup a second time. A tenant that cannot READ myDATA,
or an unreachable AADE, yields a REFUSAL rather than a blind retry — if we cannot verify, we do
not gamble.

Also added the missing **service-level `local_status=cancelled` guard** the finding called out:
the UI hiding the button is not protection for a CLI, API or automation caller, which is exactly
where it would go unnoticed.

**Deliberately NOT built: the `issue_attempts` table** the finding asks for (attempt id, payload
hash, frozen coordinates). The acceptance criteria — parallel submit, timeout after accept,
process kill after POST, DB failure after success, locally-cancelled service call — are all met by
the existing marker moved before the POST, at a fraction of the risk of introducing a new table
into a legal path. Revisit only if a real failure shows the single timestamp is not enough.

The **provider** side's durable idempotency is PROV-001 and stays open; this change gives that
path the lock, the cancelled guard and the arm/disarm, but not provider-side reconciliation.

**Review round 1 found six issues, ALL in the delivery half** — the invoice half was correct.
The P0 is the sharpest lesson: `InvalidResponseException` and `TransmissionFailedException`
SUBCLASS `MyDataException`, so the delivery path's generic `catch (MyDataException) { disarm }`
swallowed exactly the two AMBIGUOUS cases (empty 200 body; 5xx after AADE may already have
accepted) and cleared the marker → blind re-POST → two δελτία. `MyDataSubmitter` has had a
dedicated arm for those since MYD-2; mirroring the gate meant mirroring the catch ORDER too, and
that is what «mirror the invoice path» has to mean. Also fixed: arming ran before `initFirebed()`
and the provider issue-date guard, so a local pre-flight error that never sent anything locked the
note out for the whole grace window (now armed as late as possible, strictly before the first
byte); an adopted note got no `mydata_url`, so «Έναρξη διακίνησης» refused it and invited the
re-issue adoption exists to prevent (AADE's `qrCodeUrl` is in the RequestTransmittedDocs response
and was simply dropped by `AadeDocSummary` — now carried); adoption skipped the stock movement both
other success paths perform; and «this tenant cannot READ myDATA» returned the same `null` as
«AADE verified empty», so past the grace window a provider tenant re-POSTed blindly (now a refusal).

The sixth was in `TenantCoherence`: the RELATION checks were decorative inside the panel.
`CompanyScope` filters a lazy load by the ambient tenant, so a cross-tenant `customer_id` resolved
to NULL — and a null relation is legitimately allowed. It fired from CLI and queue but stayed
silent exactly where an operator sits, the opposite of the context-independence the class promises.
Relations are now resolved `withoutGlobalScope`.

**Review round 2** found six more, none P0. The two that mattered: a NULL `$first`/`$firstResponse`
(an empty or unparseable ResponseDoc) was being disarmed as if it were a rejection — but «no
response» says nothing about whether a MARK exists, so it now stays ARMED, decided at the throw
site which knows the difference rather than in the catch which does not (both services). And the
round-1 «cannot verify → refuse» fix was UNCONDITIONAL, so with nothing in the app able to clear
`mydata_pending_since`, a provider tenant with no myDATA read credentials ended up with a
permanently unsubmittable legal document — a worse operational failure than the risk avoided. It
now refuses only inside the grace window and files with a loud warning past it; provider-side
verification (InvoSign exposes an invoice_status endpoint) is PROV-001. Also: the invoice adopt
path never stamped `mydata_url` although this change had just added `qrCodeUrl` to `AadeDocSummary`
for exactly that reason (a self-healed invoice printed a QR-less PDF); the in-doubt gate matched
only `mydata_state === null` while `performSubmit` treats `''` as equally never-filed, so an armed
document carrying `''` skipped adopt-or-file entirely; and `TenantCoherence` did not check
`lines.product.productCategory`, which drives the per-line E3 classification — now checked in ONE
query per level, not one per line.

**Review round 3** found three, no P0. The one that mattered: the delivery in-doubt lookup is a
**READ**, but it primed firebed with `initFirebed()`, which resolves the **SUBMISSION** mode. For a
provider tenant those differ — `mydata_mode` is `off` while the read credentials sit in the
sandbox/production slot — so the lookup either threw forever (stranding the note: the round-2 bug
reached through another door) or verified against the AADE **dev** endpoint, saw nothing, and filed
a second δελτίο past the grace window. Now `FirebedCredentials::init()`, which is documented as THE
place for read access and resolves `mydataReadMode()` — the same predicate `canReadMyData()` gates
on two lines earlier. Also: `TenantCoherence` gated the line/product branch on `relationLoaded()`,
so on the panel's own submit paths (ViewInvoice, the invoices bulk action, ViewDeliveryNote) the
whole line + E3 check was inert — the same «decorative in the panel» shape as the `CompanyScope`
finding — now `loadMissing('lines')`, which `AadeInvoiceDocument::build()` does a moment later
anyway; and an adopted mark row carried no `invoice_url`, so the self-healed document's «Ιστορικό
myDATA» showed no QR link (both services).

**Review round 4** found two P1s and a P2 — both P1s again in FIX code, and both were «the fix did
not actually fix what it claimed». The line/product check was inert in the panel a **second** time:
round 3 replaced `relationLoaded()` with `loadMissing()`, but that still reads THROUGH
`CompanyScope`, which returns nothing for a foreign line — so the loop ran over an empty set and
passed. It now reads `withoutGlobalScope`, like `assertRelation()` two lines above always did, and
deliberately ignores an already-loaded `lines` (loaded through the scope, so trusting it would
reintroduce the hole). The tests missed it both times because tests have no ambient context; the
new ones set the context Filament sets on `TenantSet`.

The second was the stranding bug through a THIRD door: `canReadMyData()` only checks that an
aade-id is present, while `FirebedCredentials::init()` additionally needs a non-empty, decryptable
subscription key — so a half-configured tenant (or an APP_KEY rotation) threw «myDATA unreachable»
forever, and the read-less escape hatch sat behind `! canReadMyData()` where that could never reach
it. Priming is now separated from fetching, because the two failures mean opposite things: a local
config error is not evidence about AADE. Both routes share ONE policy
(`unverifiableInDoubt()`) — refuse inside the window, file past it with a loud warning — instead of
being written twice. P2: an adopted row always claimed a direct `INSERT`, mislabelling a ΥΠΑΗΕΣ
filing in the δελτίο's history; it now records `PROVIDER_INSERT` + `provider_key` when the tenant
files through a provider.

**Review round 5** found NOTHING in the round-4 diff. Its three findings were pre-existing
exposure: the provider invoice path was the last filing entry point with no lock (added here — same
key as the direct path, so a tenant switching channel cannot race itself), and two genuinely
provider-side items — a durable pre-POST marker there is useless without provider-side verification,
and a provider-filed 9.x δελτίο reaches AADE only after the ΥΠΑΗΕΣ relay, so the 10-minute grace
(tuned for the direct ERP feed) may be short. Both are **PROV-001**, recorded in `docs/BACKLOG.md`
with the reason; `EInvoiceProviderTransport::status()` already exists, so that work is mostly wiring.
A P2 on the adoption row's provider label was fixed.

Tests: `DeliveryNoteExactlyOnceTest` (20), `TenantCoherenceTest` (21), + four added to
`MyDataSubmitInDoubtTest`. Every fix across all five rounds was verified to make its test fail when
reverted.

**Still open on the provider path** (PROV-001): a hard kill mid-POST on a `gr-provider` tenant
leaves no durable marker. The lock covers the concurrent case, `unverifiableInDoubt()` covers the
window; the crash case needs the provider status query. Both
arming tests read `mydata_pending_since` **through the query builder from inside the outbound
call** — the way a different process would see it after a kill — and both fail when the arming
line is removed.

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

**Status:** DONE 2026-09-02 · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Fix:** `App\Support\Tenancy\TenantCoherence` — ONE fail-closed assertion, called at all
**11** outbound entry points before payload construction, audit writes or any request:
`MyDataSubmitter` submit/cancel/previewXml, `GrProviderSubmitter` submit/cancel,
`DeliveryNoteSubmitter` submit/previewXml, and all four `DeliveryLifecycleService`
operations (registerTransfer / confirmDelivery / refreshStatus / cancel).

It asserts on the DATA, never on the ambient context — deliberately. `CompanyScope` is a
documented no-op outside a request (CLI, queue, webhooks), which is exactly where the
automation that could carry this bug runs, so the panel's scoping is a convenience and not
a boundary. The check covers the document AND every relation whose values reach the
payload: counterpart, invoice/delivery type, payment method and (when loaded) the lines.
That is the realistic shape of the bug — a mis-set `customer_id`, not a wholesale wrong
invoice.

The provider path is the sharpest case and is why `previewXml` is guarded too:
`InvoSignDocument` reads its issuer fields from `$invoice->company` while the credentials
come from `$this->tenant`, so a mismatched call produces ONE payload asserting TWO
different issuers — and `previewXml` writes a DRY_RUN audit row carrying it.

`TenantCoherenceTest` asserts three things per case, because «it threw» is not the
requirement: it threw, **no audit row was written**, and **the Guzzle queue was never
touched** (the mock's remaining count is the proof nothing reached the wire). Both
directions are covered — a coherent invoice must still reach the wire, and an
int-vs-string `company_id` (which a query builder can return) is the same tenant. With the
assertion stubbed out, 10 of the 12 tests fail.

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

**Status:** PARTIAL 2026-09-02 (storage DONE; strict refusal → BACKLOG) · **Priority:** P0 · **Research:** CONFIRMED 2026-08-31

**Framing correction 2026-09-02.** myDATA cancellation exists and works today, for
invoices and for delivery notes alike — nothing here is legacy. What changes under a
ΥΠΑΗΕΣ **provider** is only the *invoice* side: there is no provider cancel, so the
correction is a credit note (πιστωτικό). Provider **9.3 delivery notes** DO have a
cancel (`iNVOSign_CancelDeliveryNote`). So the Ακύρωση button stays; it simply routes
differently per channel. This item is about the EVIDENCE those cancellations leave
behind, not about whether cancellation is available.

**Done 2026-09-02 — the two MARKs now go in separate columns, on every path.**
A cancellation produces two distinct MARKs: the document being cancelled, and AADE's
own MARK for the cancellation act. `mydata_marks.cancellation_mark` has existed since
2026-06-05 and the direct-invoice path already wrote both correctly. The other three
paths did not:

| Path | `mark` before | now | `cancellation_mark` |
|---|---|---|---|
| direct invoice (`MyDataSubmitter::finaliseCancellation`) | issue MARK | unchanged | already correct |
| direct delivery (`DeliveryLifecycleService::cancel`) | issue MARK | issue MARK | **new** |
| provider delivery (`cancelViaProvider`) | cancellation MARK, **else issue MARK** | issue MARK | cancellation MARK |
| provider invoice (`GrProviderSubmitter::cancel`) | cancellation MARK, **else issue MARK** | issue MARK | cancellation MARK |

The `?? $markToCancel` fallback on the two provider paths is the actual defect: a CANCEL
row did not merely lack evidence, it positively ASSERTED that the issue MARK *was* the
cancellation proof — and the provider channel is the one that becomes mandatory. A
`delivery_marks.cancellation_mark` column was added to match `mydata_marks`.

**Also closed: the same defect at a second entry point.** «Συγχρονισμός κατάστασης από
ΑΑΔΕ» (`SyncInvoiceStateFromAade`, reached from `MyDataMarkDetail`) adopted a remote
CANCELLED with no evidence at all, even though the read that produced the state had
AADE's cancellation MARK in hand and `TransmittedDocReader` was discarding it
(`$cancelledMarks[$m] = true`). The MARK is now threaded reader → `MarkDetail` → page →
sync and recorded on the STATE_SYNC row, matching the expense twin (MYD-014).
Deliberately WITHOUT that twin's refusal: this is the route that repairs an invoice
whose cancellation we learned about late, so refusing over missing evidence would
strand the very document it exists to fix.

**Display:** all three surfaces that showed a CANCEL row's MARK now show both — the
delivery-note PDF audit table and the delivery/invoice mark relation managers. The
invoice `cancellation_mark` column had existed for three months and was never rendered.

**Review round 1 (no P0/P1) — three fixed, one deferred.** Fixed: the state-sync
cancellation MARK is now shape-checked before it reaches the audit trail (its only
caller passes it from a client-writable Livewire property, so an arbitrary string
would have become fabricated «AADE evidence», and one over 40 chars would have
aborted the sync on a column error); an empty `cancellationMark` normalises to NULL
on both cancel persists (`array_filter` strips only nulls, so `''` would have
persisted and read as evidence); and a companion migration un-inverts any historical
provider CANCEL row whose `mark` holds the *cancellation* MARK, separating the two
kinds against the document's own INSERT row and refusing to guess when that row is
absent (expected to touch zero rows — no tenant has filed through a provider in
production yet). Deferred to `docs/BACKLOG.md`: the MARK page's XML panel shows the
latest exchange for a MARK, so a CANCEL row now hides the original filing XML — but
that is how the direct path has always behaved, so this change made the provider path
*consistent* rather than introducing a regression.

**Review round 2 (one P1, in the round-1 FIX) — all four fixed.** The backfill looked
for a sibling `PROVIDER_INSERT` only, while both `cancel()` paths deliberately read
the MARK from `['PROVIDER_INSERT','INSERT']` — so a tenant migrated
gr-mydata → gr-provider that cancels a directly-filed document through the provider
found no sibling and kept its inverted evidence (P1). Also: the sibling lookup gained a
`where('id','<',…)` bound, so a later adoption/recovery INSERT cannot be adopted as the
cancelled document; the companion migration's `down()` now REFUSES to drop the column
while it holds a cancellation MARK that exists nowhere else (a routine
`migrate:rollback` would have silently destroyed it — the snapshot path is unaffected);
and the `''` normalisation turned out to be missing at a THIRD persist site
(`MyDataSubmitter::finaliseCancellation` — firebed returns `''`, not null, for an empty
element). Per the «fix at the ROOT» rule that third occurrence was not patched locally:
the policy is now one definition, `App\Support\MyData\CancellationMark`, with
`clean()` for transport values and `fromUntrusted()` for the client-writable path.

**Deferred — strict refusal on a markless `Success` (→ `docs/BACKLOG.md`).** The
finding also asks that a fresh normal `Success` without a cancellation MARK stay
non-terminal. Not done, and not a small change in isolation: the direct-invoice path
recovers a refused cancel through the `[251]` «already cancelled» self-heal, but the
**delivery** and **provider** cancel paths have no already-cancelled adoption at all.
Refusing there would leave the document cancelled remotely and VALID locally, with the
retry throwing forever — the exact stranding pattern that had to be undone three times
during MYD-021. Refusal must therefore ship together with already-cancelled adoption on
those two paths; that pairing is the BACKLOG item.

**Official finding** (as filed 2026-08-31 — see the dispositions above)

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

**Status:** PARTIAL 2026-09-02 (series DONE; remainder P2) · **Priority:** P0 → P2 · **Research:** CONFIRMED 2026-08-31

**Triage 2026-09-02 — the finding overstates the invoice case.** The AADE issuer block on an
invoice is **only** `vatNumber` + `country` + `branch` (`[219]`/`[220]` actively FORBID name and
address for a GR party — that is why `AadeInvoiceDocument` does not send them). So changing a
company's legal name, commercial name, address, ΔΟΥ or ΚΑΔ **cannot** rewrite a filed invoice's
payload: those fields are not in it. The finding's «rewrite historical XML» risk applies to the
9.x **delivery note** (which does carry issuer name+address) and to regenerated **PDFs**, not to
monetary filings.

Of the fields the finding lists, the one that was genuinely filing identity is the **series** —
and it was the dangerous one, because the in-doubt recovery searched by it. **That is now frozen
per document (see MYD-018), which closes the duplicate-filing half of this item.**

**ΑΦΜ and ΓΕΜΗ: a warning, not a snapshot.** The ΑΦΜ is both the issuer identity and the
myDATA/provider **credential** identity — change it and nothing authenticates, every existing MARK
belongs to a different legal entity, and the correct operation is «new company», not «edit». A
snapshot column would not help: it would let the two diverge silently. The company form now shows
an advisory on `afm` and `gemi` once anything has been filed under the current value
(`Company::filedDocumentCount()`, `CompanyForm::identityChangeWarning()`), stating how many
documents are already filed and that a change normally means a new company. Deliberately NOT a
block — fixing a typo before the first filing is legitimate.

**Deferred to P2 (`docs/BACKLOG.md`):** freezing issuer **name + address** on 9.x delivery notes
and on regenerated PDFs. Real but bounded: it changes a *representation* of a past document, not
its filed identity, the remote record is unaffected, and no tenant has moved premises yet.

**Acceptance (revised)**

- Editing an InvoiceType cannot change an existing numbered document's XML or its in-doubt
  lookup coordinates. ✅ (MYD-018)
- Editing ΑΦΜ/ΓΕΜΗ on a company with filed documents warns the operator with the count. ✅
- Delivery-note issuer address and regenerated PDFs still read the live Company. ⏳ P2.

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

**Status:** DONE 2026-09-02 · **Priority:** P0 → P1 · **Research:** CONFIRMED 2026-08-31

**Triage: the finding's remedy is wrong for this system.** It asks to «block company deletion when
any legal document/audit evidence exists» and to replace the cascades with restricted deletion.
That would break a routine, legitimate operation: a tenant sharing a host outgrows it, is exported,
restored on its own VM, and the old copy must then be removed **completely**. A company that can
never be deleted is its own operational failure — the same over-strictness that made the first cut
of MYD-021 strand provider tenants permanently. Deletion stays possible, and stays a hard delete.

**The real defect is that it was SILENT, not that it was possible.** Three concrete holes:

1. The `--force` gate counted only `invoices.mydata_state = 'VALID'`. A document carrying a **real
   MARK whose state cache was never written** was invisible to it, as was **every CANCELLED
   invoice** and **every delivery note** — a tenant made of those was wiped with no warning at all.
   `CompanyWipeTest`'s own fixture turned out to be exactly that case: a real MARK, no state, and
   the wipe went through. That is the finding's substance and it is fixed.
2. The company-delete confirmation said nothing about what it was about to destroy.
3. A departing tenant had no way to take its παραστατικά in readable form.

**Fix.** `App\Support\LegalEvidence` is ONE definition of what a tenant has actually filed — real
MARKs across all three mark tables, plus VALID/CANCELLED documents, plus the date range — shared by
the wiper gate and the delete confirmation, so the number the operator is warned about is the number
the guard counts. Query-builder with an explicit `company_id`, never Eloquent: the mark models carry
`CompanyScope`, which is a no-op on the CLI and the WRONG tenant in a super_admin panel action.
Forensic rows (DRY_RUN/REJECTED, null mark) are deliberately NOT evidence — counting them would
block a clean-slate re-import over a dry run, the workflow the wiper exists for.

The company-delete action now names the evidence, states that **AADE keeps its records either way**
(the risk is losing local proof, not un-filing anything), requires two acknowledgements, and links
to the export tools. Deliberately **no forced backup**: the operator has usually just taken one —
this follows an export→restore migration — and a guard that makes them sit through a second copy is
one they learn to route around.

New **`php artisan company:export-pdfs --tenant=SLUG`** (`DocumentPdfArchive`) renders every invoice
and delivery note to PDF into one zip with an `index.csv` (Excel-safe BOM) and a README — the
handover artifact for a tenant that will no longer have this system. A command, not a panel
download: tens of thousands of documents must not sit in an HTTP request. Memory is bounded to one
PDF (temp file + `ZipArchive::addFile`, never `addFromString`), and one unrenderable document is
listed in `errors.txt` rather than costing the operator the other 9,999.

**Review round 1 found seven, all but two in the brand-new `DocumentPdfArchive`.** The worst was
the one the class exists to prevent: `chunkById` pages by `id > lastId`, so the `orderBy('issued_at')`
layered on top let a page end on a low id and the next window jump straight past everything between
— measured at **119 of 120 documents**, absent from the zip AND from `errors.txt`. Ordering is now
by id and chronology is restored when the index is written. Also: the eager loads kept
`CompanyScope`, so in the panel path every PDF would have rendered with no lines, no customer and no
type — a zip of blank documents, silently; unchecked `file_put_contents`/`close()` reported success
over a truncated archive on a full disk; `index.csv` dropped the formula-injection guard that two
other exporters in this repo apply, to names that are operator- and WHMCS-sourced and will be opened
in Excel by someone outside the organisation; and `LegalEvidence` counted `expense_marks` written by
`SyncExpenseStateFromAade` — the **supplier's** MARK, not ours — so a pull-only tenant was told it
had filed expense classifications and the wipe refused over data it never submitted.

Writing the paging test taught its own lesson: the first version backdated a row, which sorts FIRST
and is harmless, so it passed with and without the fix. It reproduces only when the row with the
LOWEST id sorts LAST by date.

**Review round 2** found four, one P1 — the round-1 fix landing short of its own goal. Excluding
`STATE_SYNC` alone still let `ExpenseImporter`'s `RequestDocs` / `RequestTransmittedDocs` marks
through, so a pull-only tenant was STILL mis-warned and blocked; it is now an **allow-list** of our
own actions, which also handles NULL-action rows that a deny-list drops through SQL three-valued
logic. And the round-1 scope fix only reached the relations THIS class eager-loads — the renderers
lazy-load `company`, `paymentMethod`, `bankAccount`, credit notes, delivery-note events and marks
themselves, so the panel PDFs still came out missing IBANs and related documents; wrapping the whole
build in `CompanyContext::actAs()` is the root fix and replaced the per-relation helper. Also: a
failed export left a readable, complete-looking archive at the operator's output path (now deleted,
and the temp dir is created BEFORE the zip so an early failure leaves nothing at all), and
`humanBytes()` int-divided a 1.5 GB archive down to «1 GB».

That cleanup test took **three** attempts to make honest: it passed with and without the fix twice —
first because a render failure is caught per document, then because deleting every temp file left
`close()` with nothing to write. It reproduces only when one PDF is already in the archive and the
NEXT write fails. Same trap as the round-1 paging test; a test that cannot fail is worse than none.

**Review round 3 came back with no P0/P1** — the gate closed there. Two of its five P2s were fixed
anyway because both silently lose data, the class of bug this whole change is about:
`ZipArchive::addFile` defaults to FL_OVERWRITE, so two documents whose sanitised names collide
collapsed into ONE entry while the counts and `index.csv` still claimed two; and `cleanUp()` replayed
a list of files it remembered writing, so any stray entry left the temp directory behind (the suite
was leaking one per failed export) — it now scans the directory, which cannot miss and made the
tracking list redundant. Also fixed: a docblock claiming a panel action that this same PR lists as
not built, and a test `catch (\Throwable)` that would have swallowed its own `fail()`. The
`expense_marks.mark_date` NULL (a non-fillable `'date'` key in `ExpenseClassificationSubmitter`) is a
real but cosmetic bug outside this change — `docs/BACKLOG.md`.

**Deliberately NOT done, recorded in `docs/BACKLOG.md`:** DB-level `restrictOnDelete` on the mark
tables and tenant archival/soft-delete. A DRY_RUN mark would make an ordinary draft undeletable, so
restrict needs a more precise rule than the FK can express, and archival is a feature rather than a
guard. Priority drops to P1: the silent path is closed and the remaining items are hardening.

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

**Status:** OPEN · **Priority:** P2 (was P0) · **Bucket:** B · **Research:** CONFIRMED 2026-08-30

> **Triage 2026-09-02 — downgraded on runtime evidence the source audit could not
> see.** `docs/archive/mydata-sandbox-myd2-retry-2026-07-07.md` proves the InvoSign
> channel **de-dups** an identical `(series, ΑΑ)` re-POST (both sends → MARK
> `400001965179246`) and that `invoice_status.php` is real-time. A blind retry
> therefore cannot create a second legal document on this provider, which is the
> harm the P0 was for. The finding below stays accurate as code description — the
> `Success`-without-MARK / malformed-200 path really is recorded as a rejection
> without a status lookup. **Restore P0 the day we point at a provider that does
> not de-dup (e.g. SBZ).**

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

**Status:** PARTIAL 2026-09-02 (print half DONE; artifact-archive half → BACKLOG) · **Priority:** P0 · **Bucket:** A (print) / B (archive) · **Research:** CONFIRMED 2026-08-30

> **Print half DONE (2026-09-02, PR for PROV-003).** The customer PDF now renders a
> «Εκδόθηκε μέσω παρόχου (ΥΠΑΗΕΣ)» evidence block on any invoice filed through a
> provider (a `PROVIDER_INSERT` MARK on file, still VALID/not-cancelled): provider
> commercial+legal name, site, AADE code, **ΥΠΑΗΕΣ licence no.**, MARK, **UID** and
> **authentication code**. Provider identity is immutable config
> (`einvoice.provider_identity` → `App\Support\EInvoice\ProviderIdentity`), keyed by
> the mark's `provider_key` — not hard-coded in Blade — so a second provider renders
> its own evidence with one config row. The document **UID is now persisted**
> (`mydata_marks.uid`; `GrProviderSubmitter` writes it) — it was parsed then dropped
> (closes PROV-009's UID slice). `InvoicePdfProviderEvidenceTest` covers provider vs
> direct vs cancelled.
>
> **Still OPEN → BACKLOG (bucket B, archive/hardening half):** (a) retrieve + privately
> archive the official provider PDF artifact (SHA-256, immutable, retry-only-download);
> (b) snapshot the licence-in-force **per document** so a future licence rotation
> doesn't rewrite historical printouts (today's single stable licence makes the config
> source correct); (c) the full invoice-card «compare» panel. None of these blocks the
> compliant printout that now ships.

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

**Status:** DONE (single-flight) · **Priority:** P0 · **Bucket:** D · **Research:** CONFIRMED 2026-08-30

> **Triage 2026-09-02 — the ledger is behind the code.** Both cited entry points
> now take a distributed lock and re-read under it: `GrProviderSubmitter::submit()`
> → `Cache::lock('mydata-submit:'.$id, 120)` (`97c23de`), `DeliveryNoteSubmitter::submit()`
> → `delivery-submit:` (`23b1fa4`) — the same key the direct path uses, so a tenant
> switching channel mid-flight still serialises on the invoice. What remains open is
> only the *second* half: serialising issue against concurrent document mutation
> (edit/cancel while a POST is in flight) → `docs/BACKLOG.md`.

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

**Status:** PARTIAL 2026-08-31 — **hygiene DONE** (public-https-only guard + no
credentialed redirects, enforced at transport/preflight/form); **approved-endpoint
allowlist + request-time DNS-rebinding pin OPEN** → `docs/BACKLOG.md`. · **Priority:** P1 ·
**Research:** CONFIRMED 2026-08-30

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

**Status:** OPEN · **Priority:** P2 (was P1) · **Bucket:** D

> **Triage 2026-09-02 — half stale, and the required change below is wrong.**
> `AadeInvoiceDocument::paymentMethodTypeFor()` already distinguishes the two cases:
> a method that IS chosen but carries no valid §8.12 mapping logs a warning and is
> surfaced by `MyDataConfigAudit` in preflight/go-live; only a **null** method falls
> to cash silently, which is not a misreport. «Require a payment method before
> filing» would block a live legal document over a payload-quality nit — worse than
> filing type 3. What is actually needed here is one configuration check that both
> tenants' payment methods carry a `mydata_payment_type`.

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

### OBS-001 — Cutover-day forensics are captured but not reachable over MCP

**Status:** DONE 2026-09-02 (PR #405) · **Priority:** P1 · **Bucket:** A · **Raised:** 2026-09-02

> **DONE (#405).** All five read-only tools shipped and are registered on
> `EkdosiMcpServer`: `invoice_filing`, `mydata_failures`, `stuck_documents`,
> `mydata_discrepancies`, `preflight` (a later review round tightened the real-vs-
> capped counts and the error-code extraction). The acceptance below is met: from a
> cold start, with only MCP, `invoice_filing` returns a document's local×myDATA state
> plus its full `mydata_marks` history and — on request — one attempt's raw
> request/response XML. The ONE thing deliberately left is the optional «cheap»
> per-success INFO log line (below, under «Also worth doing») — it was never part of
> the acceptance and is recorded in `docs/BACKLOG.md` as an OBS-001 tail.

> Not legally required, unlike the other five blockers. It is here because it is
> what decides whether a day-one problem costs minutes or a day.

**The question this answers:** when the first live document misbehaves on 1 Oct,
how long does it take to see *why*?

**What is already captured — no new logging is needed**

- `mydata_marks` keeps the **byte-exact request and response XML** of every
  attempt, and not only successes: `REJECTED`, `CANCEL_REJECTED`,
  `PROVIDER_REJECTED`, `PROVIDER_FAILED` and `PROVIDER_CANCEL_FAILED` rows are
  written forensically, carrying the payload actually sent (InvoSign's augmented
  `xml_arxeio`, not the pre-augment core) and the provider's raw reply.
- `mydata_marks` also holds `provider_key`, `authentication_code`,
  `cancellation_mark`, `delivery_state` and `invoice_url` per attempt.
- `invoices.mydata_pending_since` marks an in-doubt document; `Invoice::loggedAttributes()`
  puts **`mydata_state` and `mydata_mark` in the activity trail**, so state
  transitions already have a who/when.
- `MyDataSubmitter` and `GrProviderSubmitter` log every failure and every recovery
  decision with `invoice_id` + `invcode`.
- `delivery_note_events` holds the ΔΑ lifecycle history.

So the answer to «MARK; XML; logs; or do we need extra activity + verbose
logging?» is: **none of the above — the evidence is already stored.** The gap is
that from outside the panel it is unreachable. Today MCP exposes `app_health`,
`failed_jobs`, `log_tail` and `recent_activity`: infrastructure-level. Not one
tool can answer «why was ΤΠΥ6661 rejected?». Debugging a document currently means
someone opening the panel and clicking.

The one number that proves the gap: `app_health` reports **92 myDATA discrepancies
for myip** and offers no way to see a single one of them.

**Required change — five read-only tools, same `SuperAdminMcpTool` /
`AssistantMcpTool` pattern as the existing ones**

1. **`invoice_filing`** *(the important one)* — by `invcode` or id: local vs myDATA
   state, and the full `mydata_marks` history (action, MARK, cancellation MARK,
   provider, auth code, timestamps), with an opt-in flag to return one row's
   request/response XML in full. Turns «why did it fail» into one call.
2. **`mydata_failures`** — recent `*REJECTED` / `*FAILED` rows across tenants,
   newest first, with the AADE `[nnn]` / InvoSign `[88-nnn]` codes extracted from
   the response. Answers «what is broken right now» without knowing which document.
3. **`mydata_discrepancies`** — the `SalesReconciler` buckets (matched /
   stateMismatch / contentMismatch / contentIncomplete / missingAtAade /
   missingLocally / duplicateLocal) as rows, not a count. Makes the 92 above
   actionable.
4. **`stuck_documents`** — `mydata_pending_since` set (in-doubt), numbered-but-unfiled
   drafts, provider attempts with no MARK. The «what is silently stuck» query.
5. **`preflight`** — the existing `mydata:preflight` / `ekdosi:go-live-check`
   output over MCP, so readiness is checkable without a shell.

**Also worth doing (cheap):** the submitters log failures but not successes. One
structured INFO line per filing outcome (channel, type, series/ΑΑ, MARK,
duration) makes `log_tail --contains=<invcode>` work even when the DB write is
the thing that failed.

**Explicitly not needed:** extra activity-log coverage, verbose/debug logging, or a
second audit store. All three would add noise to a trail that already contains the
answer.

**Acceptance**

From a cold start, with only MCP: name a failing document, get its rejection code
and the exact XML that produced it, without opening the panel.

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

**Resolution (triage 2026-09-02): the audit's own advice, enforced in code.** The
verdict above said «do not rely on the UI apply path» — so it is now **OFF by
default** (`ekdosi.updates.allow_in_app_apply`, env `EKDOSI_UPDATE_IN_APP_APPLY`),
and the supported upgrade is `deploy/update.sh <tag>` on the host, with
`deploy/rollback.sh` behind it (`docs/updates-runbook.md`).

The **CHECK stays on** — it is read-only, genuinely useful, and «Υγεία συστήματος»
now prints the exact command to run (`deploy/update.sh vX.Y.Z`) instead of leaving
the operator hunting for a button that is deliberately not there.

`UpdateRun::inAppApplyEnabled()` is the ONE definition. It hides the «Εγκατάσταση
ενημέρωσης» button and the «Επαναφορά» action, but the guarantee is not a hidden
button: **`ekdosi:self-update` refuses any queued run** — update or rollback,
scheduler or `--run=` — and FAILS the row with the command to use instead, rather
than skipping it (the scheduler fires every minute while something is queued, so a
silent skip would spin forever and never explain itself). Covered by
`tests/Feature/Updates/InAppApplyDisarmedTest.php`.

**This disarms the machinery, it does not delete it.** UPD-001…015 below stay
**accurate** — they describe real defects in code that still exists and that a
deploy can re-arm with one env var. Their PRIORITY drops to P2 because nothing
reaches them in the shipped configuration: they are a **precondition for turning
the flag back on**, not a cutover blocker. Fix UPD-001…004 before anyone sets
`EKDOSI_UPDATE_IN_APP_APPLY=true`.

### UPD-001 — PHP update and rollback do not quiesce the queue

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P0 → P2

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

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P0 → P2

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

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P0 → P2

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

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P0 → P2

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

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

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

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

A power loss, killed cron process or timeout after status becomes `running`
leaves the row active forever. `hasActive()` then blocks new update and rollback
actions, while the scheduler only selects `queued` rows.

Add a heartbeat/lease to `UpdateRun`, detect stale runs, inspect the maintenance
and deployed-build state, and offer an explicit “resume / rollback / mark failed”
recovery flow. Never automatically retry a migration/restore without knowing the
last completed durable phase.

### UPD-007 — Critical post-update health can still be green in history

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

The PHP strategy runs `ops:health` with `allowFailure=true`; Shield generation,
role sync and opcache are also advisory. The shell script logs a critical health
exit but still returns success. The wrapper therefore writes `status=succeeded`
even when the worker is dead or health is critical.

Add `succeeded_with_warnings`/verification state or fail the run on critical
health. Do not label the update complete until the new build, migrations, queue
heartbeat and required permissions are verified.

### UPD-008 — In-app snapshots bypass the retention policy

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

The PHP strategy creates `update-*.sql.gz` and rollback creates
`rollback-*.sql.gz`, while [`DbSnapshot::prune()`](app/Console/Commands/DbSnapshot.php)
only prunes `ekdosi-*.sql.gz`. Passing `--keep=10` therefore does not prune
either in-app naming scheme. Full DB snapshots containing business data and
secrets accumulate indefinitely.

Implement a common snapshot registry/retention policy that preserves snapshots
still referenced by rollbackable runs and securely removes expired unreferenced
update/rollback snapshots.

### UPD-009 — Preflight exists in the design, not in the UI

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

The action only checks that update checking is enabled, a repo exists, a newer
version was cached and no active row exists. Failures such as no cron, dirty tree,
missing Composer/client binaries, insufficient disk or unwritable vendor occur
after the operator has already queued the run.

Implement the documented dry-run preview: exact target commit, commits/migrations,
strategy, current/target versions, dependency tools, writable paths, disk,
snapshot probe, worker-drain capability and scheduler freshness.

### UPD-010 — Script strategy invokes a Bash script with `sh`

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

[`SelfUpdate::runScript()`](app/Console/Commands/SelfUpdate.php) executes
`['sh', deploy/update.sh, target]`, but the script uses Bash-only syntax
(`[[ ... ]]`, `set -o pipefail`, functions/conditionals) and declares a Bash
shebang. This breaks wherever `/bin/sh` is not Bash.

Invoke the executable directly after validating permissions, or explicitly call
a discovered `bash` binary. Add a portability test.

### UPD-011 — Tests do not execute the updater lifecycle

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

[`SelfUpdateCommandTest`](tests/Feature/Updates/SelfUpdateCommandTest.php) covers
only “nothing queued” and “row not queued”. No test executes checkout, snapshot,
Composer, migration, maintenance recovery, opcache, health or DB rollback.
Shell scripts also have no automated behavior tests.

Build a disposable test harness with a temporary Git repository and MariaDB,
replace external binaries with controlled fixtures, and inject failure at every
durable phase. At minimum prove happy update, pre-check abort, snapshot abort,
Composer failure, migration failure, crash recovery and full rollback.

### UPD-012 — A read-only setting implicitly arms code deployment

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P1 → P2

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

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P2 → P2

The temporary askpass file is removed and output is redacted correctly, but
`makeAskpass()` calls `putenv('EKDOSI_GIT_TOKEN=...')` and never unsets it.
Later Composer and Artisan subprocesses may inherit the token.

Pass the token only in the Git process environment and unset it in a `finally`
block. Add a test proving later subprocess environments do not contain it.

### UPD-014 — A formal GitHub Release can hide newer tag-only releases

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P2 → P2

The checker uses `releases/latest` whenever any Release exists and only falls
back to tags on 404. The documented release flow pushes tags but does not create
GitHub Releases. The repository currently has no Releases, so fallback works
today; if one Release is created and later versions remain tag-only, the checker
can stay pinned to the older Release.

Fetch both signals and choose the highest valid stable SemVer, or standardize the
release process so every production tag always creates a GitHub Release.

### UPD-015 — Single-flight is not atomic at the command boundary

**Status:** DISARMED 2026-09-02 (in-app apply OFF by default; precondition for re-arming) · **Priority:** P2 → P2

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
| 2026-08-31 | **PROV-017 DONE** *(superseded 2026-09-01 → PARTIAL, see below)* — provider base URL constrained to public https (guard at transport/preflight/form) + no credentialed redirects; TOCTOU/endpoint-profile deferred → BACKLOG | `CHANGELOG.md` [Unreleased] → Security |
| 2026-08-31 | **MYD-003 DONE** — movement-only 9.x excluded from the monetary invoice picker + build guard; Δελτία Αποστολής stay in the delivery flow | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **MYD-012 DONE** — unsupported ΔΑ types 9.1/9.2 hidden from the delivery picker + submitter guard (only 9.3 fileable); full model → BACKLOG | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-08-31 | **MYD-002 DONE** — misleading «ΤΔΑ» dropped from the invoice-type seed (fresh installs); real combined 1.1+isDeliveryNote → BACKLOG | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-003 extended** (review follow-up) — shared `InvoiceType::scopeMonetary()` now excludes 9.x from quote→invoice/service + renewal selectors too; `InvoiceNumberer::allocate()` backstop rejects 9.x for every creator | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-012 hardened** (review follow-up) — denylist → allowlist `Codes::SUPPORTED_DELIVERY_TYPES=['9.3']` (future 9.4 now blocked); `defaultDeliveryTypeId()` checks the ΔΑΠ shortcut resolves to a supported type | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-002 modal wording** (review follow-up) — «Εισαγωγή τυπικών» modal no longer lists the removed «ΤΔΑ» type; existing tenants' ΤΔΑ left as-is per operator decision (no migration) | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-020 doc sweep** (review follow-up) — FEATURES.md/BACKLOG.md catalogue text no longer says «χαρτόσημο»/«§8.5» for fees (→ Ψηφιακό Τέλος Συναλλαγής §8.6 / Τέλη §8.7) | `CHANGELOG.md` [Unreleased] → Changed |
| 2026-09-01 | **MYD-015 8.6 fixture** (review follow-up) — aggregator test proves a zero-value 8.6 order slip is counted but adds 0 to the myDATA revenue picture | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **PROV-017 status → PARTIAL** (review follow-up) — hygiene (public-https-only + no credentialed redirects) DONE; approved-host allowlist + DNS-rebinding pin remain OPEN in BACKLOG (no flat-DONE) | `docs/BACKLOG.md` (§Provider endpoint hardening) |
| 2026-09-01 | **MYD-003 second pass** (strict review) — `->monetary()` now on the remaining selectors: 3× WHMCS defaults + 2× third-party split + credit-note picker; WHMCS default/split queries extracted to shared helpers with 9.x-exclusion tests | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-008 DONE** — correlated credit (5.1) resolves the original MARK from INSERT **and** PROVIDER_INSERT (was INSERT-only), so provider-issued originals stay correctable; same-tenant scoped; rejected attempts refused | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-008 hardening** (PR #388 review) — the `MyDataMark` correlation query is now also `company_id`-scoped, so an inconsistent audit row from another tenant pointing at the same invoice_id can't be used as the MARK | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017 DONE** — live reconciliation compares content (gross/type/series-ΑΑ/date/ΑΦΜ), not just MARK+state; new `contentMismatch` bucket (shared comparator, both consoles) ends the false-green | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-014 DONE** — `SyncExpenseStateFromAade` + console action apply a supplier cancellation onto an existing expense (VALID→CANCELLED, audited, no re-import/dup); books already exclude CANCELLED | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017 review** (PR #389) — incomplete ≠ conflict: new warning bucket `contentIncomplete` (AADE has a field the local record lacks) alongside danger `contentMismatch`; comparator returns `ContentComparison` (conflicts+incompletes), both count + render (consoles + CLI); fixed retail test (`array_merge`, not `??`) | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-014 review** (PR #389) — integrity: reconciler keeps the real cancellation MARK (`invoiceMark ⇒ cancellationMark`, inline + standalone); `SyncExpenseStateFromAade` refuses CANCELLED without it; console `syncStates()` re-reconciles fresh at click time (no trust in the ≤12h cache) + re-verifies tenant/expense_id/MARK before mutating | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017/014 code-review round** (PR #389) — (a) `snapshotFrom()` relation fallback so legacy null-cache invoices match instead of permanent `contentIncomplete`/exit-2; (b) `normDate()` null-on-blank so an empty AADE date can't fabricate a conflict; (c) `syncStates()` per-row try/catch so one evidence-less cancellation doesn't abort the batch; net/VAT-split compare noted → BACKLOG | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017 AADE-side fail-open closed** (PR #389 review 2) — a missing/unparseable MANDATORY AADE field (gross/type/series/ΑΑ/date) is now `contentIncomplete`, not `matched`; counterpart ΑΦΜ stays optional for retail 11.x. MYD-017 → DONE | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017 net/VAT split + gross basis** (PR #389 review 3) — compare `<totalNetValue>` (catches a same-gross/different-VAT-category doc); money now compared against a new `FiledInvoiceTotals` (per-VAT-rate roll-up + [208] adjustment — what the submitter actually files) instead of the `net_total`/`gross_total` columns; unreconstructable (no lines / category-less legacy withholding) → unverified, not a false conflict; integer-cent tolerance; same basis fixes the per-invoice «Σύγκριση με ΑΑΔΕ» too | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017 fail-closed + cleanup** (PR #389 review 4) — `FiledInvoiceTotals` returns unverified (not 0,00 / not a thrown exception) on null-amount legacy lines and out-of-range header discounts; blank/non-numeric AADE totals read RAW (`->get()`) → null instead of firebed's typed getter throwing; money compare unified in `Support\Money::differsByCent` (fixes the per-invoice «Σύγκριση με ΑΑΔΕ» float bug), ΑΦΜ in `Support\Afm` (comparator + 4 WHMCS sites); `AadeDocSummary::withCancellation()` de-dups the fold rebuild | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-017 self-closing containers + AFM finish** (PR #389 review 5) — the reconcilers read the invoiceSummary/invoiceHeader/counterpart/issuer CONTAINERS raw (`->get()` + instanceof) too, so a self-closing `<invoiceSummary/>` no longer TypeErrors out of the fetch; `Support\Afm` now the single AFM source (7 sites incl. PendingWhmcsInvoice/WhmcsInboxTable) | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-01 | **MYD-011 DONE** — delivery recipient country frozen on the note (`recipient_country`), populated from customer/supplier/manual; external recipient without a resolvable country is REFUSED (GR default reserved for ενδοδιακίνηση); one shared `Support\IsoCountry` normaliser across invoice + delivery (adds the missing EL→GR / UK→GB to delivery) | `CHANGELOG.md` [Unreleased] → Fixed |
| 2026-09-02 | **Go-live triage** — every open item re-bucketed A/B/C/D against the 2026-10-01 cutover, the two tenants' actual document mix (840+15 docs/yr, ΤΠΥ/ΤΙΜ/ΠΙΣ only) and a live-verified deploy; 6 blockers, 14 out-of-scope | `known-issues.md` §Go-live triage |
| 2026-09-02 | **PROV-001 P0 → P2** — InvoSign de-dups + real-time status (sandbox 2026-07-07); a duplicate legal document is not reachable on this provider. Re-raise on a non-de-duping provider | `docs/archive/mydata-sandbox-myd2-retry-2026-07-07.md` |
| 2026-09-02 | **PROV-014 → DONE** — the ledger was behind the code: single-flight locks landed in `97c23de` (invoices) / `23b1fa4` (delivery). Mutation-during-issue remains → BACKLOG | `known-issues.md` §PROV-014 |
| 2026-09-02 | **SETUP-003 P1 → P2** — an unmapped method already warns + surfaces in preflight; hard-blocking a filing over it is worse than type 3 | `known-issues.md` §SETUP-003 |
| 2026-09-02 | **Operator corrections** — PROV-010 **DONE** (contract/declaration/acceptance in place); delivery notes + retail are in scope (issued in legacy, digital delivery becomes mandatory) so the 9.x family + PROV-006 move **C→B/A**, not out; MYD-007's 12% confirmed **intra-community**; dry-run is in progress on dev/test | `known-issues.md` §Operator corrections |
| 2026-09-02 | **OBS-001 raised (P1, bucket A)** — filing forensics are already captured (byte-exact XML + rejection rows + activity trail) but unreachable over MCP; 5 read-only tools proposed. No extra logging needed | `known-issues.md` §OBS-001 |
