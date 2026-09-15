# Backlog / Roadmap — what's left + ideas (SINGLE SOURCE)

The **one** place for what is **deliberately not built yet** + new ideas/thoughts.
What IS built lives in **`FEATURES.md`** (the catalogue) and **`CHANGELOG.md`** (per
change) — those two are the cross-check for «τι είναι χτισμένο».

**Lifecycle of a shipped item (keep it tidy — μην χανόμαστε):**
**α)** add/confirm it in `FEATURES.md` → **β)** record it in `CHANGELOG.md` → **γ)**
delete it from here → **δ)** if it was a «known latent» note in `CLAUDE.md`, unmark it.
(Don't backfill old `CHANGELOG` versions for things shipped long ago — just ensure
`FEATURES.md` has them and drop them from here.)

Don't re-pick the **«Done recently»** or **«looks like a gap but isn't»** lists below.

> **`known-issues.md` was folded into this file (2026-09-04)** and deleted. It was a
> 283 KB readiness ledger that was **~65% already-DONE/DISARMED**; its Go-live triage
> and its ~16 genuinely-open entries live here now (see «Cutover gate» + «Provider/
> myDATA — folded from known-issues» + «Parked» below). The DONE detail sections stay
> in git history. The BUILT design-docs `leads-mini-crm.md`, `cmr-international-delivery.md`
> and `delivery-provider-split-brain.md` moved to `docs/archive/`.

---

## 🎯 Master priority index (start here — 2026-09-04)

The one ordered view of what's left. Each tier links to the detailed section below.
The rule that governs the order (learned from the ten-round P2 PRs): **a finding's
priority is not a property of the finding — it is the finding × this business × this
date.** Cutover (1 Oct provider obligation) sorts everything.

> *The **Cutover gate** (ex-TIER 0) is **CLOSED** — all bucket-A code shipped, MYD-006/007
> decided, and a live prod check (2026-09-05) found **0 real myDATA discrepancies** (the
> earlier «92/2» came from a stale devbox backup, never real). The daily dry-run on the VM
> is the operators' routine, not a backlog task. Kept as a closed record under «Cutover gate».*

> **Sequencing (owner, 2026-09-05):** the **delivery-note family is HELD until the myDATA
> API v2.0.2** ships (DEP-001). ✅ **UNBLOCKED 2026-09-12** — v2.0.2 shipped and `firebed/aade-mydata`
> **5.12.0** (bumped from 5.10.4) implements it (backwards-compatible; suite green). The HOLD is
> lifted — the family can start. **MYD-011 (country→ISO) ✅ DONE**
> and **PROV-005 ✅ CLOSED (won't-do)** — no actionable-now item remains in TIER 1. TIER 2 is
> **not urgent** («δεν καιγόμαστε»). The **AI «Βοηθός» Phase 2c** is **✅ COMPLETE** (α read
> tools · ε ai_usage · β record_payment · ζ knowledge_search).

**TIER 1 — Real in-scope code work, next deadline (delivery-note family + provider):**
1. **Delivery-note family** — ✅ **UNBLOCKED 2026-09-12** (was HELD until myDATA API v2.0.2, DEP-001;
   `firebed/aade-mydata` 5.12.0 now ships v2.0.2). Before the ψηφιακή-διακίνηση deadline, NOT 1 Oct:
   MYD-023 strict-refusal + already-cancelled adoption on ΔΑ/provider paths (**P1**, ties to PROV-015)
   · MYD-019 · MYD-026 · PROV-002 · STOCK-001 follow-ups · unblock 9.1/9.2 + combined ΤΔΑ. One block
   with a 9.3 sandbox rehearsal. **Wired in 5.12.0:** `ConfirmDeliveryReturn` + `deliveryReturnMark`
   (MYD-026/PROV-002 durable attempt-record — the exact DEP-001 gate) ✅ **Slice 1 DONE** (direct-myDATA
   path: `confirmReturn()` + `return_mark` cache + `CONFIRM_RETURN` audit row + UI action; **provider
   path PROV-002 still TODO**). `DeliveryStatus::IN_TRANSIT_RETURN` + the `CONFIRM_RETURN`/
   `REGISTER_TRANSFER_RETURN` event types ✅ **Slice 2 DONE** (own `in_transit_return` state +
   refresh visibility + event labels/summaries; carrier-reported, no submit action). **Still
   available in 5.12.0 to wire:** `RequestDeliveryNoteStatus::handleUsingQrUrl()`,
   `TransportDetails::packingsDeclaration`; PLUS the **new Receiving Note flow** (Δελτίο Ποσοτικής
   Παραλαβής, types 10.1/10.2 — `CancelReceivingNote`, `ReceivingNotePurpose`) if in scope;
   `supportsDeliveryNote()` now also allows 1.4/3.1/3.2/11.5.
   **9.3 lifecycle sandbox rehearsal — RAN 2026-09-13** (`myip`, AADE test env; see
   `docs/delivery-sandbox-rehearsal.md` §Findings). Confirmed from the ISSUER's own creds:
   RegisterTransfer ✓, refreshStatus ✓, cancel-from-`registered` ✓; cancel-from-`in_transit`
   ✗ [801]; ConfirmDeliveryReturn from `in_transit` ✗ [828] → **`in_transit` pruned** from
   `CONFIRM_RETURN_FROM_STATES`. **DGM two-party sandbox validation — ✅ DONE 2026-09-13**
   (myip⇄nexon, `docs/delivery-two-party-sandbox.md` §Findings): confirmReturn CONFIRMED at AADE
   from `rejected`, `failed`, AND DeliveredByCarrier(PARTIAL) (posts a deliveryReturnMark). Fixed
   the **P2-2** bug — `DELIVERED_BY_CARRIER` was collapsed to `'delivered'` (∉ CONFIRM_RETURN set),
   now split by the ConfirmOutcome lifecycleHistory detail (PARTIAL→`partial`, FULL→`delivered`).
   Empirical role rules recorded: CARRIER = whoever CALLS RegisterTransfer (not the declared
   `carrierVatNumber`); recipient NONE = [817]; PARTIAL needs [814] `deliveredPackaging`. **Remaining
   punch-list (P2):** (a) ✅ **DONE** — issuer-side `confirmDelivery()` + UI «Δήλωση παράδοσης» REMOVED
   (was a [833]/[817]/[814] dead-end; outcome is recipient/carrier-only, only observed via refresh);
   (b) ✅ **DONE** — `delivery:test-lifecycle --return` (confirmReturn from `in_transit` → [828]) removed,
   command is now the issuer flow register→status(+cancel). _(Cleanup left: the `delivery_notes.outcome_mark`
   + `reject_mark` cache columns are now write-only/vestigial — the outcome/rejection marks are the
   recipient's/carrier's and surface in `delivery_note_events`; their blank infolist fields were removed.
   Either populate them from the synced ConfirmOutcome/Rejection events on refresh, or drop the columns in a
   later migration — P2, non-destructive to leave.)_ (c) a receiving-ekdosi
   «Εισερχόμενα Διακίνησης» is net-new (RequestDocs discovers 9.3s to the counterpart by MARK — no qrUrl;
   reject-by-MARK works, confirm is qrUrl-only). **Slice 4a ✅ SHIPPED** (PR #540 — `inbound_delivery_notes`
   staging table + `InboundDeliveryFetcher` reusing the RequestDocs feed, movement-only filter,
   `delivery:fetch-inbound` scheduler-gated OFF; design `docs/delivery-inbound-design.md`). **4b** = the inbox
   Resource + Reject/Refresh/Acknowledge (desk-actionable). **4c** = the qrUrl/scan-gated Confirm-outcome —
   **DEFERRED** (we are almost always the issuer; needs a physical-QR-scan UX for a flow no tenant hits today).
   **Slice-4a review P2s (deferred, `docs/delivery-inbound-design.md`):**
   - **P2-1** — the fetcher folds only `invoicesDoc`, not `cancelledInvoicesDoc` (unlike `ExpenseReconciler`).
     A DGM *movement* cancel is a `DeliveryStatus::CANCELLED` transition the re-poll picks up; only a full
     invoice-level cancellation of a received ΤΔΑ could leave `aade_delivery_status` stale until the 4b
     per-row Refresh runs. Mitigated by 4b Refresh; fold the cancelled list if it proves to bite.
   - **P2-4** — combined-ΤΔΑ discovery hooks on `otherDeliveryNoteHeader`/`invoiceDeliveryStatus` surviving in
     the *counterpart* feed (the feed is known to strip `qrCodeUrl`). Extend the §2 sandbox `--raw` residual
     check to also grep `<otherDeliveryNoteHeader>`/`<invoiceDeliveryStatus>` before relying on the filter.
   - **P2-5** (low-confidence) — a `DeliveryLifecycle` with an unset `deliveryEvents` key would `TypeError` in
     `parseLifecycle`; unreachable in practice (firebed defaults it to `[]`; a self-closing element lands as a
     scalar caught by `is_iterable`). Add a defensive guard only if ever observed. (d) `deliveryStateFromAade` defaults a
   DeliveredByCarrier with a MISSING ConfirmOutcome detail to `'delivered'` (conscious — a carrier FULL
   also reports DeliveredByCarrier, so `'partial'` would mislabel the common case) — revisit only if a
   real truncated-history case dead-ends a legitimate return; the authoritative outcome stays in
   `delivery_marks`/lifecycleHistory regardless.
2. **MYD-011 country→ISO normalization** — ✅ **DONE** (Option B): νέα καθαρή στήλη
   `country_code` σε πελάτες/προμηθευτές + ISO picker + `IsoCountry::syncCountryCode`
   (save-hook) + `ekdosi:backfill-country-codes` + ETL alignment + `suppliers.country`
   nullable/no-default (τέλος το silent-GR freeze). Οι resolvers έκδοσης διαβάζουν
   `isoCountryCode()` (zero-regression fallback). Βλ. «Done recently».
3. **PROV-005** — ✅ **CLOSED (won't-do, owner 2026-09-05).** Ο authenticated credential/quota
   probe εγκαταλείφθηκε συνειδητά: κάθε InvoSign κλήση (και δοκιμαστικό παραστατικό) **χρεώνεται
   credits** και δεν υπάρχει non-issuing status endpoint, οπότε δεν έχει νόημα. Κρατάμε το **δωρεάν
   reachability ping** (unauthenticated GET)· τα διαπιστευτήρια/quota αποδεικνύονται στην πρώτη
   πραγματική αποστολή. Τα success messages λένε πλέον ρητά «έλεγχος μόνο διαθεσιμότητας».
4. **WHMCS bridge Phase 2 — outbound payment sync** (money-write, opt-in, design-first).

**TIER 2 — High-value net-new features / migration tooling:**
5. **Generic CSV importer** (products/customers) — «biggest win for catalog/customer
   migration»; column-map + dry-run + tenant-scope.
6. **Cashflow / recurring-expenses** epic (accountant-gated; anti-double-count vs myDATA).
7. **Dunning ladder** (escalating 3/7/15/30-day reminders on the existing auto-email).
8. **Bank-statement import → payment match** (CSV/MT940 → proposed `Payment` rows).

**TIER 3 — Strategic epic «Αντικατάσταση WHMCS» (`PLAN.md`, largely greenfield):**
9. **Πυλώνας A — Domains** (A0→A5; design-only today, first pillar).
10. **Πυλώνας B — Payment gateways** (online Stripe/PayPal/Eurobank/manual, WHMCS-style admin, modular
    contract/registry — **design+threat-model: `docs/payment-gateways-design.md`**; office rails card-POS/IRIS
    → `payment-connectors.md`).
11. **Πυλώνας C — Provisioning modules** (real cPanel/DA/… on the existing seam).
12. **Πυλώνας D — Customer portal** (foundation ✅ shipped; transactional surfaces per-pillar).
13. **Πυλώνας E — Support/Ticket system** (NEW, eval-first — spike `laravel-service-desk` vs build).
14. **Menu / IA architecture** (NEW, epic-wide + already pressing — Clusters-per-domain, Settings
    Cluster, panel-split only when the audience differs). Both detailed under the epic section below.

**TIER 4 — Blocked-on-external (not actionable now — keep parked, don't re-pick):**
- **GR Πάροχος live** + **PEPPOL Phase 2** — need real provider creds + sandbox.
- **PROV-003 archive half** — no InvoSign download endpoint. **PROV-011** — versioned
  InvoSign API contract (empirically resolved already).

**TIER 5 — Out of current tenants' scope (re-raise if scope changes):** exotic VAT
(island/ν.5057), multi-branch (MYD-010), B2G/POS (PROV-012), offline/Transmission
Failure (PROV-008), fresh-install onboarding (SETUP-001/002, OPS-001, TEST-001),
PROV-004/013/015/016. → see «Parked» below.

**TIER 6 — Tech-debt / P2 pile** (DB-state-correct; cosmetic/perf/edge). Mostly leave;
knock off the genuine one-liners opportunistically → see «Tech debt / latent». *(The
three previously-listed «real small bugs» were re-verified 2026-09-06 and are all
already fixed: `resolveWhmcsCustomField` has its `is_array` guard, `AgedReceivables`
+ `LedgerBookExporter` pass `escape: ''`, and the expense submitter writes the fillable
`mark_date`, not a bare `'date'`. Remaining CSV item = the shared `Csv::stream()` DRY,
below.)*

**TIER 7 — Ideas / low-commitment** (multi-currency, shared Contacts CRM, setup
profiles per industry, AI «Βοηθός» Phase 2c). Reference only.

---

## 🔐 Secrets hygiene — RESOLVED (2026-09-08)

Historical sensitive files were removed from the tree, the affected credentials were rotated,
and the git history was rewritten to a single clean root (old commits, tags and branches dropped).
No secret values remain in the tree or in reachable history. Closed — no further action.

---

## 🚀 Cutover / go-live gate — bucket A: ✅ CLOSED (closed record)

**Cutover:** ekdosi replaces the legacy C++Builder app for real invoicing; the
ΥΠΑΗΕΣ/provider obligation lands **2026-10-01**. Delivery notes (9.x) follow on their
own ψηφιακή-διακίνηση deadline — that family is TIER 1, **not** gated on 1 Oct.

**All bucket-A code is DONE** (PROV-010, OBS-001, PROV-003-print #406, MYD-004 0% #410,
MYD-006 #413, MYD-007 code #410, PROV-006 retail-via-provider sandbox-verified 2026-09-03),
and the two residual decisions are made:
- **MYD-007 — decided.** The intra-community 0% was essentially one large invoice to
  Estonia; per-line §8.3 field + `VatExemptionGuidance` ship (GR→EE service = code 4).
  Preflight still FLAGS any reason-less/wrong 0% rows for review (no auto-guess).
- **MYD-006 — chosen.** `business_activity_type` selected per tenant (go-live gate forces
  it); income class also configurable per product-category. Per-product override = UI follow-up.
- **Discrepancies — none real.** Live prod check **2026-09-05**: **myip 0**. The earlier
  «92/2» came from a **stale devbox backup** measured while prod moved ahead (myip filing
  again, last MARK 2026-09-04; nexon's 2 were the same stale artifact) — never a real
  backlog. Watch with `app_health` / `mydata_discrepancies` going forward.
- **Cutover dry-run — ongoing, NOT a task.** Two operators run every day-one document type
  (ΤΠΥ, ΤΙΜ, ΠΙΣ + αποδείξεις λιανικής 11.x) plus edge cases on the dev/test VM **daily** —
  that IS the rehearsal, and it's enough. **PROV-007** rides along (verify InvoSign discount
  semantics cent-for-cent on one discounted invoice); no separate project.

*Runtime facts that de-risk the provider path (measured, sandbox 2026-07-07): the
InvoSign channel **de-duplicates** a blind re-POST of the same (series, ΑΑ), and
`invoice_status.php` answers in real time — so a blind retry cannot create a second
legal document on this provider. A **provider-filed 2.1/11.x cannot be cancelled at
all** (AADE `[249]`, InvoSign `[283]`); reversal is a 5.1 credit, gated to 9.3 only.*

---

## 🅱️ Provider / myDATA — folded from known-issues (after-cutover, in-scope)

Open entries from the old ledger not already tracked in their own sections below.
The dupes (MYD-023, MYD-024, PROV-001/003/005/009/017/018, delivery family) live in
the myDATA/Provider sections lower down — not repeated here.

- **PROV-011 (P2/VERIFY)** — ask InvoSign for a **versioned/written** API contract; the
  cancellation-endpoint ambiguity is already resolved empirically (`[283]`). *Blocked on vendor.*
- **POS-1 (P2/VERIFY + FEATURE)** — myDATA §5.2 (v2.0.2): a POS payment (`paymentMethods/type=7`)
  needs a **POS payment signature** in the invoice XML — `ProvidersSignature` (via a provider
  channel), or `ECRToken` (from an ERP) — plus the POS `tid` + a `transactionId`. ekdosi emits NONE
  (`AadeInvoiceDocument` sends bare `type`+`amount`). **Confirmed** the provider path (InvoSign)
  rejects a bare type-7 (`88-007 — η υπογραφή δεν είναι έγκυρη`, prod, myip ΑΦΜ 800561849, 2026-09-15).
  Shipped guard: `MyDataConfigAudit` flags an **in-use** type-7 method (ERROR for `gr-provider`, WARN
  for `gr-mydata`). Open items: **(a) VERIFY** whether **direct** `gr-mydata` `SendInvoices` also
  rejects a bare type-7 (the §5.2 fields are `Όχι`/optional and the "≥1 POS object" rule is scoped to
  the separate `SendPaymentsMethod`, not `SendInvoices`) → if it does, promote the direct WARN to
  ERROR; if not, the WARN stays advisory. **(b)** the guard's "in use" subquery excludes only
  soft-deleted invoices — it still counts drafts/cancelled/credit-notes; tighten to "will actually
  be filed" if the WARN/ERROR proves noisy. **(c) FEATURE** — real POS interconnection (ν.5073/2023):
  capture the acquirer's `ProvidersSignature` + `tid` + `transactionId` (natural path: **Cardlink/
  Eurobank vPOS** return → pass through `InvoSignDocument`/`AadeInvoiceDocument`) so type-7 can file.
  Until (c), operators map card/vPOS/PayPal to §8.12 **1** (επαγγ. λογαριασμός ημεδαπής), 3, 6 or 8.
- **DEP-001 (WATCH → ✅ SATISFIED 2026-09-12 → ✅ WIRED 2026-09-12)** — AADE **v2.0.2**
  delivery-lifecycle spec gated a durable attempt-record for MYD-026/PROV-002 (only a
  protocol-agnostic cache-lock was safe until then). v2.0.2 shipped, `firebed/aade-mydata` 5.12.0
  exposes it, and **Slice 1 wires it**: `DeliveryLifecycleService::confirmReturn()` drives
  `ConfirmDeliveryReturn` → `Response::getDeliveryReturnMark()` → the guarded cache column
  `delivery_notes.return_mark` + a `CONFIRM_RETURN` audit row (UI action «Δήλωση επιστροφής»,
  `in_transit → returned`). The direct-myDATA path uses the real durable mark now; the **provider
  path (PROV-002)** still needs its own wiring in a later slice.
- **MYD-005 (P2)** — ordinary invoice XML omits the optional myDATA `measurementUnit`
  (data-fidelity enhancement; goods-tenant-conditional).
- **ΤΔΑ measurementUnit from free-text (P2)** — a combined ΤΔΑ MUST emit a per-line
  `measurementUnit` ([230]); `AadeInvoiceDocument::measurementUnitCode()` best-effort maps
  the line's free-text `metric_unit` (τεμ/κιλά/λίτρα/μέτρα/τ.μ./κ.μ.) → §8.13 code with a
  **fallback to 1 (Τεμάχια)**. A goods sold by an unmapped unit files as Τεμάχια. Proper fix:
  a §8.13 unit picker on the ΤΔΑ invoice line (like the delivery-note line), or store a code
  on `invoice_lines`. Sandbox-validated 2026-09-14 (both direct + provider paths).
- **ΤΔΑ issuer address placeholder (P2)** — for a combined ΤΔΑ, `AadeInvoiceDocument` files the
  issuer name+seat address; if the tenant company row has a blank address it falls back to
  'Έδρα'/'00000'/'Unknown' (mirrors `DeliveryNoteSubmitter::buildTenantAddress`), whereas the
  counterpart branch (MYD-6) hard-refuses such placeholders. Inconsistent on the same legal
  document, but unreachable in practice (an invoicing tenant always has a configured seat).
  Align (hard-fail issuer too) if a real blank-seat tenant appears.
- **SETUP-004 (P2)** — Estonian (EE) tenant skips even non-AADE neutral lookups.
- **SETUP-003 (P2, was «stale»)** — only a *null* payment method defaults to cash
  silently (an unmapped-but-chosen one already warns + surfaces in preflight). Remaining
  = one config check that each tenant's methods are mapped. The originally-required
  hard-block was judged wrong.
- **OPS-003 (P2)** — shared-hosting cron/worker recipe + `ops:cron` done; the install
  completion-page deep-link is a UI follow-up.
- **TEST-001 (P2)** — no full web-installer success-path test (fresh-install quality).

---

## 🅲 Parked — out of current tenants' scope (re-raise if scope changes)

Genuinely correct findings that **cannot occur for these two single-establishment,
domestic-services tenants**. Not deleted — parked with the trigger that reactivates them.

- **Exotic VAT** — 3% (code 9), island 4% (code 6) vs ν.5057 4% (code 10), goods-export
  exemptions → the non-0% half of MYD-004 and the goods rows of MYD-007. *No island/ν.5057
  activity.* (The intra-community 0% case IS in scope — MYD-007, TIER 0.) **The island rates
  (17/9/4 = codes 4/5/6) are NO LONGER seeded** (2026-09-06, mainland tenants — they were
  noise in every VAT picker); the codes/rates stay in `Codes::VAT_CATEGORY_RATES`, so an
  island tenant re-adds the category by hand (or restores the `[1,2,3]`→`[1,2,3,4,5,6]` loop
  in `vatCategorySeedRows`).
- **Multi-branch** — **MYD-010** (WATCH). Refers to the **ISSUER** branch: both tenants
  single-establishment, so `issuer.branch=0` is truth. The **COUNTERPART** (customer) branch is
  now settable per invoice — `invoices.counterpart_branch` (default 0) reaches the filed myDATA
  Counterpart (the "by the book" replacement for the legacy duplicate-ΑΦΜ branch hack; a customer
  is one ΑΦΜ, the establishment is a per-document attribute). No `customer_branches` table yet —
  1 ΑΦΜ in the whole legacy backup used it; a future child table would just populate that column.
  (ΔΑ / DeliveryNote counterpart branch stays hardcoded 0 — out of this scope; revisit if a tenant
  ever ships to a specific customer establishment on a delivery note.)
  **Deferred P2s from the counterpart-branch review (consciously, low value for 1 rare ΑΦΜ):**
  (i) **no in-form signal** that a branch typed for a retail (11.x) or foreign counterpart is
  ignored (`filedCounterpartBranch()` files 0, the infolist hides it, and the helper text says so,
  but there is no reactive warning/disable tied to the selected customer/type); (ii) **branch↔PDF
  address can diverge** — the operator can set `counterpart_branch=5` and forget to change the
  free-text address snapshot, so myDATA names branch 5 while the PDF prints the έδρα address (no
  cross-field enforcement; inherent to the no-`customer_branches` design — the child table, which
  would carry per-branch addresses and auto-fill both, is the real fix). Accepted-as-negligible:
  the double `counterpartCountryForFiling()` resolve on a branch>0 filing (short-circuited to a
  single column read for the branch=0 common case).
- **B2G / POS scopes** — **PROV-012** (public contracts, All-in-one POS). Requirement is
  only that ekdosi not *claim* them — it doesn't.
- **Offline / Transmission Failure** — **PROV-008**. «Design with InvoSign, do not
  improvise»; at ~70 docs/month a provider outage is handled by *waiting*.
- **Provider cancellation-evidence / historical-channel freeze** — **PROV-015 / PROV-016**.
  A provider-filed 2.1/11.x can't be cancelled at all (see above), so almost no live surface.
- **Credit-compatibility matrix** — **PROV-004** (5.1/5.2/11.4). Practical fix: set ΠΙΣ = 5.1,
  one credit type configured; enforce in code later.
- **Sandbox acceptance matrix (~30 rows)** — **PROV-013**. Superseded in practice by the
  bucket-A dry-run on the document types these tenants issue. Keep as aspiration.
- **Fresh-install onboarding** — **SETUP-001 / SETUP-002 / OPS-001**. Host is installed,
  provisioned, green (`ops:health`). Product-quality for the *next* installation.
- **The whole UPD-* family** — already DISARMED; `deploy/update.sh <tag>` is the path.
  Re-arming needs UPD-001…004 closed first (see `versioning-and-updates.md`).
  - **Όταν ξ-αρματωθεί: το apply path να διαβάζει repo/token από το UI override, όχι σκέτο `config()`.** Ο
    read-only `UpdateChecker` πλέον διαβάζει `system.update_repo`/`system.update_token` (UI) με fallback στο
    `.env`. Ο `SelfUpdate` (git fetch) και το `SystemHealth::applyAvailable()` ΑΚΟΜΑ διαβάζουν μόνο `config()`,
    οπότε ένας tenant που έβαλε token ΜΟΝΟ στο UI θα κάνει ανώνυμο git fetch σε private repo όταν αρματωθεί το
    in-app apply. Ακίνδυνο σήμερα (apply OFF). Fix όταν αρματωθεί: κοινός resolver repo/token (override→env) και
    για τα δύο μονοπάτια.

---

## 📚 Kept design / reference docs (indexed here, not deleted)
These are genuine specs / architecture blueprints / ops runbooks / historical records —
kept on their own, with their current status. The forward-looking work in them is
surfaced in the open-items sections further down.

- **`PLAN.md`** (repo root) — master roadmap «Ekdosi ως σταδιακή αντικατάσταση WHMCS»
  (4 πυλώνες: **Domains → Payment gateways → Provisioning → Portal**, strangler-fig).
  Domains **OPEN** (Φάσεις A0–A5, βλ. epic «Αντικατάσταση WHMCS» παρακάτω)· Πυλ. B/C έχουν
  ήδη blueprint/seam (`payment-connectors.md` · `ProvisioningModule`), Πυλ. D = νέο.
- **`domains/README.md`** — Πυλώνας A **αναλυτικό design** (pre-build): data model + `DomainRegistrar`
  contract + Openprovider endpoint mapping + .gr/grEPP rules + rich per-domain View + phase gates
  A0–A5. **DESIGN, no code yet.**
- **`paroxos/regulatory-blueprint.md`** + **`paroxos/implementation-plan.md`** — GR
  ΥΠΑΗΕΣ provider + EU PEPPOL. PEPPOL Phase 1 (UBL builder, `peppol:test-submit`) **DONE**;
  provider P0–P5 built/gated (mode=off); **PEPPOL Phase 2 + live provider = OPEN**.
- **`payment-connectors.md`** — card-POS + IRIS design. **NOT-STARTED** (blueprint).
- **`archive/leads-mini-crm.md`** — **Leads / mini-CRM** (υποψήφιοι πελάτες + χρονολόγιο επαφών +
  μετατροπή σε πελάτη + απολογισμός ανά χειριστή). **L0 + L1 + L2 + L3-όψεις (kanban/ημερολόγιο) DONE**
  (§10)· email-από-lead + AI `lead_summary` = **συνειδητά ΟΧΙ** (owner 2026-09-02). **BUILT → archived.**
- **`payments` (AR)** — core **DONE** (cockpit/allocator/bank-accounts/refunds); deferred
  connectors → `payment-connectors.md`.
- **`bridges-connectors.md`** — multi-billing-source. Phase 0 (registry seam) **DONE**;
  Phase 1 (real 2nd source) **OPEN**.
- **`ai-assistant-blueprint.md`** — in-app «Βοηθός» + external MCP. In-app chat **DONE** (§16β);
  **external MCP server DONE** (`MCP.md`, §16γ — same registry, tenant-bound token, propose-only
  writes, ops/debug tools). **OPEN follow-ups:** per-tenant OAuth binding (claude.ai multi-company),
  `connection_health` tool (WHMCS/myDATA freshness), curated-KB `knowledge_search` (item ζ below).
- **`whmcs-legacy-plugin-map.md`** — legacy WHMCS plugins → `ekdosi_bridge`. T-1/T-2 **DONE**;
  T-3 cutover **OPEN**.
- **`archive/delivery-provider-split-brain.md`** — ΔΑ provider-vs-direct-myDATA routing (architecture
  lock, **RESOLVED + provider-confirmed in writing → archived**).
- **`archive/cmr-international-delivery.md`** — CMR διεθνής φορτωτική ως αυτόνομο έγγραφο
  (Phases 1–4 **BUILT** → archived· Phase 0 = φορολογική απόφαση own-gear, non-blocking).
- **`operator-health.md`** · **`dr-without-app-key.md`** · **`go-live-usage-checks.sql.md`** —
  ops runbooks (reference).
- **`archive/`** — closed historical records (the sandbox-validation reports, the
  2026-07 production audit) — the «what happened / evidence» trail, moved out of the
  live `docs/` tree.
- **`aade/`** — the AADE myDATA + Delivery-Note specs.

---

## ✅ Done recently (so we don't re-pick them)
- **`einvoice_provider_key` κανονικοποιείται στο ΓΡΑΨΙΜΟ (mutator στο `Company`) + data-fix migration.**
  Ο `ProviderTransportRegistry::for()` κάνει `trim()`, άρα η ΥΠΟΒΟΛΗ δούλευε με κλειδί που κουβαλά κενά, ενώ
  κάθε σύγκριση της ωμής στήλης αστοχούσε. **Μετρημένα** συμπτώματα (probes, όχι εικασία): η φόρμα εταιρείας
  γινόταν ΑΔΥΝΑΤΟ να αποθηκευτεί (το συντιθέμενο channel δεν υπήρχε στα options → validation error σε κάθε
  save, ακόμη και για άσχετη αλλαγή)· το preview payload στο ViewInvoice + το `einvoice:provider-test-submit`
  έδειχναν **μη-augmented** XML ενώ η πραγματική υποβολή ήταν augmented· η κάρτα υπολοίπου έλεγε «καμία
  υποβολή ακόμη»· το `go-live-check` έγραφε **pass** για κλειδί που έπεφτε σε `NullProviderTransport`.
  **ΔΙΟΡΘΩΣΗ του προηγούμενου σημειώματος:** είχε καταγραφεί ότι «η αποθήκευση της φόρμας μηδενίζει το
  `einvoice_provider_config`». **Δεν ισχύει** — probe στην πραγματική ροή Livewire: χωρίς αλλαγή channel το
  save μπλοκάρεται από validation (τίποτα δεν γράφεται)· με επιλογή του σωστού channel το `->live()` Select
  εμφανίζει το προ-συμπληρωμένο πεδίο και το token επιβιώνει. Το wipe εμφανιζόταν μόνο σε συνθετικό array
  κατευθείαν στο bridge, που το UI δεν παράγει ποτέ. Το bridge σκληρύνθηκε πάντως (σύγκριση normalised).
- **PEPPOL Phase 1** (PR #253) — provider-independent BIS 3.0 UBL builder + `peppol:test-submit`.
- **DR / «work without APP_KEY»** (PR #254) — `MaybeEncrypted` cast + `secrets:reencrypt`; default plaintext.
- **`$hidden` on secret models** (PR #255).
- **Expense classification → AADE** (PR #256) — `SendExpensesClassification` + per-line + `expenses:test-classify`.
- **FK-aware delete guard** (PR #258) — `GuardedDeleteAction`.
- **«Σύστημα» area — 3 slices** (2026-06-10): «Υγεία συστήματος» page · durable `scheduled_task_runs` + queue retry · «Ρυθμίσεις χρονοπρογραμματιστή» (audited toggles, `system_settings`).
- **«Ρυθμίσεις συστήματος» page** — global knobs (`require_2fa`, backup-alert on/off + email) ως audited live toggles· at-rest encryption + mailer status read-only.
- **Expenses polish** (2026-06-10): χειροκίνητη καταχώριση εξόδου (`source=manual`, γραμμές, tab «Χειροκίνητα», edit μόνο για manual) + ιδιωτικό PDF/scan attachment με signed download.
- **Δίγλωσσο/EN PDF** (2026-06-10): γλώσσα ανά invoice/quote (GR/EN/δίγλωσσο, default από χώρα πελάτη)· `PdfLabels` dictionary· localizes μόνο ετικέτες.
- **Withholding/fees count toward owed** (2026-06-11): `invoices.payable_total` (= gross + AADE [208] adjustment)· owed/balance/Καρτέλα/receivables/dashboard + PDF «Πληρωτέο» = payable· `invoices:backfill-payable-total`· money-consistency proven με τιμολόγιο παρακράτησης.
- **«Υπόλοιπο πελάτη» στο PDF** (2026-06-11): legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ» — snapshot-at-issue (`invoices.customer_balance_snapshot`, capture στον `InvoiceObserver`), block Προηγούμενο+παραστατικό=Νέο, opt-in per-tenant (`show_customer_balance_on_pdf`) + override per-customer (`show_balance_on_pdf`)· μόνο επί πιστώσει/πιστωτικά.
- **Καρτέλα «αναλυτική παρακράτηση»** (2026-06-11): στο AR-ledger row, όταν το εισπρακτέο διαφέρει από την αξία εγγράφου (παρακράτηση/τέλη), εμφανίζεται detail «Αξία εγγράφου 1.240 · Παρακράτηση φόρου 200» κάτω από την αναφορά (page + statement PDF). Display-only — debit/credit/υπόλοιπο μένουν = payable (chose inline-detail αντί synthetic rows ώστε running-balance + paid/unpaid filters να μη χαλάνε).
- **Onboarding** (2026-06-10): DEMO seeder · `ekdosi:install` wizard (+ lookup seeding via `MyDataLookupSeeder`) · global+per-company «Δοκιμή SMTP» · export/import χωρίς passphrase.
- **myDATA console unification** (PR #272) — one «Κονσόλα myDATA» cluster (Πωλήσεις/Έξοδα/Ε3) + redirects.
- **Expenses fetch** (PR #272) — «Άντληση από myDATA» κουμπί στη λίστα Έξοδα + tip + read-only `mydata:refresh-expenses` cron (UI toggle, default OFF).
- **Already shipped earlier — docs were stale, now corrected:** ΔΑ **movement lifecycle**
  (έναρξη/παράδοση/έλεγχος μέσω `DeliveryLifecycleService`: `registerTransfer`/`confirmDelivery`/`refreshStatus`) ·
  **§8.13 μονάδες μέτρησης** + seeder (`MyDataLookupSeeder::seedMetricUnits`, `MetricUnit`) ·
  **enrich/QR από MARK** (`EnrichInvoiceFromAade`).
- **Sandbox round 2 ✅** (2026-06-10) — ΔΑ lifecycle + νέοι taxTypes (fees/stamp/deductions) +
  product-linked taxes + 4% override, όλα AADE-accepted (`sandbox-results.txt`).
- **Ηλικίωση οφειλών** (PR #308) — aged-receivables page (0-30/31-60/61-90/90+ ανά πελάτη, σύνολα,
  drill στην Καρτέλα, CSV· reuse Καρτέλα FIFO aging). FEATURES §12.
- **Βιβλίο Εσόδων-Εξόδων → myDATA period report** — ΜΑΡΚ+κατάσταση στήλες, Έσοδα/Έξοδα+σύνολα,
  period presets, **PDF οριζόντιο A4**, exports CSV/XLSX/JSON. FEATURES §12. (Πλήρως κλεισμένο.)
- **Panel utility CSS (no-build)** — `resources/css/panel.css` μέσω `FilamentAsset::register` →
  `filament:assets`· όλα τα custom blade utilities πλέον styled, χωρίς npm/Vite/theme.
  _(Maintenance: νέο utility σε blade → πρόσθεσέ το εκεί.)_
- **Sendable customer statement (επαφή-aware)** (2026-06-17) — Καρτέλα → PDF/email σε πελάτη +
  τις επαφές του (role-labelled) + ελεύθερα extras (validate/dedupe). FEATURES §7.
- **Καρτέλα — όψη περιόδου + ομαδοποίηση header actions** (2026-06-17) — φίλτρα περιόδου πάνω από
  τον πίνακα + **σύνολα έτους** (τζίρος/εισπράξεις/υπόλοιπο)· header actions σε dropdowns + **global
  fix** στο overflow (`.fi-header-actions-ctn` wrap, αφορά όλες τις σελίδες με πολλά actions). FEATURES §7.

---

## 🛑 Looks like a gap — but it is NOT (don't re-open without a NEW reason)
- **RequestVatInfo «ΦΠΑ cross-check»** — **deferred ON PURPOSE.** Μετράει το Φ2 *deductible*
  της ΑΑΔΕ — **άλλο πράγμα** από το δικό μας «άθροισμα ΦΠΑ εξόδων» → μόνιμη ψεύτικη διαφορά.
  **NB:** η **ΦΠΑ picture** (dashboard «Εικόνα από myDATA» + box στην Ε3) **ΕΙΝΑΙ χτισμένη**
  (sum-of-docs, `MyDataVatAggregator`) — μην τη μπερδεύεις με το RequestVatInfo cross-check.
- **E3 ↔ local classification diff** — ίδια οικογένεια· deferred μέχρι να υπάρξει πραγματική ανάγκη.
- **E3 overview** — **already done** (`MyDataE3Overview`, νούμερα της ΑΑΔΕ).
- **`TenantScopedUnique`** — redundant (DB `unique(company_id,…)` constraints).
- **Manual «off-the-books» expense entry** — βλ. «myDATA/expenses completeness» (deferred, όχι «gap»).
- **Stock movements / ΣΔΕΠ / WHMCS `-333/-1000` sentinels** — DEAD legacy code· build μόνο αν το prod `.fbk` δείξει πραγματική χρήση.

---

## 🟠 myDATA / expenses completeness
- **`mydata:backfill-config` §8.3 προεπιλογή = «verify» default (P2, από review)** — το `--exemption-default`
  γράφει τον seed-κωδικό 4 (ενδοκοινοτική υπηρεσία) σε ΜΙΑ reason-less 0% κατηγορία, αλλά ο νόμιμος λόγος
  απαλλαγής **δεν βγαίνει από την κατηγορία μόνη της** (μπορεί να είναι εξαγωγή=8, reverse-charge=16…). Οι
  δικλείδες: opt-in flag, μόνο single-category (2+ = ambiguous), skip vatCategory-8, dry-run, ρητό output
  «ΠΡΟΕΠΙΛΟΓΗ — επιβεβαίωσέ την». Συνειδητό trade-off (ο owner ζήτησε ρητά τον default). Η πλήρης λύση
  (per-category scenario picker) είναι ο `VatExemptionGuidance` scenario-picker που ήδη τρακάρεται εδώ.
- **`mydata_read_env` override: gate στο aade-id, όχι στο subscription-key (P2, από review)** — το
  `Company::mydataReadMode()` τιμά το override (και η auto-λογική διαλέγει slot) με `filled(aade_id)`,
  **όχι** το key. Άρα override=production με production aade-id αλλά κενό key → `canReadMyData()=true`,
  μα το `FirebedCredentials::init()` πετάει στο fetch (αντί να πέσει σε sandbox). **Συνειδητά deferred:**
  (α) η υπάρχουσα auto-λογική έχει ΤΗΝ ΙΔΙΑ ασυμμετρία (id-only) — αλλαγή μόνο στο override θα ήταν
  ασυνεπής· (β) ο έλεγχος του key απαιτεί **decrypt κατά την πλοήγηση**, ακριβώς αυτό που το docblock
  αποφεύγει (crash σε rotated APP_KEY). Το `mydata_settings` MCP tool ήδη δείχνει `*_subscription_key`
  presence χωριστά, οπότε η μισο-ρυθμισμένη κατάσταση είναι ορατή. Πλήρης λύση: κοινός helper που ελέγχει
  ΚΑΙ τα δύο slots + surfacing στο UI — για ΟΛΑ τα read paths μαζί, όχι μόνο το override.
- **`mydata_read_env` override αγνοεί το gr-mydata «Off» (P2, από review)** — το override αξιολογείται
  ΠΑΝΩ από το `gr-mydata → Off→null` gate, οπότε ένας gr-mydata tenant με myDATA σκόπιμα Off + leftover
  creds + ρητό override ξαναποκτά ΑΝΑΓΝΩΣΗ (μπαίνει στα scheduled read jobs). **Συνειδητά deferred:** το
  «Off» στο `MyDataMode` σημαίνει «καμία **υποβολή**» — οι αναγνώσεις είναι ορθογώνιες (ο πάροχος διαβάζει
  ενώ mydata_mode=off), και το override είναι **ρητό opt-in** δύο ενεργειών (set env + creds), read-only.
  Defensible· αν φανεί surprising στην πράξη, το gate γίνεται «override δεν ξυπνά reads σε gr-mydata Off».
- **Η σελίδα ΜΑΡΚ δείχνει το XML της ΤΕΛΕΥΤΑΙΑΣ ανταλλαγής, όχι της έκδοσης (P2, από review MYD-023)** —
  το `MyDataMarkDetail::load()` κάνει `where('mark', …)->latest('id')`, οπότε όταν υπάρχει γραμμή
  CANCEL με το ίδιο ΜΑΡΚ, το panel request/response XML δείχνει την **ακύρωση** αντί για την αρχική
  υποβολή. Δεν είναι regression: έτσι συμπεριφερόταν ήδη η **απευθείας** διαδρομή (γράφει το ΜΑΡΚ
  έκδοσης στη γραμμή CANCEL από πάντα) — το MYD-023 απλώς έφερε και τη διαδρομή **παρόχου** στην ίδια
  συμπεριφορά. Σωστό θα ήταν να δείχνει το XML της γραμμής INSERT/PROVIDER_INSERT (ή και τα δύο, με
  διαχωρισμό «έκδοση / ακύρωση»), αλλά αυτό αλλάζει υπάρχουσα συμπεριφορά που δουλεύει → χωριστό item.
- **Αυστηρή άρνηση σε `Success` ακύρωσης ΧΩΡΙΣ ΜΑΡΚ ακύρωσης — μαζί με υιοθέτηση
  «ήδη ακυρωμένου» σε ΔΑ + πάροχο (υπόλοιπο MYD-023, P1)** — το MYD-023 έκλεισε την
  **αποθήκευση** (τα δύο ΜΑΡΚ σε ξεχωριστές στήλες παντού, + το ΜΑΡΚ ακύρωσης στο
  `STATE_SYNC`). Δεν έκλεισε το «μια κανονική επιτυχία χωρίς ΜΑΡΚ ακύρωσης να ΜΗΝ γίνεται
  τελική». **Δεν είναι μικρή αλλαγή μόνη της:** η απευθείας διαδρομή τιμολογίου έχει δίχτυ
  (self-heal στο `[251]` «ήδη ακυρωμένο»), αλλά η διαδρομή **δελτίων** και η διαδρομή
  **παρόχου** ΔΕΝ έχουν καμία υιοθέτηση ήδη-ακυρωμένου. Άρνηση εκεί = το παραστατικό
  ακυρωμένο στην ΑΑΔΕ και VALID τοπικά, με το retry να πετάει για πάντα — ακριβώς το
  stranding που ξηλώθηκε τρεις φορές στο MYD-021. Άρα: **πρώτα** υιοθέτηση
  ήδη-ακυρωμένου στις δύο διαδρομές, **μετά** η αυστηρή άρνηση — ένα κοινό item, όχι δύο.
  Δένει με PROV-015.
- **PEPPOL buyer = ζωντανός πελάτης, όχι snapshot (follow-up MYD-009)** — ο
  `PeppolInvoiceDocument` χτίζει τον αγοραστή εξ ολοκλήρου από το `customer`, ενώ η ελληνική
  διαδρομή (AADE + πάροχος) χτίζει πλέον τη νομική ταυτότητα από το **παγωμένο snapshot** του
  τιμολογίου. Δεν είναι bug σήμερα: ένας tenant είναι είτε `gr-mydata` είτε `ee-peppol`, οπότε
  κανένα μεμονωμένο παραστατικό δεν μπορεί να διαφωνεί με τον εαυτό του. Γίνεται όμως πραγματικό
  θέμα **μόλις ζωντανέψει το PEPPOL** (αλλαγή στοιχείων πελάτη θα ξαναγράφει την ταυτότητα
  περασμένου τιμολογίου). Θέλει τα ίδια helpers — `counterpartAfm()/counterpartName()/
  counterpartCountryForFiling()` — και το `frozenPartyColumns()` στο persist του PEPPOL submitter.
- **Στοιχεία επικοινωνίας αντισυμβαλλόμενου στο InvoSign — ζωντανά by design (follow-up MYD-009)** —
  τα νομικά πεδία (επωνυμία/ΑΦΜ/επάγγελμα/διεύθυνση) χτίζονται πλέον από το **παγωμένο snapshot**
  του τιμολογίου, αλλά τα `CounterpartTaxOffice` / `CounterpartPhone` / `CounterpartEmail`
  διαβάζουν σκόπιμα τον **ζωντανό** πελάτη: δεν είναι μέρος της νομικής ταυτότητας, λείπουν
  εντελώς από το AADE payload, και ο πάροχος τα χρησιμοποιεί για αποστολή/εκτύπωση — οπότε το να
  φτάνει το σημερινό email είναι το σωστό. **Αν** ποτέ χρειαστεί να είναι αναπαραγώγιμα (π.χ.
  «τι email είχε τότε;» σε έλεγχο), θέλουν **δικές τους στήλες snapshot** — όχι σιωπηλό πάγωμα
  μέσα στον builder. Καταγράφεται ως συνειδητή απόφαση, όχι ως παράλειψη.
  - **`CounterpartPhone` ungated (P2, από review)** — ο διακόπτης `einvoice_include_customer_email`
    (default OFF) πυλώνει μόνο το `CounterpartEmail`, όπως ζητήθηκε ρητά. Το `CounterpartPhone` μένει
    πάντα ζωντανό. Σήμερα ο InvoSign παραδίδει με email (όχι SMS), οπότε δεν είναι footgun· **αν** ποτέ
    προστεθεί SMS-παράδοση, το τηλέφωνο γίνεται ανάλογη περίπτωση → είτε δεύτερος διακόπτης είτε
    επέκταση του ίδιου (rename σε `einvoice_include_customer_contact`).
- **Χώρα πελάτη/προμηθευτή σε ISO picker (MYD-011) — ✅ DONE (Option B).** Η λύση που δούλεψε:
  αντί να μπει `Select` πάνω στην ωμή free-text στήλη (που έβαζε implicit `in` rule και **μπλόκαρε
  το save** σε κάθε legacy «ΙΤΑΛΙΑ» — γι' αυτό είχε αναιρεθεί), προστέθηκε **νέα καθαρή στήλη
  `country_code`** (πάντα null ή έγκυρο ISO) και ο picker δένεται εκεί. `IsoCountry::syncCountryCode`
  (save-hook, μία λογική για τα δύο models· `isDirty` ξεχωρίζει «picker set code» από «label changed»
  ώστε να μη μένει stale code), `ekdosi:backfill-country-codes` (idempotent), ETL γράφει την cache,
  και `suppliers.country` έγινε nullable/no-default → τέλος το silent-GR freeze. Το `country` μένει ως
  legacy/mirror label· οι resolvers έκδοσης διαβάζουν `isoCountryCode()` (zero-regression fallback).
  **Conscious tradeoffs (review P2, αποδεκτά):** (α) ένα deliberate re-pick στον picker **αντικαθιστά**
  το legacy free-text spelling («ΙΤΑΛΙΑ»→`IT`) ώστε να μη διίστανται οι δύο στήλες — το `country` δεν
  εμφανίζεται ως label πουθενά στην εφαρμογή (grep: κανένα table/infolist) και το πρωτότυπο μένει στο
  activity log/git· (β) αλλαγή του label σε μη-αναγνωρίσιμη τιμή **μηδενίζει** το derived code (→ ο
  submitter αρνείται) αντί να κρατά stale code που το νέο label δεν δικαιολογεί — safe by design.
  **Follow-up (P2, review):** τα money-adjacent predicates `ReverseCharge::isEuNonGreek`, το
  `PeppolEndpoint` και το EU-hint στο `InvoiceForm` διαβάζουν ακόμη το ωμό `customer->country` (όχι
  `isoCountryCode()`). Δεν είναι regression (έτσι ήταν πριν, και ο mirror κρατά το `country`
  resolvable), αλλά το root-fix θα ήταν να περάσουν κι αυτά από `isoCountryCode()` — εκτός MYD-011
  scope (αγγίζει VAT reverse-charge logic), οπότε ξεχωριστό βήμα με δικά του tests.
- **ΔΑ σε εξωτερικό παραλήπτη χωρίς ΑΦΜ — ανοιχτό ερώτημα ΑΑΔΕ (MYD-011 residual)** — όταν
  υπάρχει επώνυμος εξωτερικός παραλήπτης **χωρίς ΑΦΜ**, το μόνο διαθέσιμο placeholder είναι η
  σεντινέλα `000000000`, που όμως η Α.1123/2024 την ορίζει για **ενδοδιακίνηση**. Σήμερα:
  πελάτης-linked → `000000000` + GR (η domestic populace που αλλιώς θα κολλούσε)·
  manual/προμηθευτής → **άρνηση** αν δεν δοθεί χώρα. Χρειάζεται επιβεβαίωση από ΑΑΔΕ/λογιστή
  ποια είναι η σωστή δήλωση για ιδιώτη/μη-υπόχρεο παραλήπτη, και ίσως ξεχωριστό πεδίο αντί για
  τη σεντινέλα.

- **`invoice_taxes` table** — πολλές κατηγορίες ανά taxType σε **ΕΝΑ** τιμολόγιο (σήμερα μία/τύπο
  αλλιώς throw). **Χαμηλή προτεραιότητα/σπάνιο** — το ΦΠΑ ανά γραμμή παίζει ήδη· αυτό αφορά
  μόνο 2+ διαφορετικές κατηγορίες **ειδικού τέλους** (§8.x) στο ίδιο παραστατικό. _(Η money-core
  απόφαση «μετράνε τα τέλη/παρακράτηση στο οφειλόμενο;» **λύθηκε ✅** = `payable_total` — βλ. «Done recently».)_
- **Expenses — λογιστής/`entityVatNumber`** third-party submission (για tenants που μπλοκάρει
  η ΑΑΔΕ με [323]) + `RequestMyExpenses` (sanity totals) + **supplier CSV import** (`source=import`).
- **`SalesOrphanImporter`** — νέο τοπικό τιμολόγιο **πώλησης** από sales-orphan MARK + **line/E3
  backfill** στο enrich όταν το τοπικό δεν έχει γραμμές. _Χαμηλή αξία — πώληση εκδομένη από
  άλλο πρόγραμμα συνήθως απλώς αναγνωρίζεται. (Το expense-orphan import υπάρχει.)_
- **Expenses polish (remaining):** per-row import action (+ «held/needs-review» state) στην
  κονσόλα-Έξοδα. _(Χειροκίνητη καταχώριση + PDF/scan attachment: ✅ shipped — βλ. «Done recently».)_
- **§8.13 quantity/units για ΔΑ αγαθών** — οι μονάδες υπάρχουν· τυχόν goods-tenant ειδικά
  (π.χ. `<quantity>` per-line σε goods invoice types) ανοίγουν μόνο αν έρθει goods tenant.
- **Combined Τιμολόγιο–Δελτίο Αποστολής (ΤΔΑ) — ✅ SHIPPED (Slice 3a–3d, MYD-002 closed → FEATURES §5).**
  Ένα 1.1 με `isDeliveryNote=true` + movement header στο ίδιο έγγραφο: schema (3a) + payload (3b) +
  lifecycle contract `MovableDocument` (3c) + UI/seed/normaliser + invoice-view κινήσεις + goods-type
  guard + row-lock (3d-b) + shared movement-header builder (3d-c). Ζει με τα Παραστατικά· ακυρώνεται από
  το monetary path (§7). Απομένει ΜΟΝΟ το **3e** (single stock event / PDF / polish).
  _(Resolved: 3d-b — goods-type guard [`AadeInvoiceDocument::applyMovementHeader` via firebed
  `supportsDeliveryNote`], row-lock [`SyncInvoiceStateFromAade` lockForUpdate re-check, WHMCS κλήση εκτός],
  FEATURES §5. 3d-c — `MovementHeaderBuilder::applyCommon` ενοποιεί movePurpose+dispatch(H:i:s)+vehicle και
  για τα δύο issue paths, byte-identical, golden suites πράσινα. Ιστορικό: CLAUDE-history.)_
- **Πλήρη 9.1 / 9.2 Δελτία Αποστολής** — το 9.1 (συσχετιζόμενο) θέλει payload με
  correlated MARKs (`addCorrelatedInvoice` + επιλογή σχετικών παραστατικών) και το 9.2
  (συγκεντρωτικό) μοντέλο σύνοψης πολλαπλών κινήσεων. Προς το παρόν είναι κρυμμένα από τον
  picker + μπλοκαρισμένα στον submitter (MYD-012, μόνο το 9.3 φιλάρεται μέσω allowlist).
  Ξεμπλόκαρέ τα όταν χτιστεί το μοντέλο (προσθήκη στο `Codes::SUPPORTED_DELIVERY_TYPES`).
- **measurementUnit = 7 (Τεμάχια_Λοιπές Περιπτώσεις) στα ΔΑ** — απαιτεί
  `otherMeasurementUnitQuantity` + `otherMeasurementUnitTitle` (§8.13 note 9, υποχρεωτικά).
  Δεν μοντελοποιούνται ακόμη → το 7 είναι σκόπιμα **μπλοκαρισμένο** (service throw) + κρυμμένο
  από τα line pickers (MYD-016). Full support = νέες στήλες σε `delivery_note_lines` + form fields
  + payload· άνοιξέ το αν το ζητήσει tenant με «λοιπές» μονάδες συσκευασίας (π.χ. παλέτες).
- **CMR (διεθνής φορτωτική)** — _✅ BUILT (Φάσεις 1–4): αυτοτελές `cmr_notes`/`cmr_lines`,
  `CmrResource` (standalone) + action «Δημιουργία CMR» σε Τιμολόγιο/ΔΑ (pre-fill + μεταγραφή
  ΕΛΟΤ-743), editable draft, `CmrPdf` 24-box, αγγλικά στοιχεία εταιρείας._ **Εκκρεμεί Φάση 0**
  (φορολογικά own-gear: move_purpose/ΦΠΑ = λογιστής, ΔΕΝ μπλοκάρει) + προαιρ. Φάση 5 («πακέτο
  εξαγωγής»). **`docs/archive/cmr-international-delivery.md`**. _Μετά deploy: `shield:generate`._

---

## 💶 Ταμειακή εικόνα / cashflow — «τα έξοδα που δεν έρχονται μόνα τους»
_Ιδέα 2026-07-12 (chrismfz). **Θα το δει με τον λογιστή πρώτα.** Επιλέχθηκε ρητά η
προσέγγιση «recurring templates + cashflow view», ΟΧΙ αυτόματη σύλληψη των πάντων._

**Πρόβλημα:** τα **έσοδα** είναι πεντακάθαρα (όλα κόβονται ηλεκτρονικά → «ό,τι κόβεται =
έσοδο»). Τα **έξοδα** ΟΧΙ: ο operator ξέρει τζίρο αλλά όχι μηνιαία εκροή, άρα δεν ξέρει «τι
του περισσεύει», πώς χτίζει αποθεματικό, ποιους μήνες να προσέχει (δώρα/άδειες/13ος-14ος).

**Framing (μη το ξεχάσουμε):** αυτό είναι **διοικητική/ταμειακή εικόνα, ΟΧΙ τα βιβλία του
λογιστή** (μισθοδοσία/ΕΦΚΑ/αποσβέσεις = δικά του). Σκοπός = cash-visibility, όχι δεύτερο
λογιστήριο — αλλιώς φουσκώνει σε κάτι που δεν συντηρείται.

**3 κουβάδες εξόδων** (κατά «το ξέρει το σύστημα μόνο του;»):
- **Α. myDATA GR e-invoiced** (Synapsecom, Alfanet, ΔΕΗ/ΟΤΕ/Vodafone, ΓΡ προμηθευτές) → **λυμένο**,
  `ExpenseImporter`/`ExpenseReconciler` τα τραβάει ήδη.
- **Β. Foreign B2B** (AWS, Hetzner, cPanel, WHMCS, Vultr, DirectAdmin, CloudLinux, JetBackup…) →
  **μόνο αν αυτο-δηλώνονται** (τύπος 14.x)· αλλιώς αόρατα.
- **Γ. Μη-τιμολόγια** (μισθοδοσία, ΕΦΚΑ ιδίων+παιδιών, δάνειο Εθνικής/στεγαστικό γραφείου, δώρα/
  έκτακτα/βλάβες) → **ποτέ** στο myDATA· πρέπει να δηλωθούν χειροκίνητα.

Το κλειδί (το είπε ο operator): **τα περισσότερα Β+Γ είναι recurring με μικρές αυξομειώσεις**
(π.χ. cPanel licenses που παίζουν λόγω accounts). Άρα: «όρισέ το μία φορά, ξαναγίνεται μόνο του».

**Φάσεις:**
1. **Μητρώο «Πάγια / Επαναλαμβανόμενα έξοδα».** Πρότυπο: όνομα, κατηγορία (Μισθοδοσία/ΕΦΚΑ/ΔΕΚΟ/
   Δάνειο/Foreign subs…), ποσό (+flag «κυμαινόμενο»), συχνότητα (μηνιαίο/τριμηνιαίο/ετήσιο), ημέρα,
   περίοδος ισχύος. Ο **scheduler (ήδη live)** φτιάχνει **πρόχειρο `Expense`** ανά περίοδο →
   operator επιβεβαιώνει/διορθώνει ποσό. Γράφεται σαν κανονικό `Expense` (νέο `source` ή `manual`)
   → μπαίνει «τζάμπα» στο `VatPeriodReport` + στο cashflow view.
2. **Ημερολόγιο εποχικών/έκτακτων.** Δώρα Χριστ./Πάσχα, επίδομα αδείας, 13ος/14ος, ετήσιες
   ασφάλειες = πρότυπα ετήσια/εξαμηνιαία αγκυρωμένα σε μήνα → οι «βαριοί μήνες».
3. **Widget «Τι μου περισσεύει»** (Αναφορές, δίπλα στα Έσοδα/μήνα + Εισπράξεις YoY): ανά μήνα
   **Έσοδα − Έξοδα (τιμολογημένα + πάγια) = καθαρή ροή**, γραμμή σωρευτικού **αποθεματικού**,
   σημαδεμένες οι κορυφές. Το payoff.

**⚠️ Ο ένας πραγματικός κίνδυνος — διπλομέτρημα:** πρότυπο «ΔΕΗ» **+** myDATA τιμολόγιο ΔΕΗ = 2×.
Λύση: match/reconcile βήμα, ή κανόνας «το πρότυπο μετράει μόνο αν ΔΕΝ βρεθεί myDATA παραστατικό
τον μήνα». Ίδιο για foreign που ήδη δηλώνονται (14.x). **Ερώτημα για τον λογιστή:** ποια foreign
δηλώνονται ήδη (καθορίζει πόσα μπαίνουν χειροκίνητα). **Υποδομή έτοιμη:** `Expense`+CRUD+import
υπάρχουν· λείπουν μόνο (α) recurring-templates, (β) cashflow widget, (γ) το anti-double-count.

---

## 🔵 Big features (blueprints kept — see index above)
- **PEPPOL Phase 2** — Access-Point transport («send»). Phase 1 (UBL) DONE· θέλει EE provider +
  sandbox creds (Billit/Finbite/Telema…). `paroxos/regulatory-blueprint.md §7`.
- **GR Πάροχος live** — P2–P5 built/gated (mode=off)· θέλει πραγματικά provider creds + sandbox
  (InvoSign/SBZ). `paroxos/`.
- **PROV-003 archive half** (print ✅ #406· snapshot + invoice-page evidence ✅ 2026-09-03) —
  απομένει **(α) ανάκτηση + ιδιωτική αρχειοθέτηση του επίσημου PDF παρόχου**: SHA-256, immutable πρώτη
  έκδοση, retry ΜΟΝΟ download (ποτέ re-file), allowlisted hosts/bounded size (anti-SSRF),
  `evidence_pending` state όσο λείπει artifact — χωρίς να κάνει fail ένα VALID filing. **BLOCKED στον
  πάροχο:** το InvoSign API (v1.0.1) επιστρέφει μόνο `invoiceMark`/`invoiceUid`/`authenticationCode`/
  `qrUrl` — **κανένα download endpoint/canonical URL εγγράφου**· το `qrUrl` είναι η landing σελίδα
  `viewinvoice.php`, που το spec ΑΠΑΓΟΡΕΥΕΙ να αρχειοθετηθεί ως το επίσημο έγγραφο. Ξεμπλοκάρει μόνο αν
  ο πάροχος εκθέσει download/retention API. Η υποχρέωση διατήρησης του εκδότη (ν.4308/2014) καλύπτεται
  ήδη (δεδομένα + MARK + UID + auth + request/response XML + ο δείκτης verification URL). Επίσης
  απομένει **(γ) το πλήρες «compare» panel** (τοπικό snapshot × AADE reconciliation) στην καρτέλα.
  _(β snapshot αδείας-εν-ισχύ ανά παραστατικό: ✅ DONE — `mydata_marks.provider_identity`.)_
  _(PROV-003 — archive half is TIER 4, blocked on an InvoSign download endpoint.)_
- **Provider endpoint hardening (PROV-017 follow-ups)** — το core URL guard (public-https-only,
  no userinfo/query/port≠443, no private/loopback/link-local/CGNAT host, no credentialed redirects)
  ✅ SHIPPED. Είναι **best-effort accident-prevention** (το URL το βάζει έμπιστος operator). Deferred
  hardening για πλήρη anti-SSRF: (α) **resolver-consistency + IP-pin** — ανάλυση με τον ΙΔΙΟ resolver
  (getaddrinfo/`/etc/hosts`, όχι μόνο `dns_get_record`) και POST στην ήδη-ελεγμένη IP (CURLOPT_RESOLVE),
  ώστε να κλείσει το fail-open (κενή ανάλυση = δεν μπλοκάρει) και το TOCTOU/DNS-rebinding· (β) parse_url
  host-confusion — ο έλεγχος γίνεται με `parse_url`, ο connect με curl (πιθανή απόκλιση σε crafted URLs)·
  (γ) provider-managed endpoint-profile registry αντί ελεύθερου URL (vendor-confirmed hosts)· (δ)
  μη-blocking DNS (το `dns_get_record` είναι σύγχρονο στο hot path/form-save).
- **Bridges/Connectors Phase 1** — πραγματική 2η πηγή (WooCommerce/Blesta…). `bridges-connectors.md`.
  _Phase 0.5 ✅ (presentation-only): source-neutral «Εισερχόμενα» + source badge · «Γέφυρες» page
  (honest status, no fake toggle). Phase 1 = move `companies.whmcs_*` → `billing_connections.config`,
  ExternalDocument DTO, generic ingest dispatcher, real is_active gating, + the 2nd connector —
  build WHEN a real 2nd source exists (designing the contract against WHMCS+guesswork bakes in WHMCS-isms)._
- **AI «Βοηθός»** — _✅ Phase 1 SHIPPED 2026-06-17: read-only chat (σελίδα + floating widget, κοινό
  `AssistantRunner`), tool layer isolation + per-tool permission, governance web/DB (on/off · model · token
  cap · per-company key) + `ai_usage_log` metering + caps. ✅ Phase 2a SHIPPED 2026-06-17: 6 read-only
  insight tools (`count_sales`/`outstanding_receivables`/`list_top_debtors`/`find_customer`/`recent_invoices`/
  `vat_summary`) + **clickable same-origin links** (Καρτέλα/view/νέο παραστατικό) μέσω `ChatMarkup` +
  prompt-caching toggle. ✅ Phase 2b SHIPPED 2026-06-17: **WRITE tools με operator-confirm** (ποτέ
  αυτόματα) — `send_customer_statement` (επαφή-aware) + `create_reminder`· staging σε `ai_pending_actions`,
  confirm/cancel κάρτες, `AiActionExecutor` (re-validate, scoped tenant+user), reminders → Filament DB
  notifications μέσω `ai:dispatch-reminders`._
  **Phase 2c (open) — ιδέες/σημειώσεις (καμία δέσμευση, χαμηλή προτεραιότητα):**
  - **(α) Περισσότερα read tools** — **✅ SHIPPED (3/4): `income_vs_expense`, `top_products`,
    `whmcs_inbox`** (dual-surface, chat + MCP). **Remaining: `backups_status`** — αφέθηκε γιατί
    τα backups είναι super-admin/global (αδέξιο σε tenant-scoped operator chat)· καλύπτεται ήδη
    μερικώς από το super-admin `app_health` MCP tool. Χτίσε το μόνο αν χρειαστεί operator-facing.
  - **(β) Περισσότερα write tools με confirm** — **✅ `record_payment` SHIPPED (2026-09-05):**
    «καταχώρισε είσπραξη» (reuse `PaymentAllocator::allocate`, `TYPE_RECORD_PAYMENT`, propose-only,
    μονοσήμαντος πελάτης, gate `Create:Payment`, chat + MCP). **Remaining — «κόψε πρόχειρο
    παραστατικό» (deferred):** τέμνει ανοιχτό design question — ένα draft «καίει» ΑΑ (βλ. pro-forma/
    ΠΡΟΤ-N item παρακάτω) και θέλει πολλά structured inputs (τύπος + γραμμές/είδη/ΦΠΑ). Χτίσε το ΜΕΤΑ
    την pro-forma απόφαση, με το ίδιο `ai_pending_actions` pattern.
    _(P2 cleanup: το fuzzy customer-match (name/ΑΦΜ like) υπάρχει πλέον σε 3 tools —
    `SendCustomerStatementTool`/`CreateReminderTool`/`RecordPaymentTool` με λίγο διαφορετικά
    return shapes· ένας κοινός `AssistantCustomerResolver` (found/ambiguous/none) θα αφαιρούσε το drift.)_
  - **(γ) Per-company κλειδί/βοηθός ξεχωριστά** — η στήλη `companies.ai_api_key` υπάρχει
    (στο `$hidden`)· λείπει το UI exposure (στο `CompanySettings` ή super-admin only) +
    per-key billing separation (κάθε εταιρεία δικός της Anthropic account/DPA).
  - **(δ) Persistence συνομιλιών** — `ai_conversations` table (ιστορικό + πολλές
    συνομιλίες ανά χρήστη, αντί session) — απαιτεί και UI επιλογής συνομιλίας.
  - **(ε) Usage dashboard + `ai_usage` tool — tokens/κόστος ανά εταιρεία. ✅ SHIPPED (2026-09-05).**
    Super-admin σελίδα «Χρήση & κόστος AI» (cross-tenant) **+ `ai_usage` chat/MCP tool** (per-tenant·
    MCP `company="all"` → ανά-εταιρεία fan-out). Follow-up: per-tenant self-view UI για company_admin. Ιστορικό:
    Χτίζεται ως **super_admin σελίδα «Χρήση & κόστος AI» ΜΕΣΑ στην περιοχή «AI Βοηθός»**
    (group «Σύστημα», δίπλα στο «Βοηθός AI») — **ΟΧΙ** στο κεντρικό dashboard (owner). Τα
    ΔΕΔΟΜΕΝΑ ΥΠΑΡΧΟΥΝ ΗΔΗ: το `ai_usage_log` κρατά input/output/cache tokens +
    `cost_estimate` ανά εταιρεία/χρήστη/συνομιλία/μοντέλο (source of truth για τα caps, βλ.
    `AiUsageMeter`). Surface: `sum(tokens)`/`sum(cost)` group-by μήνα × εταιρεία (ποιος
    πληρώνει, ποιος κοντά στο όριο) + per-user + μηνιαία τάση, προαιρετικά CSV. Read-only.
    _Follow-up: per-tenant self-view για company_admin (η δική του κατανάλωση vs cap)._
  - **(στ) Streaming απαντήσεων** — τώρα είναι «σκέφτομαι…» μέχρι να ολοκληρωθεί το
    tool-loop· streaming θα ήθελε SSE/Livewire polling (μεγαλύτερη αλλαγή στο surface).
  - **(ζ) Helper / «βοήθεια & συμβουλή» με curated knowledge base. ✅ SHIPPED (2026-09-05).**
    `knowledge_search` tool + `KnowledgeBase` (RAG-lite) πάνω στο `docs/assistant-kb/` (chat + MCP),
    ΑΥΣΤΗΡΟ grounding («ρώτα λογιστή» όταν δεν καλύπτεται). **Follow-up:** πλούτισε το KB (ο λογιστής
    προσθέτει επιβεβαιωμένες φορολογικές ενότητες με ημερομηνία ισχύος). _P2 follow-up:_ το
    `knowledge_search` είναι **global** (δεν αγγίζει tenant data) αλλά μέσω MCP περνά από τον
    `McpTenantResolver` — άρα multi-company χρήστης πρέπει να δώσει ένα (αδιάφορο) `company`. Θέλει
    ένα «no-tenant» μονοπάτι στο `AssistantMcpTool` (framework· το in-app chat δεν επηρεάζεται).
    _Το αρχικό σχέδιο:_ Δύο ΞΕΧΩΡΙΣΤΑ
    πράγματα: **(i) app how-to** («πού βλέπω τι μου χρωστάνε;», «πώς κόβω πιστωτικό;») —
    ασφαλές, γνώση της εφαρμογής· **(ii) domain advisory** («τι ΦΠΑ για Σκόπελο;», «τι
    παραστατικό για αποστολή δικού μου εξοπλισμού στο datacenter;», «ποιον τύπο να
    διαλέξω;») — ΕΠΙΚΙΝΔΥΝΟ αν απαντηθεί από γενική γνώση του μοντέλου (μειωμένα νησιά
    άλλαξαν πολλές φορές· λάθος = λάθος ΦΠΑ/ΑΑΔΕ). **Σχέδιο:** curated KB σε markdown
    (`docs/assistant-kb/`) που γράφεις εσύ/ο λογιστής + νέο tool `knowledge_search`
    (RAG-lite: επιστρέφει σχετικά αποσπάσματα) → ο βοηθός στηρίζεται ΑΥΣΤΗΡΑ σε αυτό,
    «δεν καλύπτεται → ρώτα λογιστή», ΠΟΤΕ εφευρεμένος φορολογικός κανόνας + πάντα
    disclaimer για φορολογικά. **Κουμπώνει με τα έτοιμα:** links (π.χ. «πώς στέλνω
    εξοπλισμό» → εξήγηση ΔΑ + link «Νέο Δελτίο Αποστολής»), `vat_categories` της
    εταιρείας (δείξε τις ρυθμισμένες, μη μαντεύεις). Ίδιο grounding-discipline με τα
    tools — απλώς προστίθεται μία ΕΓΚΕΚΡΙΜΕΝΗ πηγή δίπλα τους.
  - _Σχεδιαστικά κλειδωμένα ήδη (μην ξανασυζητηθούν): tool-layer isolation (κανένα `company`
    param), per-tool Shield permission, `#[Locked]` messages/transcript, `ChatMarkup`
    same-origin links, writes ΠΟΤΕ auto (operator-confirm). Engine = Laravel HTTP/Messages
    API χωρίς SDK. prompt-caching ✅ έγινε (2a)._ `ai-assistant-blueprint.md`
  (πλέον καλύπτει: **«δεν χρειάζεται Console agent»** για το in-app chat — μόνο API key +
  Messages API tool-loop· **abuse/resource safeguards** = no-code-execution + per-request
  max_tokens/tool-loop/timeout/history caps + per-tenant/user rate-limit + monthly token caps +
  audit· **grounding** system prompt (ξέρει ότι είναι ekdosi, ποια εταιρεία, off-task refusal)·
  **υποψήφιο μοντέλο = Sonnet 4.6 default**, Haiku 4.5 cheap tier, Opus 4.8 για βαριά ανάλυση).
- **Payment connectors** — IRIS + card-POS. `payment-connectors.md`.
- **Leads / mini-CRM** (2026-09-01, ιδέα ιδιοκτήτη — «αποκτούμε άτομο να κυνηγάει πελάτες») —
  ξεχωριστός `leads` πίνακας (όσα στοιχεία έχουμε, μόνο επωνυμία υποχρεωτική) + `lead_activities`
  χρονολόγιο (τηλέφωνο/email/ραντεβού/σημείωση, append-only, ποιος/πότε/τι ειπώθηκε/επόμενο βήμα) +
  status (νέο→…→πελάτης / χάθηκε / μην ξαναενοχλήσετε) + **dedupe warning** vs υπάρχοντες πελάτες &
  παλιά leads («να μην ξαναζαλίζουμε κόσμο») + **`ConvertLeadToCustomer`** με αμφίδρομο link
  (`leads.converted_customer_id`, μοτίβο quote→invoice) + section «Προέλευση» στον πελάτη +
  **«Απολογισμός πωλήσεων»** page (τηλέφωνα/emails/μετατροπές ανά χειριστή×εβδομάδα — «δούλεψε ο
  άνθρωπος;») + `next_action_at` reminders. **DESIGN ONLY, αποφάσεις κλειδωμένες** (§9: ρόλος =
  `operator`, όλοι βλέπουν όλα, παντού/multi-tenant, ελεύθερη επεξεργασία, μόνο χειροκίνητα, keep it
  simple) → `archive/leads-mini-crm.md`. **L0 + L1 ✅ SHIPPED** (resource + χρονολόγιο + καταστάσεις + dedupe +
  μετατροπή σε πελάτη + «Προέλευση» + προσφορά από lead, FEATURES §7β). **L2 ✅ SHIPPED**
  (`SalesActivityReport` + CSV, `leads:notify-due`, dashboard widget). **Μένει (προαιρετικά):** εβδομαδιαίο
  digest email του απολογισμού στον company_admin (μοτίβο backup-failure alert). **L3 όψεις (kanban +
  ημερολόγιο) ✅ SHIPPED**· email-από-lead + AI `lead_summary` = συνειδητά ΟΧΙ (owner: «too much»).

---

## 🌐 Αντικατάσταση WHMCS (σταδιακή) — master epic → βλ. **`PLAN.md`** (root)
Στόχος: το ekdosi να αντικαταστήσει σταδιακά το WHMCS (**strangler-fig**, όχι big-bang·
`billing_connections` επιτρέπει συνύπαρξη). Σειρά: **Domains → Payment gateways →
Provisioning → Portal**. Το «δύσκολο» (invoices/myDATA/recurring/υπόλοιπα) ήδη γίνεται·
κάθε πυλώνας = 6η/7η υλοποίηση του υπάρχοντος contract+registry pattern. Πλήρες σχέδιο +
data model + phase gates: **`PLAN.md`**.
- **Πυλώνας A — Domains** _(OPEN, πρώτο)_ — **αναλυτικό design: `docs/domains/README.md`** (data
  model, `DomainRegistrar` contract + OP endpoint mapping, .gr/grEPP rules, rich per-domain View,
  «Μεταφορά ιδιοκτησίας», API history). Dedicated `Domain` ↔ `ServiceContract` billing clock·
  registrar modules à la `EInvoiceProviderTransport`: **Openprovider** (gTLDs) + **grEPP** (.gr,
  direct EPP), routing ανά TLD. Φάσεις (stop σε κάθε gate):
  - **A0** θεμέλιο — ✅ **SHIPPED** (βλ. `FEATURES.md §21`): `enable_domain_management` flag +
    gated `DomainsCluster` + `DomainRegistrar` contract/registry/creds/Null («manual») +
    `config('ekdosi.domains.registrars')` + `domain_registrar_connections` (super_admin creds).
  - **A1** data model + manual CRUD — ✅ **SHIPPED σε δύο slices** (βλ. `FEATURES.md §21`):
    A1a πίνακες + «TLDs & τιμές»· A1b «Domains» resource + «Ανάθεση σε πελάτη» (SC 1:1) +
    «Μεταφορά ιδιοκτησίας» + Customer tab. Εκκρεμεί από το αρχικό A1 σκοπό ΜΟΝΟ το import
    (μετακόμισε: registrar-first → A2· το .gr list από grweb export).
  - **A2** Openprovider read-only — ✅ **SHIPPED σε slices A2a/A2b/A2c** (βλ. `FEATURES.md §21`):
    adapter+creds (A2a), sync+availability (A2b), pricing cost-sync + registrar-first/CSV import +
    `domain_registrar_connections` στο **sealed** export bucket (A2c — ΟΧΙ πια INTENTIONALLY_EXCLUDED).
    Εκκρεμεί από το A2 μόνο το live validation με production credentials.
    (Deferred P2, review per-domain-contacts: μετά το «Συγχρονισμός» στο ViewDomain, τα
    relation managers Επαφές/NS της ανοιχτής σελίδας ΔΕΝ ξαναρεντάρουν μόνα τους — το toast
    λέει «Ενημερώθηκαν επαφές» αλλά ο πίνακας δείχνει τα παλιά rows μέχρι reload/interaction.
    Pre-existing και για τα NS από το A2b. Fix = dispatch refresh event στα RMs από το action·
    θέλει έλεγχο σε πραγματικό browser, όχι εικασία — μαζί με το επόμενο UI slice.)
    (Deferred P2, review A2c-3: τα `importConnections`/`importDomainConnections` —και τα
    exporter αδέρφια τους— μοιράζονται ~40 γραμμές match-or-create/seal logic σε δύο αντίγραφα·
    extraction σε κοινό helper όταν έρθει ο ΤΡΙΤΟΣ sealed πίνακας —τα Support mailbox creds,
    ήδη σημειωμένα στο INTENTIONALLY_EXCLUDED— rule of three, όχι πριν.)
    Επίσης (deferred P2, review r3 2026-09-07): **IDN/punycode validation στο sld** — το
    maxLength(63) μετρά unicode chars ενώ το DNS όριο είναι 63 octets του A-label, και το
    `\p{N}` δέχεται μη-ASCII ψηφία· ο σωστός έλεγχος (idn_to_ascii + strlen) μπαίνει μαζί
    με το availability/registry validation του A2 (εκεί απορρίπτεται τελικά έτσι κι αλλιώς).
    Επίσης (deferred P2, review A2c-1 2026-09-07): **επαλήθευση σε live OP creds του
    min-term quoting** — το cost-sync γράφει το κόστος στη γραμμή του ελάχιστου term
    (`max(1, min_years)`) με την υπόθεση ότι το `GET /tlds/{name}?with_price=true`
    κοστολογεί την ελάχιστη registrable περίοδο· αν το OP κοστολογεί per-year, TLD με
    min_years>1 παίρνει μισό κόστος. Δεν δαγκώνει σήμερα (τα OP-routed TLDs του tenant
    είναι όλα min_years=1 — τα .gr πάνε grEPP/manual), αλλά τσεκάρεται στο go-live του
    A2 με πραγματικά credentials πριν εμπιστευτούμε κόστη πολυετών TLDs.
    Επίσης (review A2c-1 r3, ρητές αποφάσεις — ΟΧΙ bugs): (1) TLD που ο registrar
    επίμονα δεν κοστολογεί (κανένα reseller quote) βγάζει FAILURE σε κάθε run —
    ΣΚΟΠΙΜΑ loud· αν εμφανιστεί στην πράξη, το silencing είναι per-TLD «skip pricing
    sync» flag (μικρό migration). (2) Το currency-mismatch warning φωνάζει σε κάθε
    run και για το δικό του disabled artifact row (π.χ. quote USD→EUR και πίσω) —
    ο operator σβήνει το αχρησιμοποίητο row από τα «TLDs & τιμές» και σωπαίνει·
    προτιμήθηκε από one-shot warning που χάνεται. (3) Το extra exists() ανά operation
    (αντί για ένα in-memory fetch ανά TLD) — declined, ~8 μικρά queries/TLD σε
    χειροκίνητο run δεν αξίζουν το refactor.
  - **A3** Openprovider write — **A3a (renew) ✅ + A3b (register) ✅ + A3c (transfer-in +
    EPP code) ✅ + A3d (NS/lock/privacy/contacts writes + redemption restore) ✅ SHIPPED**
    (βλ. `FEATURES.md §21`). Το δεσμευτικό skeleton-extraction του A3c r1 έγινε ΜΕ το A3d:
    trait `GuardsRegistrarWrites` (logger/refuse/adapter-resolve/claimedElsewhere/lock) — και
    τα ΤΕΣΣΕΡΑ write services τρέχουν πάνω του. Εκτός v1 (συνειδητά): **DNSSEC key
    management** (το `dnssec_enabled` mirror μένει read-only — το key-material UX θέλει
    σχεδιασμό: add/remove DS/DNSKEY, validation, ρίσκο να σπάσει resolution με λάθος digest)·
    **restore billing** (η επαναφορά χρεώνει τον πελάτη ΧΕΙΡΟΚΙΝΗΤΑ v1 — δεν κόβει invoice
    μόνη της· αν φανεί συχνό, hook στο invoice flow όπως το renew)· approve-transfer/
    resend-FOA (αν φανούν χρήσιμα live). **P2 από το A3d gate r1 (deferred με λόγο):** αν το
    `sync->apply()` (τοπικό DB transaction) σκάσει ΜΕΤΑ από αποδεκτό/χρεωμένο registrar write,
    δεν γράφεται ΚΑΝΕΝΑ audit row (ούτε ok ούτε failed) — ισχύει ομοιόμορφα και στα τέσσερα
    write services (προϋπήρχε σε renew/register/transfer)· recovery ασφαλές (retry → sync-first
    adopt, μηδενική χρέωση), οπότε είναι κενό ΙΧΝΟΥΣ, όχι χρήματος. Fix μαζί για και τα 4
    (ok-row πριν το τοπικό apply, ή wrap του apply ώστε αποτυχία τοπικής εγγραφής να λογκάρει
    το αποδεκτό write) — μαζί με τον A5 reconciler που έτσι κι αλλιώς ξαναδιαβάζει τα ok-logs.
    **P2 από το A3d gate r2 (pre-existing A2, deferred):** το READ path (`DomainSyncService::sync`
    → by-name resolve) ΔΕΝ έχει cross-tenant guard — ένα sync σε fqdn που άλλος tenant έχει
    claimed στο κοινό reseller account υιοθετεί id/λήξη/NS/contact handles του άλλου tenant στο
    δικό μας row (truth/handle leak, ΟΧΙ χρήματα — κάθε write μετά αρνείται με το δικό του
    sweep). Συγγενές με το υπάρχον «claimedElsewhere over-blocks» item (σ) — λύσιμο μαζί:
    ποιο registrar account + guard και στο read-adopt. P2 από A3c r2 (wholesale): (ρ) το tombstone-flag gate μετρά ΚΑΙ
    pre-flight refusals ως «αίτηση» (false ⚠ σε previous-life tombstone + ένα refused click·
    αντίστροφα panel-started FAI χωρίς κανένα log δεν φλαγκάρεται)· (σ) το claimedElsewhere
    over-blocks (αγνοεί ΠΟΙΟ registrar account + withTrashed ξένα rows μπλοκάρουν «πελάτης
    μετακόμισε μεταξύ των εταιρειών μας» — fails safe/loud)· (τ) scrub evasion σε auth codes
    με «"»/«\» μέσω του JSON-escaped raw-body fallback (σπάνιο· scrub και το trimmed form). Για τον A5 reconciler (μαζί με τα υπόλοιπα A3):
    (α) re-evaluate των renew logs με `short_of_target=null` (το post-renew re-fetch απέτυχε —
    η πραγματική λήξη ήρθε από το nightly sync μετά) και όσων `ok` έμειναν κάτω από το
    `target_expiry` τους· (β) orphan unconsumed button-renewals που δεν τιμολογήθηκαν ποτέ.
    Επίσης P2 από το A3a gate r5 (wholesale): (γ) στο periodStart-null branch το stamping
    window (baseline=expires_at) ανοίγει νωρίτερα από το intent window (today) σε ληγμένα
    domains — ευθυγράμμιση σε έναν υπολογισμό· (δ) overshoot claim (3yr log σε biennial
    invoice) δεν καταγράφει το πλεόνασμα πουθενά — ο reconciler να το εμφανίζει· (ε) τα
    repeated refusal FAILED rows (spam του κουμπιού σε locked domain) είναι θόρυβος στο §9
    history + ένα INSERT-throw στο refusal path αντικαθιστά το typed exception (το
    DomainRenewalInProgress handling παρακάμπτεται) — wrap σε try/catch. Από r6 (GREEN):
    (στ) `max(1,…)` στο date-adopt stamping vs `max(0,…)` στο intent-match — ενοποίηση σε
    helper (χωρίς money συνέπεια, το max(1) σφάλλει προς όφελος του reconciler)· (ζ) εξωτικό:
    refusal γραμμένο σε re-issue μετά από ΔΥΟ αλλαγές κύκλου μπορεί να αποθηκεύσει
    mis-stepped baseline που σκιάζει το πρώτο — αυστηρά λιγότερο λάθος από το pre-r5.
    Και P2 από το A3b gate r2 (wholesale): (η) το cross-tenant claim guard μετρά ΚΑΙ trashed
    ξένα rows — ένα διαγραμμένο domain άλλης εταιρείας μπλοκάρει για πάντα το register εδώ
    (συντηρητικό μπλοκάρισμα = ασφαλής πλευρά· ξεμπλοκάρεται με force-delete ή χειροκίνητα)·
    (θ) το step-0 in-flight guard κάθεται ΜΕΤΑ τα NS/contact refusals — in-flight row με
    αδειασμένο NS σφηνώνει σε guard που το adopt δεν χρειάζεται· (ι) το adopt σφραγίζει
    registered_at=today ενώ το OP payload κουβαλά creation_date που το syncResultFrom πετά —
    panel-registered/παλιές υιοθετήσεις παίρνουν λάθος ημερομηνία (add registeredAt στο
    DomainSyncResult όταν χρειαστεί αλλού). Και από r3 (wholesale): (κ) refusal-only logs
    κοστίζουν ένα extra probe GET σε κάθε retry· (λ) μη-ευρωπαϊκά 3ψήφια CCs (+971/+212/+880)
    mis-split στο 2ψήφιο default (τα ψηφία διατηρούνται)· (μ) `previous_registrar_domain_id`
    είναι single slot που overwrite-άρεται (ίδιο pattern και στο DomainSyncService)· (ν)
    residual race δευτερολέπτων: instant retry ενώ ο OP ακόμα επεξεργάζεται >30s timed-out
    POST (χωρίς idempotency key στο OP API — cool-down μετά από timeout-flavored failure
    θα το στένευε)· (ξ) trim-vs-'' predicate consistency στα id checks. Από r5 (GREEN):
    (ο) shared-account edge: no-id row του οποίου το ληγμένο όνομα ξανα-καταχωρήθηκε από
    ΑΛΛΟΝ reseller-client στον ίδιο OP λογαριασμό → το by-name πιάνει το ξένο ACT (πάντα
    account-scoped ambiguity· το nightly sync παγώνει Deleted πριν το rebuy στην πράξη)·
    (π) το register() re-wrap πετά το $base->deadRecord (αδρανές — μόνο το adopt το διαβάζει).
  - **A4** 2ος registrar **grEPP** (.gr/.ελ direct EPP· 2ετία min, no privacy/lock) — αποδεικνύει το abstraction.
  - **A5** polish — bulk availability search, portfolio dashboard, **registrar↔local
    reconciliation** (mirror myDATA reconcile).
- **Πυλώνας B — Payment gateways** → `payment-connectors.md` (IRIS πρώτα· card-POS/Stripe μετά)· πριν το portal.
- **Πυλώνας C — Provisioning modules** → seam `app/Contracts/ProvisioningModule.php` ήδη (βλ. «Services / Provisioning» κάτω).
- **Πυλώνας D — Customer portal** — **foundation ✅ SHIPPED** (identity/grants/reset/session-security +
  προβολή παραστατικών + «Η καρτέλα μου», όλα read-only, στο `/user`). ΔΕΝ ήταν τελικά μονολιθικά «τελευταίο»:
  η foundation δεν χρειαζόταν A/B/C. Μένουν τα **transactional** surfaces (πλήρωσε → B· domains → A·
  services → C), που προσκολλώνται ανά πυλώνα. Βλ. `PLAN.md §6`.
- **Πυλώνας E — Support / Ticket system** _(NEW, TODO-eval — μας ξέφυγε από τον epic)._ WHMCS-style
  υποστήριξη: departments, tickets (public replies + **internal notes** + attachments), statuses/priority,
  ticket↔customer/service link, canned replies, SLA, ratings, **email ingestion** (IMAP poll + reply/quote/
  signature strip + threading + outbound). Δύο κόσμοι UI όπως παντού: operators στο Filament panel, πελάτες
  στο **Flux portal** (`/user`) — άρα ό,τι διαλέξουμε πρέπει να αφήνει και τα δύο δικά μας.
  **Buy-vs-build (2026-09-06 eval):**
  - ❌ `rasmuscnielsen/laravel-support-tickets` (packalyst) — Laravel 5.x εποχής, εγκαταλελειμμένο. Drop.
  - ⭐ `jeffersongoncalves/laravel-service-desk` (MIT, Laravel 11/12/13, **headless**) — ο σοβαρός
    υποψήφιος: πλούσιο μοντέλο (departments/statuses/operators/internal-notes/SLA/KB/service-catalog/
    email Mailgun+SendGrid+Resend+Postmark+IMAP). Headless = κρατάμε Filament+Flux δικά μας. Καμπάνες:
    **νέο/μικρό (~8★)**, **όχι multi-tenant** (θέλει `company_id`/`CompanyScope` retrofit).
  - Filament-only plugins (Umnidev Helpdesk, Padmission, Creators Ticketing, `jeffersongoncalves/
    filament-help-desk`, το επί-πληρωμή) → UI μόνο για operators· ΔΕΝ λύνουν το customer-facing Flux —
    μόνο αν αποφασίσουμε tickets = operator-only (απίθανο, WHMCS έχει customer tickets).
  - **Build-our-own thin model** = viable (έχουμε ήδη CompanyScope/activitylog/Flux/mail)· το μόνο
    ακριβό κομμάτι είναι το email ingestion/threading, που δανειζόμαστε (`webklex/php-imap` +
    `willdurand/email-reply-parser`). Πιθανώς το καθαρότερο long-term δεδομένου πόσο δένουν τα tickets
    με customer/service/company.
  - **Πλήρης ανάλυση + απόφαση → `docs/ticket-system-eval.md`** (2 στρώματα: ticket domain + mail
    ingestion). **Σύσταση:** **build-our-own thin model** (native multi-tenancy/Filament/Flux) +
    δανεικό mail layer — **`webklex/php-imap`** (poll το δικό μας `mail.myip.gr`, όχι provider webhook
    αφού έχουμε δικό μας mail) + **`willdurand/email-reply-parser`** (καθάρισμα quoted/signature) —
    με το `laravel-service-desk` ως **MIT design reference** (schema + state machine), όχι dependency.
  - **Επόμενο βήμα:** time-boxed **spike (1-2 μέρες)** — (α) διάβασε migrations/state-machine/IMAP poller
    του `laravel-service-desk` + μέτρησε το tenancy-retrofit κόστος, (β) απόδειξε IMAP poll στο
    `mail.myip.gr` + reply-parse. Αποτέλεσμα → adopt (αν φθηνό retrofit) ή build (πιθανό).
  - **Συγκεκριμένο design (schema + state machine + mail flow + 2 UIs + PR breakdown) →
    `docs/ticket-system-design.md`** (build-our-own, thin 6-table domain, reuse των υπαρχόντων
    `HasAttachments`/`HasTags`/`TracksActivity`, Support Cluster + config στο Settings Cluster).
  - **Portability (deferred):** τα ticket tables είναι `INTENTIONALLY_EXCLUDED` από το per-tenant
    `CompanyExporter` bundle μέχρι να γραφτεί/τεσταριστεί το FK-rewiring (ticket→customer/department/
    assignee, message→ticket, encrypted per-mailbox creds). Τα whole-DB backups τα καλύπτουν ήδη.
  - **P2 (review #496, deferred):** το nav-badge queue-count και το «Στην ουρά» tab badge τρέχουν το
    ίδιο `COUNT` ξεχωριστά ανά render της λίστας — δύο πανομοιότυπα counts. Αμελητέο· ένωσέ τα αν ποτέ
    γίνει hot. Follow-up UI: canned-reply picker (token expansion) + context panel (τιμολόγια/καρτέλα inline).
  - **P2 (review #501, deferred):** το `SendTicketReplyEmail` δεν έχει πλήρες resend guard — ένα
    retry μετά από επιτυχή αποστολή (worker died πριν το ack) ή double-dispatch μπορεί να ξαναστείλει
    το ίδιο reply email. Χαμηλό impact (διπλή απάντηση, όχι invoice). Το πλήρες κλείσιμο θέλει το
    at-most-once state machine του `SendInvoiceEmail` (OPS-12: sending/sent + send_key)· άξιο μόνο αν
    γίνει πρόβλημα στην πράξη.
  - **Phase 4 SHIPPED:** operator **bell** + **watchers/CC**, **feedback-on-close** (rating),
    **spam/block-sender**, **inbound-CC capture**, **ticket merge** (same-owner· source→Closed+`merged_into_id`·
    πύλη hide+redirect). **Πυλώνας E core = DONE.** **SLA timers σκόπιμα εκτός** (δικό του slice)· ανοιχτά μόνο
    follow-ups (email-invite στο κλείσιμο, reply-threading για watcher/CC). **In-app KB: DROPPED** — το
    **BookStack** (external) καλύπτει ήδη το knowledge base, δεν χτίζουμε δικό μας. **Announcements:** maybe-later,
    low-prio (όχι τώρα).
    **Deploy σημείωση:** νέο resource «Αποκλεισμένοι αποστολείς» → `shield:generate`
    + re-provision μετά το deploy (όπως κάθε νέο resource perm).
  - **P2 (review Phase-4 spam, deferred):** ένα block ρίχνει ΟΛΑ τα εισερχόμενα του αποστολέα — και reply
    πάνω σε **ήδη ανοιχτό** ticket. Ένα domain-block μπορεί έτσι να «καταπιεί» σιωπηλά απάντηση ενός νόμιμου
    συναδέλφου στο ίδιο domain. Deliberate (blocklist = πλήρης αποκλεισμός· full-email block είναι ακριβές,
    domain block είναι blunt & προειδοποιείται στη φόρμα). Εναλλακτική αν πονέσει: soften σε «new/reopen only»
    (επίτρεψε reply σε ανοιχτό ticket που ανήκει στον αποστολέα)· + ίσως «blocked» counter στο `TicketPollRun`
    για ορατότητα. Επίσης: το domain-block είναι **exact** (`bad.gr` δεν πιάνει `x@mail.bad.gr`)· subdomain
    matching (έλεγχος και των parent domains) αν χρειαστεί.
  - **P2 (review Phase-4, deferred):** η λίστα watchers στο ticket infolist (`RepeatableEntry` πάνω στη
    σχέση `watchers`) κάνει lazy-load το `user` ανά γραμμή (`label()`) + ένα ξεχωριστό `exists()` για το
    visibility → N+1 / διπλό query. Αμελητέο (ένα ticket έχει λίγους watchers)· eager-load + `isNotEmpty()`
    αν ποτέ γίνει hot.
  - **P2 (review Phase-4, deferred):** το operator bell (`TicketNotifier::notifyNewCustomerMessage`)
    τρέχει **σύγχρονα** στο afterCommit του poller και, όταν ένα τμήμα δεν έχει agents, γράφει
    `sendToDatabase` σε **ΟΛΟΥΣ** τους χρήστες του tenant ανά μήνυμα → O(μηνύματα × χρήστες) inserts στο
    hot path ενός poll. Αμελητέο στα σημερινά μεγέθη (λίγοι operators/tenant)· αν μεγαλώσει ένας tenant με
    agent-less τμήματα, βγάλε το bell σε queued job.
  - **feedback-on-close follow-ups (deferred):** το rating γεμίζει από την πύλη. Follow-ups: (α) **email-invite**
    στο κλείσιμο (mailable με link στην αξιολόγηση) για πελάτες που δεν ξαναμπαίνουν στην πύλη· (β) **στήλη/
    φίλτρο** αξιολόγησης στη λίστα tickets + απλό «μέσος όρος ικανοποίησης» metric. Το core (portal rating +
    operator badge) SHIPPED.
  - **P2 (review feedback-invite, deferred):** το «Κλείσιμο» στέλνει invite σε κάθε close-cycle ενός
    **αβαθμολόγητου** ticket — close→reopen→close ξαναστέλνει «αξιολόγησε» email. `isRated()` κόβει μόνο το
    re-nag αφού υπάρχει βαθμός. Πλήρες dedupe θέλει marker (`feedback_invited_at`, καθαρίζεται στο reopen)·
    χαμηλό impact. **Declined:** το re-rate μέσω του signed link είναι σκόπιμο (συνέπεια με την πύλη· misclick
    fix) — πλέον bounded από το 30-day expiry του link.
  - **Conscious tradeoff (review Phase-4 r3):** το participant auto-watch λύνει τον operator μέσα από
    το `company->users()` pivot (ίδιο tenant invariant με το «Προσθήκη watcher»). Συνέπεια: ένας operator
    **εκτός pivot** (π.χ. super_admin που απαντά cross-tenant χωρίς membership row) δεν auto-watch-άρεται —
    μπορεί να κάνει watch χειροκίνητα, και το bell ούτως ή άλλως φτάνει στους agents του τμήματος. Προτιμήθηκε
    το tenant-scope invariant από το βολικό (global `User::find`).
  - **Inbound-CC → watcher auto-capture (SHIPPED):** τα `To`/`Cc` ενός εισερχόμενου email γίνονται email
    watchers (`source=cc`, εξαίρεση αποστολέα/τμήματος/owner/From, idempotent). **(α) reply-threading για
    watcher/CC αποστολείς = SHIPPED** — ο `senderOwnsTicket` δέχεται πλέον και τους email-watchers του ticket
    (anti-injection guard μένει: μόνο πραγματικοί watchers, όχι όποιος έχει το token). **Conscious tradeoffs:**
    (i) το ticket_messages δεν έχει sender column → η απάντηση watcher μπαίνει ROLE_CUSTOMER με prefix «(από
    email)» ώστε να μη μπερδεύεται με τον πελάτη· καθαρότερο θα ήταν sender/participant identity στο μήνυμα.
    (ii) το inbound εμπιστεύεται το From (χωρίς SPF/DKIM), οπότε το threading trust επεκτείνεται από τον owner
    στη (customer-controllable) watcher list — ίδια κλάση ρίσκου με το υπάρχον requester==From. (iii) απάντηση
    watcher σε κλειστό ticket το ξ-ανοίγει (WHMCS-consistent). **(β) visible CC αντί Bcc για cc-sourced =
    SHIPPED** — οι cc-sourced watchers μπαίνουν σε ορατό Cc στις απαντήσεις, οι manual μένουν Bcc. **Επίσης
    SHIPPED:** HTML-body strip στο inbound (`HtmlToText` για HTML-only emails). **Remaining refinements
    (deferred):** (γ) **perf:** το CC-capture καλεί
    `isBlocked` ένα query ανά recipient — για μεγάλη CC-λίστα φόρτωσε το blocklist μία φορά in-memory
    (αμελητέο στα σημερινά μεγέθη). (δ) **catch-all alias:** εξαιρούμε `email` + `imap_username` του τμήματος·
    ένα τρίτο alias/catch-all address δεν εξαιρείται (self-loop churn)· θέλει ρητό πεδίο aliases αν εμφανιστεί.
  - **Συνημμένα ticket — PR A (portal + operator) SHIPPED / PR B (email) deferred.** **PR A (SHIPPED):**
    ο πελάτης ανεβάζει από την πύλη (open/reply), ο χειριστής από το panel (reply/note)· links λήψης στο νήμα.
    Security: ιδιωτικός δίσκος, **download-only** (`Content-Disposition: attachment`, ποτέ inline), allowlist
    τύπων (όχι scripts/HTML/SVG/exe), τυχαίο όνομα, escaped filename, tenant/grant-scoped, internal-note
    attachment invisible στην πύλη (`publicOnly`). `App\Support\TicketAttachments` + routes/controllers.
    **PR B (SHIPPED):** (α) **inbound** — ο IMAP poller εξάγει τα πραγματικά (μη-inline) attachments εισερχόμενου
    email → `TicketAttachments::storeInbound` (extension allowlist, per-file 20MB, count 5, per-email total 25MB·
    ΔΕΝ trust το Content-Type, ποτέ decompress, inline parts αγνοούνται· διπλή επέκταση πιάνεται από το
    `pathinfo` extension check). (β) **outbound** — `TicketAttachments::outboundPayload` + `TicketReplyMail::
    attachments()`, all-or-nothing στο 25MB budget (αλλιώς reply χωρίς αρχεία + log warning). **Remaining
    (deferred):** (i) **AV-scanning** — δεν σκανάρουμε συνημμένα (ClamAV/`clamdscan` seam)· το μοντέλο μας είναι
    download-only + allowlist (κανένα execution), αλλά ένα malicious έγγραφο μπορεί να κατέβει· χαμηλή προτεραιότητα.
    (ii) **disk-DoS**: 50 msgs/poll × 25MB = ~1.25GB/poll worst-case από spam· φράγμα σήμερα = blocklist + clients_only
    + `ops:health` disk monitor· per-tenant quota αν εμφανιστεί abuse. (iii) **operator UI feedback στο outbound
    drop (P2, review PR B):** όταν τα συνημμένα μιας απάντησης δεν σταλούν όλα (πάνω από το 25MB budget → all-or-nothing,
    ή κάποιο file λείπει από τον δίσκο → skip), η απάντηση φεύγει χωρίς αυτά με μόνο `Log::warning` (καταγράφει stored vs
    attached) — ο χειριστής δεν ειδοποιείται στο UI (τα αρχεία μένουν ορατά/κατεβάσιμα στο thread, οπότε δεν χάνονται).
    Full feedback θέλει async notification πίσω στον χειριστή (queued job → bell)· χαμηλή προτεραιότητα.
    (iv) **webklex giant-part memory — SHIPPED (poller hardening).** Ο poller φέρνει πλέον headers-only και
    κατεβάζει το σώμα ένα-ένα (`fetchBody(false)` + `parseBody()` per message → peak μνήμη = 1 μήνυμα αντί για ΟΛΑ
    τα 50 unseen μαζί). Μήνυμα πάνω από 40MB RFC822 (`WebklexImapMailbox::isMessageTooLarge`, έλεγχος `RFC822.SIZE`
    πριν το body download) → **δεν κατεβαίνει· ανοίγει stub ticket με μόνο headers** (placeholder body, χωρίς
    συνημμένα) ώστε να μη χαθεί σιωπηλά το αίτημα ούτε να γίνει OOM/poison loop· failed size-probe → επίσης stub
    (ποτέ parse ενός μη-μετρήσιμου μηνύματος «στα τυφλά»). **Deploy req:** το poll/worker process θέλει `memory_limit` ≥ 256M (parse μηνύματος ~cap peaks
    σε few× wire size). Ο walk γίνεται `chunked(…,1)` (peak = 1 μήνυμα, verified), με `finally` disconnect ώστε να μη
    διαρρέει socket αν σκάσει. **Remaining (χαμηλή προτ., review):** (α) **round-trips:** το chunk-size-1 κάνει
    header fetch ανά μήνυμα (≤50 sequential) αντί για ένα batch — latency σε high-RTT mailbox (efficiency, όχι
    correctness). (β) **poison message (pre-existing):** ένα μήνυμα που το webklex ΔΕΝ μπορεί να parse-άρει σε
    fetch/make (π.χ. malformed Date header, `soft_fail=false`) πετά GetMessagesFailedException που σταματά το poll·
    το μήνυμα μένει unseen → ξανα-μπλοκάρει το επόμενο poll (τα από πίσω δεν φτάνουν). Ίδιο και πριν με το παλιό
    `->get()`. Fix = per-message fetch isolation ή `soft_fail=true` + iteration guard. (γ) **soft_fail infinite
    loop:** αν κάποτε ενεργοποιηθεί `soft_fail`, το `chunked` do-while μπορεί να γίνει infinite (dropped message →
    handled δεν φτάνει available)· μη-reachable στο σημερινό default (soft_fail=false)· θέλει per-poll iteration cap
    αν ποτέ αλλάξει. (δ) dead-letter folder αντί για stub, per-tenant disk quota.
  - **Holistic Support review (peace-of-mind, 2026-09-07) — NO P0/P1· P2 dispositions.** Ολόκληρο το
    subsystem reviewed· τα core invariants (tenant isolation, blocklist→idempotency→match→ownership, attachment
    gating, escaping) κρατάνε. **Fixed αμέσως:** removeWatcher action (stop replies σε ανεπιθύμητο CC — disclosure),
    MergeTickets attachment re-parent `withoutGlobalScope`, honest oversized-stub placeholder. **Deferred
    (P2):** (0) **«ticket ανά τμήμα» για email σε πολλά τμήματα:** σήμερα το idempotency είναι company-wide (σωστό
    για το «seen this message-id;»), οπότε ένα email σε dept-A + dept-B ίδιας εταιρείας δίνει ΕΝΑ ticket. Το «ένα
    ticket ανά τμήμα» ΔΕΝ είναι dedup tweak — θέλει department-scoped `matchTicket` + merge μαζί (αλλιώς reply-all
    ξανα-collapse-άρει, και redelivery μηνύματος που threaded/merged σε άλλο τμήμα διπλασιάζεται)· design change, χαμηλή
    προτ. (δοκιμάστηκε per-department dedup, έγινε revert γιατί εισήγαγε duplicate-on-redelivery). (α) **ambiguous customer match — FIXED (2026-09-08).** Σε ΜΗ-μοναδικό email (≥2 πελάτες ίδιας
    εταιρείας) ο router πλέον ΔΕΝ κάνει auto-bind (`matchCustomers`, limit 2 → bind μόνο σε single match)· ανοίγει
    αδέσμευτο ticket + internal note flag· ο χειριστής συνδέει με «Σύνδεση πελάτη» (ViewTicket, tenant-scoped). Το
    clients_only δέχεται τον ασαφή (γνωστό) αποστολέα, ρίχνει μόνο τον πραγματικά άγνωστο. (β) **systematic `getSize()`
    failure:** αν ένας server ΔΕΝ υποστηρίζει RFC822.SIZE σε headers-only fetch, ΟΛΑ γίνονται stubs· σπάνιο (RFC822.SIZE
    ~universal) + retry καλύπτει transient· fix αν πονέσει = εναλλακτικό size path ή bounded body. (γ) **`$authorNameCache`
    unbounded static** (TicketInfolist) — Octane-only memory growth + stale names· harmless σε FPM (locked). (δ)
    **dead config — HIDDEN:** τα `autoresponder`/`prevent_client_closure` toggles ΑΦΑΙΡΕΘΗΚΑΝ από το
    TicketDepartmentForm (2026-09-07) γιατί καμία ροή δεν τα διαβάζει (παραπλανούσαν — `autoresponder` default ON
    χωρίς αποστολή). Οι στήλες μένουν· θα ξαναμπούν όταν υλοποιηθεί η συμπεριφορά (auto-ack στο άνοιγμα· φραγή
    πελάτη-close). (ε) **`syntheticId` first-300-chars** για no-Message-ID mails (pre-existing, comment-acknowledged).
- **Menu / Information Architecture — πριν πληθύνουν οι πυλώνες** _(NEW, epic-wide· ήδη πιεστικό)._
  **Πλήρης στόχος-χάρτης (κάθε σημερινό screen + μελλοντικό, mapped) → `docs/menu-ia.md`.** Το nav
  είναι μόνο αριστερά (Filament), ήδη **~59 items** (31 Resources + 28 Pages) σε **9 groups** με τη
  «Ρυθμίσεις» στα **11**. Με Support (Tickets/Departments/Settings) + μελλοντικά Services/Domains/Servers/
  Groups/Provisioning γίνεται «πάπυρος». **Κατεύθυνση: βάθος (Clusters), όχι πλάτος (flat groups)·
  panel-split μόνο όταν αλλάζει ο ρόλος/κοινό:**
  - **Cluster ανά domain** (το pattern υπάρχει ήδη — `App\Filament\Clusters\MyDataCluster`): Support →
    ένα top-level «Υποστήριξη» με δικό του sub-nav (Tickets/Departments/Settings μέσα). Domains/Provisioning
    ομοίως → κάθε πυλώνας = ΕΝΑ entry, όχι 5-6.
  - **«Ρυθμίσεις» (τα 11) → Settings Cluster** (WHMCS «Configuration» / Blesta «Settings» pattern) — τα
    config/lookups φεύγουν από το καθημερινό nav.
  - **2ο Filament panel** (με switcher) **μόνο** όταν το κοινό διαφέρει (π.χ. infra/provisioning ops ≠
    billing operator) — cross-panel tenant-context = extra plumbing, όχι νωρίτερα.
  - Στήριξη σε **global search (Cmd+K)** ώστε το βάθος να μη βλάπτει findability.
  - **Επιβεβαίωση από το ίδιο το WHMCS** (screenshots 2026-09-06): χωρίζει **Configuration** (Support
    Departments/Ticket Statuses/Escalation/Spam κάτω από το «Configuration» sidebar) από τα **operational**
    Support (Tickets/Predefined Replies/KB κάτω από το top «Support» μενού) — ακριβώς το Settings-Cluster
    split. Λεπτομέρειες στο `docs/ticket-system-eval.md` «WHMCS parity».

## 🟢 Services / Provisioning
- **Real provisioning modules** (cPanel/Mailcow/license server) — σήμερα μόνο `NullProvisioningModule`. _(= Πυλώνας C του `PLAN.md`.)_
- **Multi-line service contracts** — v1 = single-line.
- **Non-fiscal «προτιμολόγιο» series (ΠΡΟΤ) — WHMCS-style freedom (2η φάση, δένει με portal + gateway + recurring).**
  Μια σειρά/invoice-type με **filing OFF** (`submitsElectronically()` → false) που ΠΟΤΕ δεν φεύγει σε ΑΑΔΕ/πάροχο:
  ο operator μπορεί να τη μαρκάρει «πληρωμένη» ή να την αφήσει ανοιχτή ελεύθερα, χωρίς νόμιμο έγγραφο — το μοντέλο
  «φίλοι/reference υπηρεσίες που δεν πληρώνουν ποτέ, αλλά θέλω να ξέρω τι έχασα» (what-if, χωρίς να το κάνω «0/free»).
  Στο portal γίνεται ο «λογαριασμός σου»: recurring auto-issue ΠΡΟΤ → ο πελάτης βλέπει/επιλέγει ανανέωση/upgrade/
  downgrade/ακύρωση → πληρωμή (gateway) → **convert σε νόμιμο τιμολόγιο** με τον τρόπο πληρωμής → mark paid → money
  trail. Η αρχιτεκτονική το σηκώνει (`local_status` ⟂ `mydata_state`)· η «Καρτέλα μου» το εμφανίζει αυτόματα (ίδιο
  `CustomerLedgerBuilder`). Θέλει και τη numbering απόφαση κάτω ↓.
- **Pro-forma numbering (draft = προτιμολόγιο)** — σήμερα ο ΑΑ εκχωρείται στη ΔΗΜΙΟΥΡΓΙΑ (`InvoiceNumberer`, `CreateInvoice`), οπότε κάθε draft «καίει» έναν αριθμό της νόμιμης σειράς τιμολογίων → gap αν διαγραφεί/δεν πληρωθεί. Για service-manager pro-forma ροή (στέλνεις πολλά προτιμολόγια, πληρώνονται κάποια) χρειάζεται **δικό τους reference**: είτε ξεχωριστός μετρητής «ΠΡΟΤ-N» (ο πραγματικός ΑΑ μπαίνει στην έκδοση/πληρωμή), είτε το σταθερό `id`. Σημαίνει μετακίνηση εκχώρησης ΑΑ create→issue (ο `InvoiceNumberer` συνειδητά το απέφυγε — τεκμηρίωση εκεί). Το immediate «ο πελάτης χρειάζεται αριθμό-αναφορά» ΗΔΗ καλύπτεται (το draft έχει `invcode` + banner «ΠΡΟΧΕΙΡΟ» στο PDF). Surfaced από το MON-5.

## 🟣 WHMCS loose ends (βλ. `whmcs-legacy-plugin-map.md`)
- **T-3 cutover** — legacy timologia → ekdosi Customers (match `gr_vatno`, upsert, back-ref).
- **Multi-party SPLIT write-back** στο WHMCS (ένα MARK ≠ N invoices).
- **T-4 manual split tools** (transfer_invoice / relid_remover) — χαμηλή προτεραιότητα.
- **«All of a client's third parties» 2ο dropdown** (θέλει `contacts-by-userid` bridge endpoint).
- **«Εκδοθέντα Παραστατικά» — πλήρες pagination (P2, από review)** — το `issued-for-client` endpoint επιστρέφει
  bridge-derived (capped **500** newest) + historical (capped **500** newest· τα δικά του WHMCS ids capped **2000**
  newest στο plugin) σε έναν αδιαίρετο client-area πίνακα. Για reseller με χιλιάδες τιμολόγια θέλει σελιδοποίηση
  (endpoint `limit`/`cursor` + plugin UI)· τα caps αποτρέπουν το pathological memory/latency αλλά **σιωπηλά κόβουν**
  παλαιότερα (>2000 WHMCS invoices ή >500 historical rows) — η σελιδοποίηση είναι follow-up.
- **Declined (από review): tenant-slug existence oracle στο `issued-doc-pdf`** — το tenant lookup προηγείται
  του signature check (404 vs 401), όπως σε ΟΛΑ τα sibling webhook controllers· τα slugs δεν είναι μυστικά και
  το πραγματικό auth (HMAC secret) δεν επηρεάζεται. Αφήνεται συνεπές με το υπάρχον pattern.
- **«Εκδοθέντα» — PDF proxy για historical (P2, follow-up)** — τα historical rows επιστρέφουν `has_pdf:false`
  (το GET `issued-doc-pdf` εξουσιοδοτεί μόνο μέσω pending rows, που τα pre-bridge δεν έχουν). Για proxied PDF
  και στα historical θα χρειαστεί POST variant του PDF endpoint που δέχεται τα δικά του WHMCS ids (ίδιο leak-proof
  boundary με τη λίστα). Σήμερα τα historical δείχνουν το verify link (για ΥΠΑΕΣ = η επίσημη προβολή) χωρίς PDF.
- **Declined (από review): `verify_kind` ανά-tenant, όχι ανά-invoice** — παράγεται από `company.einvoice_provider`,
  σωστό για single-provider tenant· μπερδεύει μόνο σε tenant που ΑΛΛΑΞΕ πάροχο με mixed ιστορικό (cosmetic label,
  ο σύνδεσμος δουλεύει). Per-invoice θα ήθελε `latestProviderMark()` = N+1. Αφήνεται.
- **Declined (από review): historical is_own fallback → «own» όταν ο reseller δεν έχει linked customer** — καθαρά
  cosmetic grouping (και τα δύο του ανήκουν, το party_name φαίνεται)· το «fix» (seed από pending.customer_id)
  ρισκάρει το αντίστροφο mislabel σε single-third-party. Default «own» = σωστό στη συνήθη περίπτωση (pre-bridge
  ιστορικό = κυρίως δικά του).
- **WHMCS-inbox resolver memoization (P2, από review)** — ο `WhmcsPaymentMethodResolver` (και ο δίδυμος
  `WhmcsIncomeClassifier`) χτίζονται per-`map()` call, οπότε preview+persist και κάθε split-party κάνουν
  ξεχωριστό query. Invoice-invariant → θα μπορούσαν να περνιούνται μία φορά από τον caller. Αμελητέο (ένα
  indexed query/κλήση)· κοινό pattern με το income map, γι' αυτό αφήνεται μαζί.
- **WHMCS gateway key με τελεία σπάει το Livewire `wire:model` binding (P2, από review)** — η σελίδα
  «Αντιστοίχιση WHMCS (πληρωμές)» κάνει `wire:model="choice.{gateway}"`· ένα gateway module name με `.`
  (ασυνήθιστο — τα WHMCS modules είναι `[a-z0-9_]`) θα γινόταν nested path. Θέλει index-based binding ή
  sanitised key. Χαμηλή πιθανότητα· άνοιξέ το αν εμφανιστεί τέτοιο gateway.
- **`customfields` ως scalar → TypeError στο `resolveWhmcsCustomField` (P2, από review — pre-existing)** —
  αν κάποιο WHMCS/bridge response έδινε ποτέ το `customfields` ως scalar αντί για array, το
  `array_is_list($scalar)` θα πετούσε TypeError. Κοινή παραδοχή όλου του parsing (`wantsInvoice`/`whmcsAfm`/
  matcher/`wantsImmediateInvoice`), **μη προσβάσιμη από κανένα πραγματικό feed path** (native + bridge δίνουν
  πάντα array). Στον γκρινιάρη-mirror το καταπίνει το best-effort `\Throwable` catch· στο create-seed
  (`WhmcsCustomerCreator`) όχι. Fix = ένας κοινός guard `is_array($customfields)` στην κορυφή του reader.

### Mass-pay consolidation — surviving P2s (από το adversarial review, 2026-09-15)
Το feature (CONSOLIDATE/EXPLODE ενός WHMCS συγκεντρωτικού) πέρασε **χωρίς reachable P0/P1** για τη σημερινή
διαμόρφωση (όλοι mainland-GR, net-per-line WHMCS, single 24%). Εφαρμόστηκαν οι φθηνές θωρακίσεις (child-rate,
per-line reconcile oracle, P0 double-fold guard **μέσα** στο tx με row-lock, explode reconcile fail-safe, tenant
assertion). Ό,τι απέμεινε:
- **Lifecycle/reversibility (P2-2):** μετά το CONSOLIDATE τα τέκνα μένουν `resolved` tombstones linked στο mass-pay.
  Αν ο χειριστής μετά (α) **ακυρώσει** το εκδοθέν ενοποιημένο, ή (β) **διαγράψει** το ακόμα-`pending_review`
  mass-pay row (`isDeletable` το επιτρέπει — δεν έχει invoice_id/mark· FK = `nullOnDelete`), τα τέκνα μένουν
  `resolved` χωρίς inbox path πίσω (`reStageAction` μόνο για REJECTED/HELD) — ανακτώνται μόνο με νέο WHMCS
  re-fetch, και το reconstructed breakdown χάνεται στη διαγραφή (`ekdosi_masspay_source` κρατά μόνο items+total).
  Fix: σε cancel του ενοποιημένου → re-open/allow re-stage των tombstones· κάνε το consolidated pending row
  **μη-διαγράψιμο** (route to reject). Θέλει lifecycle σχεδιασμό — χαμηλή πιθανότητα στη συνήθη ροή.
- **Auto-issue bypass (P2-5, judgment call):** μετά το merge `isConsolidatedPayment()` → false, οπότε ο
  consolidated-guard του `WhmcsAutoIssue::chooseType` δεν το κρατά· σε tenant που έχει armed
  `whmcs_auto_issue_immediate` ΚΑΙ ο πελάτης είναι `needs_immediate_invoice`, το ενοποιημένο **μπορεί** να
  αυτο-φιλαριστεί χωρίς το manual review. **Τα χρήματα είναι σωστά** (ισχύουν όλα τα file-guards) και είναι
  συνεπές με το ρητό opt-in του tenant στο auto-issue — γι' αυτό αφήνεται. Αν θέλουμε «manual issue μετά το
  consolidate», ο guard είναι one-liner: κράτα rows που φέρουν `ekdosi_consolidated_children` στο payload.
- **Reduced-rate flatten (P2-4, μη-reachable):** το ενοποιημένο stampάρει ΕΝΑ `taxrate` (το **max** child rate —
  βλ. round-2 review FINDING A: ήταν `child[0]` και ένα exempt-first τέκνο flatten-άριζε το rate σε 0· τώρα max),
  κι ο mapper το εφαρμόζει σε κάθε taxed γραμμή — μείξη 24%+13% θα έβγαζε λάθος per-rate ΦΠΑ (το 13% → 24%).
  Κανένας tenant με reduced rates σήμερα (mainland-only seeding)· exempt+taxed μείξη **είναι** σωστή (per-line
  `taxed` flag → 0% vs max rate). Ίδιος περιορισμός με τον single-rate mapper — άσε μέχρι να μπει reduced-rate tenant.
- **Text-only refs χωρίς relid (P2-7):** στο heuristic-text detection path (slimmed bridge feed, χωρίς per-line
  `relid`) `referenceGross=0` → το CONSOLIDATE αρνείται (reconcile fails). Fails safe (το EXPLODE δουλεύει —
  εκεί ο reconcile guard τρέχει μόνο όταν `referenceGross>0`). Fix: parse το child id από το description της
  reference γραμμής όταν λείπει το relid. Σπάνιο feed shape (το πραγματικό #32310 έχει relids).
- **Test gaps (deferred):** cancel/delete-after-consolidate lifecycle test (δεμένο με P2-2)· explode third-party
  routing (το explode test χρησιμοποιεί ένα userid)· tax-inclusive tenant test (δεμένο με το explode fail-safe·
  μη-reachable σήμερα)· concurrency test για το tombstone clobber (row-lock tests = MariaDB-only ανά CLAUDE.md).

## 💳 Paid/unpaid-aware WHMCS γέφυρα (αμφίδρομη) — epic
_Ιδέα 2026-07-13 (chrismfz). Money-sensitive· Phase 2 γράφει χρήμα στο WHMCS → design-first._

**Πρόβλημα:** σήμερα ο όρος πληρωμής του εκδοθέντος τιμολογίου βγαίνει **αποκλειστικά από τον
τύπο** (`WhmcsInvoiceMapper` → `payment_method_id = invoiceType->payment_method_id`)· το **WHMCS
paid/unpaid status αγνοείται** και **δεν καταγράφεται Payment**. Άρα ένα ΑΠΛΗΡΩΤΟ WHMCS τιμολόγιο
(π.χ. Α.Ε./Δημόσιο/Δημοτική που θέλει «πρώτα τιμολόγιο, μετά πληρωμή») που εκδίδεται κάτω από τον
(cash-term) default τύπο φαίνεται λανθασμένα **εξοφλημένο**, ενώ είναι πραγματική ανοιχτή οφειλή.
Το payload **έχει ήδη** `status`/`datepaid`/`balance` (τα διαβάζει το `CustomerWhmcsLedger`).

**Phase 1 — inbound — ✅ SHIPPED (v1.9.x):** WHMCS `status` από το payload → badge «Πληρωμή WHMCS»
στο inbox· νέα ρύθμιση **«Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ (επί πιστώσει)»** (`whmcs_default_unpaid_type_id`)·
το «Δημιουργία Παραστατικού» προ-επιλέγει τύπο βάσει status+πρόθεσης (`PendingWhmcsInvoice::suggestedInvoiceTypeId`),
override πάντα· tripwire status-aware (warn και για cash-term unpaid-slot). Auto-issue paid-only.
_(Το `datepaid`/`balance` snapshot δεν χρειάστηκε — το `status` αρκεί· read-on-demand από το payload.)_

**Inbound payment sync (WHMCS → ekdosi) — ✅ SHIPPED (v1.11.x):** `whmcs:sync-payments` (opt-in
scheduled) κλείνει την οφειλή στο ekdosi όταν ένα επί-πιστώσει WHMCS τιμολόγιο πληρωθεί στο WHMCS —
poll-based, money-write μόνο στο ekdosi, only-if-open + lockForUpdate re-read + `transaction_id` dedup
+ AADE-cancel-safe (`WhmcsPaymentSyncer`). **Γνωστά όρια** (από το review): (α) σερβίρει μόνο tenants
που φτάνουν σε `FILED` (myDATA-filing· off-mode drafts μένουν `DRAFTED` → follow-up)· (β) πριν το enable
σε tenant με legacy on-account πληρωμές, επιβεβαίωσε ότι κανένα legacy τιμολόγιο δεν έχει `FILED` pending
row (default OFF = συνειδητό opt-in)· (γ) bridge-only tenant χωρίς native creds εξαιρείται από το loop.

**Phase 2 — outbound (money-write· opt-in· design-first):** Ekdosi payment (σε WHMCS-sourced
τιμολόγιο) → WHMCS `AddInvoicePayment`/mark-paid, ΜΟΝΟ αν όχι-ήδη-πληρωμένο. Κίνδυνοι + δικλείδες:
- **Διπλή πληρωμή** (ο πελάτης πλήρωσε και μέσω WHMCS gateway) → `GetInvoice` status=Unpaid **πριν** το push.
- **Feedback loop** (WHMCS InvoicePaid hook → πίσω στο Ekdosi) → absorb από το **audit-freeze** (filed row → `touch`, όχι δεύτερη εγγραφή).
- **Μερική πληρωμή** → push **ακριβούς ποσού**· < balance μένει Unpaid (σωστό).
- **Retry διπλασιασμός** → **transaction_id** από το Ekdosi Payment για dedup WHMCS-side.
- Νέο plugin op (`add_payment` σε `inbound.php`, HMAC-guarded)· native `AddInvoicePayment` για plugin-less.
- **Opt-in per tenant** + ίσως χειροκίνητο κουμπί «Δήλωση πληρωμής στο WHMCS» αντί πλήρως αυτόματο.

**Υποδομή έτοιμη:** payload έχει status· mapper/draft/CompanyForm/writeback υπάρχουν. Λείπουν: (Φ1)
status-capture + inbox badge + unpaid-default-type + status-aware draft· (Φ2) το outbound payment op.

## 🆕 Settings-in-UI — widen (scheduler + global pages shipped)
- **Role-scoped per-company knobs:** _✅ SHIPPED (trimmed) — «Ρυθμίσεις εταιρείας»
  (`CompanySettings`, `View:CompanySettings`): company_admin self-serves the SAFE subset
  (PDF branding · invoice-mail templates/from · auto-email toggles · backup enable+cadence),
  audited, explicit-whitelist save._ **Still deferred (deliberately super_admin):** the
  credential/infra knobs (myDATA/GSIS/WHMCS/SMTP secrets, e-invoice provider, backup
  passphrase/destinations/retention, tenant identity). `whmcs_auto_issue` stays two-key
  (UI + `companies.whmcs_auto_issue_immediate`). Widen only if a real per-tenant admin
  needs a specific credential delegated — don't bulk-move secrets into company_admin reach.

## 🧹 Deploy/rollback + leads-calendar links — P2 από το review (untracked-deadlock PR)
Δεν μπλοκάρουν τίποτα· καταγραφή για να μη χαθούν.
- **Το rollback path δεν έχει τις νέες εγγυήσεις.** `SelfUpdate::runRollback()` και
  `deploy/rollback.sh` κάνουν `git checkout --force` ΧΩΡΙΣ ούτε τον έλεγχο tracked-dirty ούτε το
  `protectUntracked()` — άρα ένα untracked αρχείο που το target ref το έχει tracked αντικαθίσταται
  χωρίς αντίγραφο. Ίδιο μοτίβο με το update path· μικρό port.
- **Ο φάκελος αντιγράφων γράφεται πριν τους μεταγενέστερους ελέγχους.** Στο `update.sh` το
  copy-aside τρέχει πριν το downgrade-guard και το ΑΦΜ pre-flight, οπότε ένα deploy που ματαιώνεται
  εκεί αφήνει πίσω ένα `storage/app/deploy-untracked/<ts>/` ανά προσπάθεια (και τυπώνει «Copies
  kept…» για deploy που δεν έγινε). Είτε μετακίνηση μετά τους ελέγχους, είτε retention/καθάρισμα.
- **Το tab του link είναι χοντρότερη κοπή από τον αριθμό δίπλα του** (ημερολόγιο leads): το
  `overdueBeforeGrid()` μετράει μόνο τα ΠΡΙΝ το πλέγμα αλλά ανοίγει ΟΛΑ τα ληξιπρόθεσμα, και το
  `withoutNextStep()` ανοίγει `tab=open` (που περιέχει κυρίως leads που ΕΧΟΥΝ επόμενο βήμα). Η
  διάσταση χειριστή συμφωνεί πλέον· η χρονική/πεδίου όχι. Θέλει είτε αποκλειστικά tabs είτε
  ρητότερο κείμενο στο banner.

## ✅ ~~`UpdateRun` authorization boundary (ΜΗ shield-generated policy)~~ — ΕΓΙΝΕ
- **Έκλεισε.** `app/Policies/UpdateRunPolicy.php` γραμμένη ΣΤΟ ΧΕΡΙ (view μόνο για system super
  admin, κάθε mutation `false`) **+** το `UpdateRun` μπήκε στο `ADMIN_FORBIDDEN_RESOURCES`, με
  Gate-level tests (`UpdateRunAuthorizationTest`). Ποτέ stock `shield:generate` template εδώ — δίνει
  CRUD βάσει `*:UpdateRun` permissions, ακριβώς αυτό που απορρίφθηκε στο review του PR #389.
  Έκλεισε ταυτόχρονα και το deploy deadlock: όσο ΔΕΝ υπήρχε αρχείο policy, το `shield:generate`
  (deploy + seeder) το ξανάγραφε ως untracked και το pre-flight του `update.sh` αρνιόταν το επόμενο
  deploy. Φύλακας: `ShieldPolicyDriftTest` (κανένα resource χωρίς committed policy).
- **Υπόλοιπο (μικρό):** τα άλλα δύο global (`$isScopedToTenant = false`) resources — `Company`,
  `User` — είναι ήδη στο `ADMIN_FORBIDDEN_RESOURCES` αλλά έχουν **stock** shield policies. Αξίζει
  ίδιο πέρασμα (super-admin-only, mutations `false`) όταν ακουμπήσουμε ξανά τα δικαιώματα.

## 🔒 Backup / DR / Portability
- **Installer pre-migrate passphrase gate validates only company secrets** _(P2, pre-existing,
  surfaced στο A2c-3 review)._ Το `readHeader()` gate του web installer ελέγχει το passphrase
  μόνο πάνω στα sealed secrets της εταιρείας· tenant με ΟΛΑ τα encrypted company columns null
  (π.χ. Εσθονικός `einvoice_provider=none`) αλλά με sealed gateway/registrar connections περνά
  το gate με ΛΑΘΟΣ passphrase, τρέχει migrate, και σκάει DecryptException μέσα στο import
  transaction → migrated-but-empty DB (ακριβώς ό,τι το gate υπάρχει να αποτρέψει). Fix = το
  gate να δοκιμάζει open() και στα connections/domain_connections secrets blobs όταν τα company
  values είναι όλα null. Σπάνιο (όλοι οι τωρινοί tenants έχουν myDATA/GSIS secrets).
- **Operator export/import role queries are N+1** _(P2, from the operators-in-bundle review)._
  `CompanyExporter::exportUsers()` reuses `TenantRoleProvisioner::roleInCompany()` (up to 3 `userHoldsRole`
  queries per user); `planUsers`/`importUsers` add ~1 query per user each. A 40-operator tenant is ~120+ tiny
  indexed queries per export/import. Deliberately kept as REUSE of the team-aware role helper over a hand-rolled
  ranking join (export/import are manual, infrequent; tenants have single-digit operators). Collapse to one
  `model_has_roles→roles` join filtered to the company if a tenant ever grows a large operator roster.
- **Operators in bundle: leads/activities lose operator attribution on --full import** _(P2, from the review)._
  Now that operators travel, `leads.assigned_user_id` / `lead_activities.user_id` COULD be restored, but
  `FK_REWIRES` still nulls them (no user-id map — the users section carries email/name/role, no source id).
  Only bites `--full` (transactional) bundles, not the settings-bundle install path. Fix = carry the source
  user id in the users section + an email→new-user-id patch pass over the two lead FK columns.
- **--into restore can null a carried-but-unresolvable invoice_type default** _(P2, rare, from the review)._
  On `--into`, a `whmcs_default_*_type_id` the bundle carries but whose target invoice_type isn't in the dumped
  set (soft-deleted / dangling source FK) is nulled at save and the patch can't re-resolve it → the target's
  live default is lost with no warning. Same-company dumps normally include all types, so rare. Fix = on --into,
  keep the target's pre-import value (or at least log) when a carried type FK can't be remapped.
- **Durable native portable key (μετά το legacy_id sunset).** Ο `CompanyImporter` κλειδώνει
  το idempotent matching σε `legacy_id` (+ content-signature fallback). Όταν σβήσει το legacy
  (Delphi/Firebird), τα native rows (legacy_id NULL) δεν συγκλίνουν αξιόπιστα σε re-import-πάνω-
  σε-υπάρχουσα-εταιρία (το `--new`/fresh-copy ΟΚ — FK rewiring μέσω surrogate `id`). Λύση: ένα
  `uuid`/`public_id` ανά portable πίνακα, παραγόμενο στο create, ως ΤΟ idempotency key (uuid→
  legacy_id→signature)· + ΑΦΜ-dedup για πελάτες. Χρειάζεται μόνο για sync/merge μεταξύ ζωντανών
  ekdosi — όχι για μεταφορά-σε-VM.
- **Portability Phase 5** — envelope-key (option 4) — optional future (το plaintext-at-rest
  καλύπτει cross-VM σήμερα).
- **Backup encryption** (app-level) — deferred (βασιζόμαστε σε SFTP/S3 access control).
- **No-password (un-encrypted) exports/backups — συνεπές & εμφανές παντού.** _✅ SHIPPED 2026-06-17:
  per-company `secrets_mode` «Χωρίς κρυπτογράφηση» toggle στο export ΚΑΙ στα αυτόματα αντίγραφα, με
  σαφή plaintext προειδοποίηση και στα δύο· global spatie encryption status (read-only, env
  `BACKUP_ARCHIVE_PASSWORD`) εμφανές με «⚠ χωρίς κωδικό» στις «Ρυθμίσεις συστήματος»._ (Live global
  toggle = .env edit, σκόπιμα read-only — όχι νέα μηχανική.)

## ⚙️ Tech debt / latent (also `CLAUDE.md` «Known latent items»)
- **Schema baseline (v2.0.2 squash) — τα P2 που έμειναν συνειδητά ανοιχτά.** Μετά το squash
  (`database/schema/{sqlite,mariadb}-schema.sql`) ισχύουν τρία πράγματα που ΔΕΝ έχουν guard:
  **(α) `migrate:rollback` είναι πλέον μόνιμο no-op** για τα 220 baseline migrations σε κάθε υπάρχουσα βάση —
  ο `Migrator::rollback` τυπώνει «Migration not found» και συνεχίζει, αφήνοντας τη γραμμή στο repository.
  Εγγενές στο squash· το αναφέρουμε για να μη θεωρηθεί bug.
  **(β) Βάση που έμεινε ΠΙΣΩ από τα 220** (dev/tenant που δεν πρόλαβε να κάνει migrate πριν το merge) δεν
  μπορεί πια να προλάβει — τα αρχεία δεν υπάρχουν, το `migrate` λέει «Nothing to migrate» και το schema μένει
  στάσιμο. Δεν το πιάνει ο `ops:health`. **Πριν από deploy σε τέτοιο host: `migrate` στο `main` ΠΡΙΝ το merge.**
  _Review round 3 (2026-09-15): το mitigation είναι ΜΟΝΟ χειροκίνητο βήμα — μηδενική αυτόματη προστασία.
  Φθηνός guard αν ποτέ ξαναχρειαστεί: σύγκρινε στο deploy το πλήθος (ή το max) των baseline migration rows με
  το live `migrations` table και άρνηση αν η βάση είναι πίσω. Δεν υλοποιήθηκε — το παράθυρο κινδύνου κλείνει
  μόλις κάθε host κάνει migrate μία φορά, και μετά ο guard είναι νεκρός κώδικας._
  **(γ) Το `schema:dump` ΞΑΝΑΒΑΖΕΙ τα `DROP TABLE IF EXISTS`** (τα βγάζει by default ο `mariadb-dump`). Αυτά
  είναι το data-loss footgun που έκλεισε το review: σε βάση με δεδομένα αλλά άδειο/απόν `migrations` table
  (μισο-τελειωμένο `db-restore`) το `migrate --force` θα τα έσβηνε ΣΙΩΠΗΛΑ. Ο `SchemaBaselineTest` κοκκινίζει
  αν επανέλθουν — **μετά από κάθε regeneration ξανα-strip-άρε τα** πριν το commit.
  **(δ) Baseline υπάρχει ΜΟΝΟ για `mariadb` + `sqlite`.** Ο Laravel το βρίσκει με βάση το ΟΝΟΜΑ της
  σύνδεσης (`MigrateCommand::schemaPath()`), οπότε ένα `DB_CONNECTION=mysql` (η σύνδεση υπάρχει ακόμη στο
  `config/database.php` και υπάρχουν driver branches σε `DbSnapshot`/`DbRestore`/`CustomerLedger`) δεν
  βρίσκει αρχείο. Ο guard στον `AppServiceProvider` πλέον **σκάει δυνατά** αντί να αφήσει κενή βάση, αλλά
  αν ποτέ χρειαστεί πραγματικά MySQL θέλει είτε δικό του baseline είτε καθάρισμα της σύνδεσης.
- **Δύο «φιλάρει ηλεκτρονικά» predicates με ΔΙΑΦΟΡΕΤΙΚΗ ευαισθησία στο mode (P2, review 2026-09-15 — provider auto-email parity).**
  Το `Invoice::shouldAutoEmailOnFinalize()` κρίνει μέσω `SendChannel::isProvider()/isDirectMyData()`, που για έναν
  `gr-provider` tenant ΜΕ `einvoice_provider_key` αλλά `einvoice_provider_mode='off'` (η «staged, δεν φιλάρει
  ακόμη» κατάσταση που ρητά προβλέπει ο `EInvoiceSubmitterFactory`) εξακολουθεί να διαβάζει «πάροχος» → **δεν** στέλνει
  finalize email· και επειδή mode=off → `NullSubmitter` → ούτε acceptance email. Άρα ένας staged provider tenant χάνει
  προσωρινά το on-issue email του κατά το staging (self-heals μόλις το mode γίνει sandbox/production). Ο αριθμητής
  (`Company::submitsElectronically()`) είναι mode-AWARE και επιστρέφει false εδώ — οπότε τα δύο predicates διαφωνούν
  στην ίδια γωνία. Χαμηλό impact (δεν υπάρχει `<provider>-off` επιλογή στο dropdown· self-heals). Fix αν χρειαστεί:
  ευθυγράμμισε τα δύο σε ένα mode-aware `filesElectronically` ώστε ο staged tenant να παίρνει το finalize email όσο δεν φιλάρει.
- **`system.update_token` (UI update token) δεν σαρώνεται από `secrets:reencrypt` (P2, DR edge).** Αποθηκεύεται
  στο `system_settings` κρυπτογραφημένο όταν `ekdosi.secrets.encrypt_at_rest` είναι on (ίδια απόφαση με το
  `MaybeEncrypted`). Όμως το `secrets:reencrypt` είναι model/cast-driven (σαρώνει MODELS + `isSecretCast`
  στήλες), οπότε ΔΕΝ πιάνει αυτό το KV secret. Συνέπεια: μετά από encrypt→plain migration σε ΝΕΟ APP_KEY, το
  token μένει αδιάβαστο ciphertext → πρέπει να ξαναμπεί από το UI. Χαμηλό impact (read-only PAT· default deploy
  = plaintext, keyless restore μια χαρά). Fix αν χρειαστεί: να σαρώνει το reencrypt και αυτό το κλειδί, ή να
  μεταφερθεί σε model column με cast.
- **`WhmcsReceiptRecorder`: `transaction_id` = πραγματικό vPOS ref → ο syncer δεν το «βλέπει» στη στενή credit-term γωνία (P2, accepted tradeoff).**
  Κρατάμε σκόπιμα το πραγματικό acquirer/vPOS ref ως «Κωδ. συναλλαγής» (operator προτίμηση). Ο recorder μένει
  πλήρως idempotent (dedup withTrashed στο ίδιο id). Ο `WhmcsPaymentSyncer` όμως κάνει dedup στο σταθερό
  `whmcs-paid:{id}`, οπότε ΔΕΝ αναγνωρίζει μια recorder-γραμμή με vPOS id. **Αδιάφορο για την πραγματική
  περίπτωση** (Eurobank vPOS = cash-term· ο syncer εξ ορισμού αγνοεί cash-term). Στενή γωνία που μένει
  ανοιχτή: credit-term WHMCS τιμολόγιο πληρωμένο-στην-έκδοση → refund της είσπραξης → sweep → ο syncer
  ξαναγράφει (whmcs-paid key). Αν ποτέ ενοχλήσει: ο recorder να κρατά ΚΑΙ το whmcs-paid key (χωρίς να
  πειράζει το «Κωδ. συναλλαγής») — π.χ. ο syncer να κάνει και έναν per-invoice έλεγχο ύπαρξης WHMCS-είσπραξης.
- **`WhmcsReceiptRecorder`: πολλαπλές εισροές (installments) → το «Κωδ. συναλλαγής» δείχνει μόνο τη μία (P2, display-only).**
  Το `provenance()` διαλέγει το transaction με το μεγαλύτερο `amountin` για το ref· αν το WHMCS εισέπραξε το
  τιμολόγιο με ≥2 captures (π.χ. 60€+64€), καταγράφεται ΜΙΑ πληρωμή για το πλήρες owed με το transid ΜΟΝΟ
  του ενός. Καθαρά display/audit (μηδέν επίπτωση στο υπόλοιπο)· τα υπόλοιπα refs φαίνονται ούτως ή άλλως στο
  WHMCS. Αν χρειαστεί: όλα τα transids στη «Σημείωση».
- **`WhmcsReceiptRecorder`: το ποσό είσπραξης = δικό μας owed, ΟΧΙ το ευρώ που εισέπραξε το WHMCS (P2, by design).**
  Ακολουθούμε το trail του WHMCS id, κρατώντας το ΔΙΚΟ μας παραστατικό netted-to-zero. Στο `file()` path ο
  `WhmcsFilingGuard::assertTotalsReconcile` ήδη εγγυάται ότι το gross ταιριάζει με το WHMCS total· στο
  **draft-first** path (createDraft — σκόπιμα ΧΩΡΙΣ reconcile, ώστε ο χειριστής να διορθώνει γραμμές) αν ο
  χειριστής αλλάξει το σύνολο, η αυτόματη είσπραξη μηδενίζει στο νέο owed και μια τυχόν διαφορά με το WHMCS
  δεν επιφαίνεται ως over/under-payment. Αποδεκτό: η είσπραξη είναι editable/deletable money-trail, όχι
  reconciliation· αν ποτέ χρειαστεί, βάλε ένα προαιρετικό reconcile-warning στο draft-issue.
- **`WhmcsReceiptRecorder`: το draft-first μονοπάτι δεν αυτο-καταγράφει σε off-mode tenant (P2, non-prod edge).**
  Ο recorder καλείται (α) στο `WhmcsInvoiceFiler::file()` (τρέχει πάντα, ακόμη κι off-mode — pending=FILED)
  και (β) στο `WhmcsWritebackService::syncFiledFromLifecycle()` για το draft-first. Το (β) καλείται από τον
  MyDataSubmitter στο VALID persist και επιστρέφει νωρίς όταν δεν υπάρχει MARK — άρα ένας **off-mode** tenant
  (NullSubmitter, χωρίς MARK) που εκδίδει WHMCS draft μέσω lifecycle ΔΕΝ αυτο-καταγράφει την είσπραξη
  (προστίθεται χειροκίνητα στο «Πληρωμές»). Ασήμαντο σήμερα (όλοι οι πραγματικοί tenants myDATA-on)· αν ποτέ
  γίνει πρόβλημα, μετακίνησε την κλήση του recorder πριν το no-MARK early-return (είναι ορθογώνια στο myDATA).
- **`PaymentAllocator::absorbableTotal()` = N balance reads (P2, perf on data we don't have).** Ο guard της
  «Είσπραξη (έμβασμα)» στην Καρτέλα (και ό,τι preview το χρησιμοποιεί) καλεί `balanceData()` ανά live+active
  τιμολόγιο του πελάτη — 2 aggregate queries + eager `paymentMethod` το καθένα — και ξανα-τρέχει σε κάθε
  `amount` onBlur round-trip (μνημονεύεται μόνο εντός ενός request). Ασήμαντο για πελάτη με λίγα ανοιχτά·
  αργό αν κάποιος έχει δεκάδες/εκατοντάδες ανοιχτά. Deferred συνειδητά: το ίδιο κόστος πληρώνει ήδη το
  write-path (`allocate()`), και η φθηνή εναλλακτική (άθροισμα από cache columns) ξανα-εισάγει το
  cache-vs-live divergence που θέλαμε να αποφύγουμε. Σωστή λύση αν χρειαστεί: ένα aggregate που διπλώνει
  paid/credited ανά πελάτη (όπως το `Customer::withOutstandingBalance`) και live-confirm μόνο στα λίγα
  υποψήφια. Καρφωμένη συμπεριφορά: `CustomerLedgerReceiptGuardTest`.
- **Το blob `einvoice_provider_config` δεν καταγράφει ΣΕ ΠΟΙΟΝ πάροχο ανήκει (P2, residual).** Είναι επίπεδο
  (`base_url`, `token`, …) και ο ιδιοκτήτης συνάγεται από το `companies.einvoice_provider_key` — που όμως
  ΜΗΔΕΝΙΖΕΤΑΙ όταν ο tenant παρκάρει σε κανάλι myDATA («Καθόλου»), ενώ το blob κρατιέται σκόπιμα. Έτσι στη
  διαδρομή «πάροχος Α → Καθόλου → πάροχος Β» ο Β κληρονομεί τα κοινώνυμα πεδία του Α (`base_url` υπάρχει σε
  invosign ΚΑΙ sbz). Η απευθείας «Α → Β» ΕΧΕΙ διορθωθεί. Δεν κλείστηκε εδώ γιατί η προφανής λύση (μη
  προ-συμπλήρωση όταν ο ιδιοκτήτης είναι άγνωστος) **σβήνει οριστικά κρυπτογραφημένο token** στη διαδρομή
  «πάροχος → Καθόλου → ΙΔΙΟΣ πάροχος», που είναι και η συχνή — δοκιμάστηκε και αναιρέθηκε. Σωστή λύση:
  να καταγράφεται ο ιδιοκτήτης (στήλη ή κλειδί μέσα στο blob) → schema αλλαγή, όχι tweak στη φόρμα.
  **Η σημερινή συμπεριφορά είναι καρφωμένη** από το
  `ProviderCredentialVisibilityTest::test_the_parked_route_still_carries_shared_fields_across_known_residual`
  — αν το κλείσεις, αυτό το test θα κοκκινίσει· ενημέρωσε ΚΑΙ το σχόλιο στο `SendChannelFormBridge::hydrate()`.
  Πρακτικά latent σήμερα: μόνο ο `invosign` είναι wired (το `sbz` transport δεν υπάρχει ακόμη).
- **`mydata_marks` has no index for the provider-quota lookup (P2, perf on data we don't have).** The card's
  query is `WHERE company_id = ? AND provider_key = ? AND remaining_invoices IS NOT NULL ORDER BY id DESC LIMIT 1`,
  but the table carries only `index(invoice_id)` + `unique(company_id, legacy_id)` — so the planner walks the PK
  backwards. Runs once per dashboard load (now including the not-yet-synced placeholder branch) and, in the same
  shape, on the filing hot path (`GrProviderSubmitter::warnIfLowProviderQuota`). Fix = `index(['company_id',
  'provider_key','id'])`. Deferred deliberately: current mark volumes are small and the PR that surfaced it was a
  two-line dashboard tweak — not the place for a migration. Raised in the PR #518 review (round 3).
- **EurobankReturnController parses the raw body twice (P2, micro).** `__invoke` parse_str's the body for the
  orderid; `record()` parse_str's it again for the raw provider status. Tiny (small body), and keeping `record()`
  self-contained is arguably cleaner than threading `$fields` through `reject()` → `record()`. Fold into a single
  parse passed down if the return path ever grows hot.
- **Invoice-targeted settle: no invoice row-lock → concurrent same-invoice settles can overpay (P2, edge).**
  `PaymentAllocator::allocateToInvoice` reads the target balance without `lockForUpdate` on the invoice, so two
  intents targeting the SAME invoice settling concurrently could each write the full balance (invoice → negative
  instead of parking the 2nd on-account). Mirrors the pre-existing non-locking `allocate()` FIFO pattern (same
  BACKLOG family as the InvoiceNumberer/recompute row-lock note), just more plausible now that a customer can retry
  one invoice. Sequential settle is correct; only a rare double-capture race. Fix if it ever bites: lock the invoice
  row (or a per-(customer,invoice) advisory lock) around the balance read+write.
- **Portal `store()` validates one invoice id by rebuilding the whole payable list (P2, perf).** To 404-guard a
  submitted `invoice_id`, `PaymentController::store` calls `payableInvoices()` which runs `balanceData()` per open
  invoice (N+1). Negligible for a portal customer (few invoices) on a rare action; tighten to a single scoped
  existence query (same InvoiceScope predicate + whereKey) if a customer with many open invoices makes it matter.
- **Payment-gateway config fields share one `config` statePath — keys must be unique across gateways (P2, future).**
  The «Τρόποι online πληρωμής» form now builds a STATIC per-gateway `config` schema (one Group per gateway under a
  single `->statePath('config')`, only the selected one visible) — the fix for the false-«required» bug. All Groups
  mount together, so if two gateways declare the SAME `configFields()` key (e.g. a future PayPal `testmode` colliding
  with Eurobank's), their defaults collide and the last-mounted wins. Disjoint today (manual = bank_account_ids/
  instructions; eurobank = merchant_id/shared_secret/lang/testmode → verified no bleed on create). When a 2nd gateway
  reuses a key: namespace config under the gateway key (`config.eurobank.testmode`) — but that changes the persisted
  shape + every reader (gateway `initiate`/`redirectForm`, exporter), so do it deliberately, not reflexively.
- **Export/import — manual gateway bank_account_ids all-unresolved → «all active» (P2, edge).** On import, a
  `manual` connection's `bank_account_ids` are rewired through the imported bank_accounts map; ids that don't
  resolve are dropped. If a connection restricted to specific accounts references ONLY banks that didn't export
  (e.g. a soft-deleted bank whose id lingered in config), the list rewrites to `[]`, which the gateway treats as
  «show ALL active accounts» — a silent widening from the configured subset. Rare (needs a config pointing at a
  non-exported bank). Mitigation idea: when a non-empty selection rewrites to empty, deactivate the connection so
  the operator re-checks it, rather than defaulting to all. Deferred — the common case (all banks export) is fine.
- **MON-13 leftover (P3, ledger display edge).** The Καρτέλα ledger sorts same-day rows by a business-date
  primary + creation-order tiebreak, but a row's DISPLAYED time is issue-time for invoices vs entry-time
  (created_at) for payments. A back-dated invoice (issued 09:00, entered 15:00) on the same day as a payment
  (entered 10:00) can render its 09:00 above the payment's 10:00 while sorting after it — a cosmetic «ανάποδα»
  in that narrow case. The common case (two same-day payments/refunds) is fixed + tested. A full fix synthesizes
  a single sort/display datetime per row (pay_date + entry time-of-day), but that shifts the running-balance walk
  order, so it needs its own regression pass — deferred.
- **Payment intents — housekeeping (B0b survivors, low priority).** (a) **Amount sanity cap on manual settle:**
  `actual_amount` is bounded only by `min 0.01`; a typo (10000 vs 100) settles FIFO + parks a large on-account
  credit. Pre-existing to ALL manual payment entry (the Payments resource too), operator-authoritative — add a
  soft «are you sure, this is far from the intended X?» confirm if it bites. (b) **Auto-expire stale pending
  intents (still open after B1):** `expires_at`/`STATUS_EXPIRED` are wired into the model/filter but nothing
  sets/transitions them. B1 deliberately did NOT set `expires_at` on Eurobank redirect intents: a genuine but
  LATE browser-return must still settle (money > tidiness), and `settle()` ignores `expires_at`, so expiry is
  purely a housekeeping/badge concern, not a gate. Follow-up: a scheduled sweep that flips clearly-abandoned
  pendings (older than N hours AND never returned) to `EXPIRED` for the «Εκκρεμείς» badge — never one that
  could block a real settle. ✅ **(c) DONE (B1) — acquirer txId on the Payment.** `settle()` now takes an
  optional `$transactionId`; the Eurobank return threads `PaymentOutcome::providerTxnId` → `payments.transaction_id`
  (+ into the notes «κωδ. συναλλαγής»), while `reference` stays our ΠΛ- receipt key. The `Payment` table IS the
  transactions ledger (WHMCS `tblaccounts` analog) — no new table needed; a fuller per-gateway event log
  (auth/capture/refund) is only worth it at B4/refunds.
- ✅ **B1 go-live — vPOS digest validated on the REAL acquirer (owner, 2026-09-06).** The field set/order was
  proven end-to-end by a genuine **production** €1 transaction: redirect accepted, `CAPTURED` return verified
  and settled, money landed. (No sandbox creds survived the ~10-year gap, so the owner validated live instead —
  the redirect digest and the return digest both hold against the real bank.) Cross-checked afterwards against
  the maintained Papaki WooCommerce module (same `shophandlermpi` endpoint + SHA-256 digest scheme). Nothing to
  change in `redirectForm()`/`handleWebhook()`. *Follow-up only:* whether Cardlink also POSTs `confirmUrl`
  server-to-server (they ask for the server IP) — if so our session-free controller already settles without an
  open browser; a config question to the bank, not code.
- **B1 review — consciously-declined P2s (kept as-is, reasons recorded).** (i) **No auto-cancel of an intent on a
  verified FAILED/CANCELLED return** — same money>tidiness rule as expiry: a REFUSED interim status can be
  followed by a retry-CAPTURE on the SAME orderid, and auto-cancelling would then strand real money via the
  `isPending()` guard. Declines just log + leave pending; «Εκκρεμείς»-badge honesty is the money-safe expiry
  sweep (item b above), not a cancel. (ii) **`is_scalar` guard drops array-shaped return fields from the digest
  concat** — fails closed (never a false settle); real vPOS returns are flat scalars. (iii) **Two
  `connectionFor()` loaders** (portal vs webhook) kept separate by DELIBERATE opposite semantics — the outbound
  portal path refuses an inactive/trashed method (don't start a payment through it), the inbound return resolves
  it `withTrashed` + regardless of `is_active` (settle money that already arrived). Merging risks collapsing that
  distinction; revisit only if a third caller appears.
- **B1 vPOS digest-test fidelity — surviving P2s (PR #485 round-2, test-only, kept as-is).** The `EurobankGatewayTest`/
  `EurobankReturnControllerTest` helpers now mirror production byte-for-byte (request = iconv-then-secret-outside,
  return = raw), but three maintainability nits remain: (i) the digest EXCLUDE list `['_charset_','digest','submitButton']`
  is re-literalised in each test instead of referencing `EurobankGateway::DIGEST_EXCLUDED` (it's `private`) — a future
  add/remove there won't fail the mirrors; fix = expose the constant or a shared test trait. (ii) the invalid-UTF-8
  guard's `assertNotSame` assumes `iconv//IGNORE` STRIPS `\x80` rather than returning `false` — glibc/libiconv-standard
  but platform-sensitive. (iii) the non-scalar→`''` concat branch is documented (mirrors `handleWebhook`) but never
  exercised by a fixture. All P2: fail-closed, ASCII-safe today, no P0/P1. Knock off opportunistically.
- ✅ **DONE (B1) — Payment gateways write-only secret fields.** `HasSecretConfig` (opt-in interface) +
  `EditPaymentGatewayConnection` mutate hooks: a stored secret is never hydrated back into the form, and a
  blank submit preserves it. `EurobankGateway::secretConfigKeys() = ['shared_secret']`; covered by
  `PaymentGatewaySecretConfigTest`.
- **Customer portal — acting device's remember-me after own password change (P2, pre-existing UX).** When a
  customer changes their OWN password (profile), `remember_token` is rotated (kills «remember me» on every
  device, incl. this one) but the acting device's recaller cookie is NOT re-issued — so once its session
  cookie expires, the very device that changed the password is asked to log in again instead of being
  remembered. Pre-existing (the rotation predates the session-invalidation slice). Proper fix re-issues the
  current recaller (à la `logoutOtherDevices`), but the obvious path re-fires the Login event; deferred rather
  than widen the change. Security-neutral (only a remembered convenience is lost on the acting device).
- **Customer portal — invited-claim vs revoked grant (reviewed, BY DESIGN — not a bug).** If an operator
  invites a login (invited + grant) then revokes the grant before the invitee claims, the claim still flips
  invited→active and the login can authenticate (seeing NOTHING, since grants gate the documents view). This is
  the deliberate orthogonality: login STATUS (can authenticate) ⟂ GRANTS (what it sees). To neutralize a login
  the operator **suspends** it (then the claim no-ops); revoking a grant only removes data access. An ungranted
  active login is harmless (empty portal). Revisit only if a product decision wants «no grant ⇒ can't activate».
- **Customer portal — self-register concept (design locked, NOT built).** Reset-password + the invited-login
  «claim» shipped (operator opens an invited login → customer self-sets password via `/user/forgot-password`),
  which IS the safe near-term onboarding — no open form, operator decides who gets a login. If open-ish
  self-registration is ever wanted, go **tier-2 CLAIM only, never open signup (tier-3)**: a form where the
  visitor proves they are an EXISTING customer (ΑΦΜ + email that matches a `customers` row, or an invoice
  number) → **email verification** → the row is created, but **the data grant is still operator-approved**
  (or deterministically WHMCS-derived), never auto. Key safety fact: because of the grants boundary a login
  with **no grant sees nothing**, so even a successful spam/bot registration is inert — the real vectors are
  email-bombing (mitigate with the same throttle+honeypot pattern as reset), DB junk (email-verify before the
  row is «real»), and impersonation (mitigated by the ΑΦΜ-match + operator grant approval). Do not build until
  a tenant actually asks; reset-password covers onboarding for now.
- **Customer portal — Slice 2 documents view: P2/P3 survivors (from the adversarial gate).**
  _(consciously deferred)._ The security boundary (grant-scoped reads, fail-closed PDF authz, per-request
  status re-check) passed with no P0/P1. Fixed in the same PR: PDF-route throttle, `target=_blank`
  `rel=noopener`, a «newest 500» notice when the per-group cap is hit, and a strengthened suspended-mid-session
  test (real login + `forgetGuards()` → genuine DB-reload path). **Parked:** (a) **`CustomerDocumentFeed::forLogin()`
  runs one query per active grant with no aggregate per-response cap** — fine at today's 1–3 grants/login, but a
  login with many grants would hydrate up to 500 rows × N groups into one non-paginated page; add pagination /
  a response ceiling if a reseller login ever holds many grants. (b) **`documentsFor()` hard-caps at 500 newest
  docs per (company,customer)** with only a notice, no pagination — a customer with >500 live invoices can't
  reach the oldest; add pagination when a real tenant approaches the cap. **Deliberate (not a bug):** a
  `reseller`-role grant exposes the granted customer's FULL live-document history, not only reseller-brokered
  docs — the operator explicitly links a login to a customer/ΑΦΜ, so the grant IS the configured relationship;
  narrow it only if a product decision says a reseller should see a subset.
- **Gapless-at-send (ΑΑ Phase 1 invoices + Phase 2 δελτία) — P2 survivors of the review loop**
  _(consciously deferred)._ The gapless-at-send numbering change (real ΑΑ allocated at transmission,
  provisional «ΠΡΟΣ-…» until then) passed the adversarial gate with two P1s fixed (finalize gate → mode-aware
  `submitsElectronically()`; a build/config error after `assign()` now `release()`s the number). **Phase 2
  (delivery notes) shipped** — `delivery_notes` migrated (code/invcode nullable+widened), `CreateDeliveryNote`
  provisional, `DeliveryNoteSubmitter` assigns/releases via `InvoiceNumberer::assignDelivery`/`releaseDelivery`.
  The same three items stay parked, and (a)+(b) now apply to BOTH invoices and δελτία: **(a) Concurrency
  gap** — the decrement-if-top `release`/`releaseDelivery` is gapless only under SERIAL issuance; if two
  documents of the same series are submitted from two concurrent FPM requests and the earlier-numbered one
  is rejected, its number is no longer the top and a rare gap remains (negligible at ~70 docs/month; a
  fully-gapless guarantee needs safe renumbering). **(b) Draft XML preview** — a draft has `code=null`, so
  the AADE payload can't be built (it guards `code < 1`); the two preview actions now show a clear «issue
  first» message instead of the raw builder error, but a true preview needs the next counter value as a
  non-consuming placeholder aa. `delivery:sandbox-validate` sidesteps this by keeping allocate-at-create for
  its throwaway test note. **(c) Provisional on finalized-unsent actives** — a live AADE tenant that finalises
  but hasn't transmitted shows «ΠΡΟΣ-…» in the ledger/PDF filename until it sends (correct per the model, but a
  small «(προσωρινό)» badge would remove any surprise). **(d) Second write per draft** — the provisional
  invcode is keyed on the surrogate id, so it needs a post-INSERT `saveQuietly` (INSERT+UPDATE per draft
  create). Negligible at these volumes; a derived/read-time label would avoid it but invcode is a real,
  queried column. **(e) `down()` is best-effort** — the migration's rollback restores NOT NULL / varchar(15),
  which fails while any provisional row exists; deploy rollback uses a DB snapshot (`ekdosi:db-restore`), not
  `migrate:rollback`, so this is documented, not load-bearing. _(Review round 2 P0 fixed at root:
  `release()`/`releaseDelivery()` now fire only for the number THIS submit reserved — `assign()` returns
  whether it allocated — so an already-numbered doc, e.g. finalized-at-'off'-then-live or a legacy import,
  is never renumbered by a rejection it merely triggered.)_
  _Round 2 (Phase 2) P2 survivors, all documented not fixed: **(f) off-tenant ΔΑ stays provisional** — a
  non-transmitting tenant ('off'/'none') has no delivery-note local-issuance path (no finalize action; «Έκδοση»
  is gated on `submitsElectronically()`), so its ΔΑ drafts keep «ΠΡΟΣ-ΔΑΠ-{id}» forever. Acceptable: a compliant
  ΔΑ is always transmitted (e-transport mandate), the note was never issuable at an off tenant even before Phase 2,
  and the «ΠΡΟΧΕΙΡΟ» banner signals it. If a local-issuance ΔΑ flow is ever wanted, add a finalize→`assignDelivery`
  path like the invoice one. **(g) Pre-send local-failure gap** — a failure AFTER assign but before the wire send
  (initFirebed missing-creds, armInDoubt DB failure, provider issue-date/transport-resolution) is outside the
  release try/catch, so the number isn't returned; but the far more likely RETRY reuses it (assign no-ops on the
  set code), so a gap needs infra-error + abandon. Shared with the invoice submitters. **(h) CMR from a provisional
  draft** — «Δημιουργία CMR» is available on a draft, so `reference_no` records the provisional «ΠΡΟΣ-…»; the CMR
  is an editable ΠΡΟΧΕΙΡΟ the operator fixes before printing._
  _Round 3 (whole-ΑΑ holistic review, Phase 1+2 together) — two edge bugs FIXED at root, three P2 kept:
  **fixed:** a concurrent-reservation GAP on the lock-less local-issuance paths (finalize/NullSubmitter) —
  `reserve()` now re-reads the row's `code` under a row lock so a loser adopts the winner's number
  (counter bumped once); and finalize now `assign()`s BEFORE the `local_status→active` flip so a failed
  allocation leaves a recoverable draft, not an active-but-unnumbered document. **P2 kept: (i) provisional
  LABEL type-segment can go stale** — the invcode «ΠΡΟΣ-ΤΠΥ-{id}» is frozen by the `created` hook and not
  refreshed if the draft's `invoice_type` is changed (EditInvoice), and `revert()` rebuilds it from the
  current type; the real ΑΑ at send is always correct and the «ΠΡΟΣ» marker signals non-final, so this is
  cosmetic (id-keyed → unique either way). Dropping the type segment from the format would remove it but the
  operator explicitly chose «ΠΡΟΣ-ΤΠΥ-6885». **(j) `ProvisionalCode::is()`** is exercised by the numbering
  tests (not dead); app readers use the equivalent `code === null` (the authoritative DB signal) — both valid._
  _Rounds 4–5 (verify-the-fix passes) hardened the round-3 fixes: `reserve()` now THROWS (not burns) if the
  row vanished mid-operation; the adopt path syncs only the three number columns (a caller's other pending
  edit survives); `revert()` locks document→invoice_types in the SAME order as `reserve()` (provably
  deadlock-free); finalize wraps assign+flip in one transaction (savepoint rollback undoes the counter bump).
  One P2 accepted, not fixed: **(k) transient stale in-memory model after a rolled-back finalize** — if the
  status-flip fails, the outer transaction reverts code/invcount in the DB but the in-memory $record still
  shows them; the action errored (no redirect, no success toast) and the row refetches on the next load, so
  there is no persisted corruption. Severity converged P1→P1→P2→P2 across the rounds (no P0/P1 in round 5)._
- **OPS-001 cron↔worker attribution — inherent 5-min boundary ambiguity** _(P2, DECLINED across the OPS-001
  review loop — documented, not a bug to keep patching)._ `OperatorHealthSeverity` disambiguates «cron down»
  from «worker down» via the queue heartbeat (a job cron dispatches): when cron is down it suppresses the
  worker finding unless the beat is staler than `cronAge + 5` (proof the worker failed WHILE cron was alive).
  Two states are inherently unresolvable by timing and are consciously accepted: (a) a worker that died within
  ~5 min of cron dying is indistinguishable from a healthy idle worker, and (b) cron `missing` (never ticked →
  no age to compare) attributes any worker staleness to cron. In both, cron is already reported non-ok
  (the actionable root) and the true worker state SELF-HEALS the moment cron is fixed (a still-stale beat with
  cron now fresh → worker reported). Chosen over slack tweaks that false-alarm «worker down» on every sustained
  cron outage. Strictly better than pre-OPS-001 (which had NO cron signal and always blamed the worker). Only
  revisit with a SECOND independent worker-liveness signal (not more timing math on the same two keys).
- **`OperatorHealthReport` heartbeat ages are floats (`Carbon::diffInMinutes`)** _(P2, from the OPS-001
  review round 2; pre-existing)._ Both `queue()` and the new `cron()` compute `age_minutes` via
  `Carbon::parse($ts)->diffInMinutes(now())`, which under Carbon 3 (Laravel 11+) returns a **signed float**
  — so the operator-facing «age» line and the JSON `age_minutes` can read a fractional value, and forward
  clock skew yields a tiny negative. Harmless for the thresholds (`≤ 10` / `> 30` / the severity `+slack`
  all hold on floats), but for display/threshold cleanliness cast to a non-negative int minute count in ONE
  place shared by both slices (keep `queue()`/`cron()` in parity — don't fix only one). Untouched here to
  preserve that parity.
- **PROV-020 auto-stamp — P2 survivors of the review loop** _(consciously deferred)._ The change that
  auto-stamps `issued_at`=today at Send (provider + ΔΑ) passed the gate with the delivery-path ordering
  bug fixed (stamp now runs BEFORE the XML build; guarded by an XML-content test). Three P2s parked:
  (a) **Cross-day ambiguous retry** — if a Day-1 send times out AFTER the provider filed (no local MARK),
  a Day-2 retry re-stamps `issued_at`=Day-2 before `recoverViaStatusCheck` adopts the Day-1 MARK, so the
  local date can diverge from the filed date. **Not a regression** — the same mismatch was reachable
  before (the operator had to re-date past the block to retry); it is the PROV-001 exactly-once
  limitation (no durable in-doubt marker). Fix belongs with PROV-001: on adopt, reconcile `issued_at` to
  the adopted MARK's `mark_date`. (b) **Recompute/audit side effect** — the stamp's `update()` fires the
  InvoiceObserver balance recompute (due-date shifts to today — correct) and an «issued_at changed»
  activity row on every filing (truthful, but an operator could read it as a manual back-office edit);
  optionally tag it distinctly. (c) **Provisional draft date** — a draft on a provider tenant shows
  «Ημερομηνία έκδοσης» = its prep date, which changes to today at Send; a small «οριστικοποιείται στην
  αποστολή» hint on the draft/Send view would remove the surprise (also covers a *finalized* doc whose
  operator-set date is silently re-dated to today at Send). (d) **Timezone unification (masked)** — the
  stamp writes `now()` (app tz), the payload serializes `Carbon::parse(issued_at)->toDateString()` (app
  tz), and the guard validates in hardcoded `Europe/Athens`; all agree only while `APP_TIMEZONE=Europe/
  Athens` (which this Greek app always uses). If it ever isn't, unify the issue-date tz across stamp +
  payload (`AadeInvoiceDocument::setIssueDate`) + guard. (e) **`created_at` on legacy rows** — the new
  «Ημερομηνία δημιουργίας» shows the ETL import timestamp for Firebird-imported invoices (not the original
  prep date), so a historical record can read as «created after issued»; cosmetic, hide/relabel for
  `legacy_id`-set rows if it confuses.
- **«Έλεγχος ετοιμότητας» (Preflight) — P2 survivors of the review loop** _(consciously deferred)._
  Two P2s survived the 2-round adversarial gate (round 1 = the tenant double-scoping P1, fixed at root
  with `CompanyContext::actAs`; round 2 = no P0/P1). (a) **Query fan-out**: `ReadinessReport::build()`
  issues ~13 sequential `count()` queries per company (7 lookups + 2 products + 2 whmcs + the audit's
  per-type/VAT loads); negligible at 3 tenants behind the 30s cache, but scales linearly per tenant on
  each cache miss — collapse to grouped `count() … GROUP BY company_id` (or a `withoutGlobalScope` sweep)
  if the tenant count grows. (b) **Stale window**: the report is cached 30s under a single global key with
  no config-change invalidation, so a just-fixed tenant can read «Μπλόκο» for up to 30s until the TTL
  lapses; the «Ανανέωση» button is the escape hatch and this matches the «Υγεία συστήματος» precedent.
  DECLINED (not deferred): routing the fix through `->withoutGlobalScope(CompanyScope::class)` instead of
  `actAs` — the reused `MyDataConfigAudit` runs its OWN internal queries that `ReadinessReport` can't
  scope from the outside, so re-pinning the ambient context (`actAs`) is the only remedy that also fixes
  the audit; `withoutGlobalScope` on our own queries would leave the audit mis-scoped.
- **PROV-009 remainder — explicit `evidence_pending` indicator** _(P2)._ The core (quota + reception
  capture, widget, low-quota warn) shipped. What's left: when a filing is adopted via a MARK-only
  recovery (the myDATA read returns MARK+QR but not the provider UID/auth), the provider evidence is
  incomplete — surface that explicitly (a DERIVED predicate «has uid+auth?» + a console/invoice-box
  badge «στοιχεία παρόχου ελλιπή», not a stored state to drift). It must NEVER trigger a re-file merely
  to fill those fields (the finding is explicit). Two related asks are **not feasible**, not deferred:
  a delivery-failure warn (the issue response carries no delivery outcome — the provider emails the
  customer afterwards with no callback) and a scheduled quota poll (no non-issuing endpoint, and the
  quota already refreshes on every filing). Also parked: **shared-account quota** — the widget/warn read
  the tenant's OWN latest reading, correct for one InvoSign contract per tenant (our setup); if a single
  provider account ever backed several tenants, each would see a per-tenant partial view of the shared
  quota. Revisit only if a reseller/accountant shared-account setup appears.
- **PROV-005 authenticated provider credential/quota probe — ✅ CLOSED (won't-do, owner 2026-09-05).**
  The local config false-greens were fixed earlier (issuer-field completeness + active-env credential
  pairing, in `ProviderPreflight` + go-live). The remaining authenticated half is **deliberately not
  pursued**: InvoSign exposes **no non-issuing status/credential endpoint**, so the only real probe
  would be an actual document — and **every InvoSign call, test included, consumes credits**. Not worth
  it. Decision: keep `InvoSignTransport::ping()` as a **free unauthenticated GET** (reachability only —
  «Έλεγχος σύνδεσης» can read green with a dead token, and its success messages now say so explicitly:
  «έλεγχος μόνο διαθεσιμότητας… δεν επαληθεύει διαπιστευτήρια/quota»). Credentials/quota are proven on
  the **first real submission**; the cutover dry-run (bucket A) remains the backstop. Re-open only if
  InvoSign later ships a free non-issuing status endpoint.
- **STOCK-001 follow-ups — remainder-aware, recompute-style stock reversal** _(P2 survivors of the
  STOCK-001 review; the reachable P1 order-regression was fixed in that PR)._ Three residual edges, all
  the SAME root — the reversal fires incremental deltas at each cancel event while the "correct"
  reverse for one document depends on the LIVE state of others (the money side avoids this by
  recomputing from the live set, e.g. `RecomputeReturnedQuantities`):
  (a) **δελτίο double-count** — `reverseSaleForDeliveryNote` reverses the full line qty with no
  `qty_returned` remainder check, because a returned qty is tracked on the linked INVOICE line, not the
  δελτίο line. If a Πώληση δελτίο *carried* the sale (issued before its linked invoice) AND a credit
  note against the linked invoice returned the goods, cancelling the δελτίο double-counts the return.
  Contrived (δελτίο-first sale group + credit against the linked invoice + δελτίο-only cancel = an
  already-inconsistent business state).
  (b) **soft-delete-a-credit-note path** _(pre-existing, unchanged by STOCK-001)._ `deleted()` runs
  `RecomputeReturnedQuantities` (which frees `qty_returned`, LIVE-scoped) but does NOT reverse the
  credit note's `+qty` return movement (the reversal is wired to the `local_status='cancelled'`
  transition, not to `deleted()/restored()`), so delete-credit-then-cancel-invoice can inflate. Needs
  the delete/restore path to compensate stock too (or a recompute).
  (c) **unify the three reversal loops** — `reverseSaleForInvoice` / `reverseSaleForDeliveryNote` /
  `reverseReturnForCreditNote` are near-identical (iterate lines · skip untracked · guard on
  source-reason + REASON_CANCEL · record REASON_CANCEL). A single `reverseLineMovement(source, reason,
  sign, remainderFn)` — or better, a recompute-from-live-documents pass — collapses the divergence and
  makes (a)/(b) impossible to forget.
  (d) **trigger-predicate asymmetry** — the return-reversal fires on the credit note's
  `local_status='cancelled'` observer transition, but `qty_returned` is freed on the broader
  `InvoiceScope::live()` (which ALSO excludes an AADE-only-cancelled doc, `mydata_state=CANCELLED`
  with `local_status` still active). If a credit note ever reached that divergent state, `qty_returned`
  would free while the `+qty` return-IN stayed → a later original-cancel could inflate. Latent: no
  in-app path flips `mydata_state` without `local_status` (both cancel choke-points sync the two). The
  `reverseReturnForCreditNote` skip-when-original-cancelled guard has the mirror caveat: it assumes the
  INVOICE owns the sale (false for a δελτίο-first linked group → overstate).
  (e) **revive re-application — ✅ FIXED (PROV-018 PR).** «Επαναφορά» (`ViewInvoice::revive`,
  `local_status: cancelled → active`) used NOT to re-apply stock — `recordSaleForInvoice`/
  `recordReturnForCreditNote` skipped because the base `REASON_SALE`/`REASON_RETURN` movement still
  existed, while the cancel's compensation stood → net 0 (credit note understated, invoice overstated).
  Now they detect an outstanding cancel compensation and record a signed `REASON_REVIVE` that zeroes it,
  and the reverse* «already reversed» guards became NET-based (`compensationBalance`, not existence) so a
  cancel→revive→re-cancel cycle stays idempotent (`StockService`, tests in `StockSaleTest`).
  (f) **delivery-cancel reversal durability** _(from the PROV-018 review, still deferred)._
  `DeliveryLifecycleService::persistCancellation` records `reverseSaleForDeliveryNote` best-effort AFTER
  the cancel transaction commits; if the process dies between commit and the reversal a retry cancel
  throws «ήδη ακυρωμένο στη myDATA» and never re-runs it (and the remote-cancel/`refreshStatus` path —
  MYD-019, currently frozen on the ΔΑ v2.0.2 spec — does not reverse stock at all yet, so the changelog's
  «reused by MYD-019» is the method being ready, not wired).
  A recompute-from-live-documents pass closes (a)–(d) + (f) at once (or move (f)'s reversal inside the txn).
  Stock is informational (never blocks a sale, self-corrects with a manual adjustment), so these
  are genuinely P2.
- **PROV-018 micro-cleanup** _(P2 from the PROV-018 review, accepted)._ `IssueCreditNote::reverseRemaining`'s
  per-line `remainingQty()` read duplicates the loop's inline `qty_returned` read, and a full reversal runs
  2N `return_invoice_extras` reads (resolver builds the selection, then the loop re-validates) where N would
  do. Deliberate: the resolver reads pre-mutation while the loop re-reads under its own mid-loop writes
  (correctness over micro-opt); a single keyed prefetch reused by both would collapse it. Negligible on real
  invoice line counts — revisit only if a reversal ever touches many lines under contention.
- **PROV-019 declined-scope + soft-warn posture** _(conscious calls in the PROV-019 change, 2026-09-03)._
  Two deliberate decisions, recorded so they aren't re-litigated blindly: **(a)** the original required-change
  proposed a full 5-state «correction bundle» state machine (`credit_draft|credit_pending|credit_failed|
  reversed|replacement_ready`) linking original+credit+replacement — NOT built; disproportionate at ~70
  docs/month. The shipped fix (a `reissued_from_invoice_id` link + `isLegallyReversed()` + a soft-warn)
  covers the real duplicate-turnover risk without the machine. Revisit only if a tenant with routine,
  high-volume provider credits needs a richer correction workflow. **(b)** The replacement gate is a
  **soft-warn**, not a hard-block (operator choice): the operator can file a replacement while the reversed
  original still stands, after an explicit confirmation, and the act is logged. If double-turnover incidents
  ever show up in reconciliation, a per-tenant hard-block toggle is the escalation — cheap to add on top of
  the existing `replacementReversalPending()` predicate.
- **PROV-019 review P2 edge-notes** _(robustness-only, no live-flow impact; from the PROV-019 adversarial
  review)._ **(1)** `isLegallyReversed()` degenerate case: a VALID original whose `credited_total` came from
  a stale/ETL-written cache with NO live correlated credits reads as «ακυρώθηκε» (the "all live credits
  VALID" test is vacuously true). Harmless — recompute guarantees live credits exist when
  `credited_total ≥ payable`, and it matches pre-PROV-019 behavior — but a `->exists()` guard on
  `creditNotes()` would harden it. **(2)** Soft-deleting a still-standing original clears the warn:
  `reissuedFrom()` is a `belongsTo` on a SoftDeletes model, so a soft-deleted original resolves to null →
  `replacementReversalPending()` returns false while the AADE double-turnover risk persists. Edge (deleting a
  filed original isn't a normal flow); `->withTrashed()` on the relation would close it if it ever matters.
- **MYD-019 follow-up — 2-way delivery reconciliation (VALID/un-cancel direction)** _(P2, declined in the
  MYD-019 review as a conscious scope call)._ `refreshStatus()` auto-applies only the terminal AADE
  `CANCELLED` direction; it does NOT un-cancel a δελτίο that is locally `CANCELLED` while AADE reports it
  valid — the symmetric, delicate direction that `SyncInvoiceStateFromAade` does for invoices behind an
  operator-confirmed reconciliation action. Not reachable for δελτία today (a delivery `mydata_state`
  only becomes `CANCELLED` via a real AADE cancel or the new remote-sync, so there is no "wrongly
  cancelled" state to strand), so no repair path is needed yet. If a delivery-side reconciliation console
  ever lands, give it the same operator-confirmed un-cancel as the invoice twin.
- **MYD-019 concurrency/diagnostic cosmetics** _(P2 survivors of the MYD-019 review, all
  concurrency/staleness-only, DB state always correct)._ (a) On two racing `refreshStatus` calls (a
  double-click, or a manual «Έλεγχος κατάστασης» overlapping the scheduler), the LOSER re-reads the
  note as already-terminal under the lock and returns `state_synced=false`, so `ViewDeliveryNote` shows
  the green «Καμία αλλαγή» toast instead of the remote-cancellation warning — while `refreshFormData`
  flips the on-screen state to cancelled (mildly contradictory for that one toast; the winner's toast is
  correct). (b) `applyRemoteCancellation`'s post-commit `Log::info` uses the pre-transaction `$logFrom`
  snapshot (in-memory `$note`) while the STATE_SYNC audit row records the from-values read fresh under
  the lock — under a stale `$note` the diagnostic log and the audit row can disagree on the pre-sync
  state (the audit row is authoritative). Both fixable by having the winner branch return the applied
  outcome + the locked from-values; not worth the extra plumbing for a rare race.
- **`refreshStatus()` downgrades the `'partial'`/`'failed'` delivery_state cache** _(P2, CONFIRMED,
  pre-existing — surfaced by the Slice 1 review, NOT introduced by it)._ The refresh guard
  (`DeliveryLifecycleService::refreshStatus`, ~L341) protects only `cancelled`/`CANCELLED`/`'returned'`
  from being overwritten by the AADE-mapped state. `deliveryStateFromAade()` now CAN emit `'partial'`
  (DeliveredByCarrier split, 2026-09-13) as well as `in_transit_return` — so refresh keeps a carrier-PARTIAL
  note in `'partial'` idempotently (AADE keeps reporting DeliveredByCarrier). The residual downgrade risk is
  a note locally in `'partial'`/`'failed'` that a later refresh maps elsewhere (e.g. AADE `COMPLETED →
  'delivered'`) — the same downgrade the `'returned'` clause now blocks. (`IN_TRANSIT_RETURN(9)` maps to `'in_transit_return'` since Slice 2 — that state is NOT in
  this bucket: it is AADE/carrier-reported and non-terminal, so it SHOULD follow refresh.) Related
  pre-existing edge: a return leg the operator never closes with `confirmReturn` but the carrier
  completes → refresh reports `COMPLETED → 'delivered'` («Παραδόθηκε»), misrepresenting a returned
  shipment. The authoritative outcome still lives in the `CONFIRM_OUTCOME`/`CONFIRM_RETURN`
  `delivery_marks` rows + lifecycleHistory, so this is cache-fidelity only (no legal/money impact). Fix
  when touched: treat the operator-declared outcome states (`partial`/`failed`/`delivered`) as terminal
  in the same guard, or derive the guard from "is this state locally-authoritative" rather than listing.
- **Slice-2 follow-up: `confirmReturn` reachable-from** _(CONFIRMED vs DGM v2.0.2 §3.2.7)._ ✅ **DONE**
  — `CONFIRM_RETURN_FROM_STATES` = `rejected/partial/failed/in_transit_return`. Sandbox-settled: `in_transit`
  pruned (AADE [828]); `rejected`/`failed`/DeliveredByCarrier(`partial`) all CONFIRMED accepted two-party
  (`docs/delivery-two-party-sandbox.md`). **Issuer-side PARTIAL answered:** the whole issuer-side
  `confirmDelivery()` is a [833]/[817]/[814] dead-end (outcome is recipient/carrier-only) → gate it out of
  the issuer flow (still-open punch-list item, TIER-1 delivery).
- **Strict tenant scope** — _audited 2026-06-11: **0 live leaks** σε ~54 entry points· το no-op default είναι σωστό/load-bearing. Έγινε το φθηνό hardening (StockService explicit company_id· SweepOrphanMailLogs explicit withoutGlobalScope· CLAUDE.md rule). Το enforcement (null→throw) **deferred**: naive flip σπάει ~18 ασφαλή explicit-where paths· execution-time tripwire false-positives σε relation/eager-load FK queries. Re-open μόνο αν εμφανιστεί πραγματικό leak ή μεγαλώσει πολύ το CLI surface._
- **WHMCS outbound push — «claimed-but-lost» recovery** _(from the 2-way payment-sync double review, M1)._
  `WhmcsPaymentPusher` claims the `whmcs_payment_pushed_at` marker **before** the WHMCS write (prevents a
  double mark-paid under a manual-vs-auto race) and releases it on a caught failure. If the worker is
  **hard-killed** (OOM/deploy) in the µs window between the claim and the HTTP send, the marker stays set
  but WHMCS was never paid → the invoice drops off every worklist and no UI can re-drive it (fix today =
  manual `whmcs_payment_pushed_at = null`). Very low probability (the WHMCS `transid` dedup already makes a
  re-push double-pay-safe). Options if it ever bites: an operator «Επανάληψη push» action that clears the
  marker, or a reconcile pass that resets a stale-claimed row still Unpaid at WHMCS.
- **ΑΦΜ identity — P2 survivors of the PR #394 review loop** _(consciously parked, per the
  per-priority round cap in CLAUDE.md)._ (a) `Afm::uniqueKey('VAT123456789')` (label glued to the
  number, no separator) keeps the letters → key `VAT123456789`, so such a legacy row is not deduped
  against `123456789` (same as pre-PR; the migration does not refuse it). Fold it only if the prod
  `.fbk` shows the pattern — `SELECT afm FROM customers WHERE afm REGEXP '^(VAT|AFM|TIN)[0-9]'`.
  (b) `CompanyImporter` reads the tenant's customers 3× per phase (existingIndex / afmKeyIndex /
  customerOwners) — one `get()` could feed all three; only matters at tens of thousands of customers.
- **CHANGELOG `[Unreleased]`: δύο `### Fixed` blocks** (προϋπάρχει στο main) — το `ekdosi:release`
  τα ρολάρει αυτούσια στη δατεδ έκδοση. Ένωσέ τα στο επόμενο release cut (όχι σε PR feature, γιατί
  μετακινεί ~50 άσχετες γραμμές και θάβει το diff).
- **Bulk συγχώνευση πελατών** _(follow-up του `customers:merge`)._ Σήμερα ένα ζευγάρι τη φορά (σωστό:
  ο χειριστής αποφασίζει ποιος επιζεί). Αν ποτέ βρεθεί βάση με δεκάδες διπλά, ένα `--all --yes` που
  τρέχει τη default επιλογή (ο «γεμάτος» επιζεί) για κάθε ομάδα του `customers:afm-duplicates`.
- **Operator picker helper** _(P2 από το review του Leads L3)._ Το `$tenant->users()->orderBy('name')
  ->pluck('users.name','users.id')` ζει σε ~6 σημεία (LeadForm/LeadsTable/SalesActivityReport ×2/
  `InteractsWithLeadViews`)· το LeadForm προσθέτει και τον τρέχοντα super_admin (δεν είναι στο pivot).
  Ένα `Company::operatorOptions()` όταν ξαναπιαστεί κάποιο από αυτά.
- **CSV export helper** _(P2 από το review του Leads L2)._ `AgedReceivables`, `SalesActivityReport`,
  `LedgerBookExporter`, `AiUsage` και `CustomerStatementCsv` κουβαλούν το ίδιο BOM + formula-guard +
  `fputcsv(';')`. Το PHP 8.4 `escape:` deprecation **λύθηκε παντού** (ρητό `escape: ''` σε ΟΛΕΣ τις
  κλήσεις — επιβεβαιώθηκε με grep 2026-09-06)· απομένει μόνο το DRY — ένα κοινό
  `App\Support\Csv::stream()` τώρα που υπάρχουν 5 σημεία CSV.
- ~~**ETL (`migrate:firebird`) — διπλό ΑΦΜ ΜΕΣΑ στη legacy πηγή = hard stop**~~ **ΕΓΙΝΕ** (2026-09-10):
  το `--afm-keep=CUST_ID` υλοποιήθηκε ακριβώς όπως γράφτηκε εδώ (ρητή απόφαση χειριστή, ο άλλος μπαίνει
  με `afm_key=NULL` + ⚠), μαζί με `--dry-run` preflight και το ίδιο check στο «Έλεγχος σύνδεσης».
  Η αρχή μένει: **το ETL δεν διαλέγει νικητή μόνο του** — χωρίς `--afm-keep` το run σταματά με τη λίστα.
  Το κίνητρο ήταν ότι η legacy βάση πρέπει να μείνει **read-only αρχείο** (και η μία πραγματική
  περίπτωση σε παραγωγή είναι legacy «υποκατάστημα = δεύτερη εγγραφή με ίδιο ΑΦΜ»). Ό,τι απέμεινε:
  - **Bulk/UI για parked πελάτες.** Σήμερα το `--afm-keep` είναι CLI-only και το parked state φαίνεται
    μόνο στο `customers:afm-duplicates`. Αν ποτέ βρεθεί βάση με δεκάδες τέτοια: επιλογή keeper μέσα από
    τη φόρμα εισαγωγής + badge «χωρίς ταυτότητα ΑΦΜ» στη λίστα πελατών.
  - **Οι parked πελάτες δεν φαίνονται μετά από ΕΠΙΤΥΧΗ εισαγωγή από το panel** _(P2, 3ος γύρος
    review)._ Τα ⚠ «μπαίνει ΧΩΡΙΣ ταυτότητα ΑΦΜ» βγαίνουν στο stdout του `migrate:firebird`, που το
    `RunFirebirdImport` διαβάζει μόνο σε αποτυχία. Ο χειριστής βλέπει την ΑΠΟΦΑΣΗ (το `afm_keep` στη
    σελίδα της εισαγωγής) αλλά όχι ΠΟΙΕΣ γραμμές πάρκαραν — θέλει `php artisan customers:afm-duplicates`.
    Σωστή λύση: badge «χωρίς ταυτότητα ΑΦΜ» + φίλτρο στη λίστα πελατών (panel-native), όχι άλλη στήλη
    στο `firebird_import_runs`.
  - **Το ΑΦΜ probe διαβάζει έως 50.000 γραμμές CUSTOMER μέσα σε σύγχρονο Livewire request** _(P2,
    3ος γύρος review)._ Το `AFM_PROBE_MAX_ROWS` φράζει το πλήθος, όχι τον χρόνο μεταφοράς σε αργό WAN
    (≈3 MB στο μέγιστο· οι πραγματικοί tenants είναι μερικές χιλιάδες). Αν ποτέ κολλήσει: μέτρησε
    πρώτα `COUNT(DISTINCT AFM)` server-side ή κάνε το probe queued job με ειδοποίηση.
  - **`customers:merge --branch=N`** — όταν ο parked δίδυμος είναι όντως υποκατάστημα και ο χειριστής
    θέλει να τον συγχωνεύσει, τα παραστατικά που μεταφέρονται θα μπορούσαν να πάρουν
    `counterpart_branch=N`. **Προσοχή**: ΜΟΝΟ για μη-υποβεβλημένα — ένα ήδη filed παραστατικό στην ΑΑΔΕ
    έχει υποβληθεί με branch 0 και δεν πρέπει να αποκλίνει τοπικά (ίδιο θέμα με το snapshot divergence
    παραπάνω).
- **Issuer name/address snapshot — το υπόλοιπο του MYD-024 (P2, συνειδητά deferred)** _(η **σειρά**
  πάγωσε 2026-09-02· το ΑΦΜ/ΓΕΜΗ βγάζει πλέον προειδοποίηση· MYD-024)._
  Το issuer block **τιμολογίου** στην ΑΑΔΕ είναι μόνο `vatNumber + country + branch` (τα `[219]`/`[220]`
  **απαγορεύουν** όνομα/διεύθυνση για ελληνικό μέρος), οπότε αλλαγή επωνυμίας/έδρας/ΔΟΥ/ΚΑΔ **δεν**
  μπορεί να ξαναγράψει υποβληθέν τιμολόγιο. Μένουν δύο πραγματικά αλλά περιορισμένα σημεία: (α) το
  **9.x δελτίο αποστολής** ΟΝΤΩΣ φέρει issuer name+address, (β) τα **PDF** παράγονται από το ζωντανό
  `Company`, οπότε επανεκτύπωση παλιού παραστατικού μετά από μετακόμιση δείχνει τη νέα διεύθυνση.
  Αλλάζει η **αναπαράσταση** ενός παρελθόντος εγγράφου, όχι η κατατεθειμένη ταυτότητά του — το
  απομακρυσμένο αρχείο μένει ανέπαφο. Ξανα-άνοιγμα όταν κάποιος tenant αλλάξει πραγματικά έδρα.
- **Filing-policy snapshot — το υπόλοιπο του MYD-018 (P2, συνειδητά deferred)** _(η **σειρά** πάγωσε
  2026-09-02· MYD-018)._ Δεν παγώνουν ακόμα: `mydata_type`, income
  classification ανά γραμμή, quantity flag, payment-method mapping, κωδικός ΦΠΑ/απαλλαγής. Αυτά
  αλλάζουν το **περιεχόμενο** του payload, όχι την **ταυτότητά** του — δεν μπορούν να προκαλέσουν
  διπλή υποβολή ή χαμένο recovery (αυτό ήταν το P0 και έκλεισε), είναι συνειδητές πράξεις
  παραμετροποίησης, και το `mydata:preflight` ήδη τα ελέγχει. Ειδικά το `mydata_type` **δεν είναι
  μονόγραμμη**: ο `SalesReconciler` τεκμηριώνει fallback που στηρίζεται στο ότι είναι null για
  ETL-imported γραμμές, οπότε το να γράφεται νωρίτερα απαιτεί να ξαναγίνει κι εκείνο το μονοπάτι
  στην ίδια αλλαγή. Ξανα-άνοιγμα αν χειριστής αλλάξει `mydata_type` σε τύπο με ανοιχτά πρόχειρα.
- **Provider-side exactly-once — το υπόλοιπο του MYD-021 (→ PROV-001)** _(από το review round 5 του
  MYD-021/022)._ Το `GrProviderSubmitter` πήρε **lock** (κλείνει το double-click/overlapping
  auto-issue), αλλά **όχι** τον durable pre-POST marker: εκεί ο marker είναι άχρηστος χωρίς
  **provider-side επαλήθευση** — δεν έχει νόημα να σημάνεις «σε αμφιβολία» αν δεν μπορείς να
  ρωτήσεις τον πάροχο τι έγινε. Το `EInvoiceProviderTransport::status()` **υπάρχει ήδη** (InvoSign
  `invoice_status.php`), οπότε το PROV-001 είναι κυρίως wiring. Δεύτερο σκέλος: το **9.x δελτίο μέσω
  παρόχου** ανακτάται σήμερα διαβάζοντας **ΑΑΔΕ**, αλλά ένα provider-filed δελτίο φτάνει εκεί μετά
  το relay του ΥΠΑΗΕΣ — άρα το grace των 10' (κουρδισμένο για το άμεσο ERP feed) μπορεί να είναι
  μικρό και να οδηγήσει σε δεύτερη έκδοση. Το `status()` δέχεται `Invoice`, οπότε χρειάζεται
  delivery-note δίδυμο. **Μέχρι τότε**: το lock + το `unverifiableInDoubt()` (άρνηση μέσα στο
  παράθυρο) καλύπτουν το ρεαλιστικό σενάριο· ένα hard kill σε provider tenant παραμένει ακάλυπτο.
- **`ExpenseClassificationSubmitter` γράφει μη-fillable `'date'`** _(από το review round 3 του MYD-025)._
  Το `expense_marks.mark_date` μένει έτσι πάντα NULL για **δικές μας** υποβολές χαρακτηρισμού, οπότε
  δεν συνεισφέρουν στο εύρος ημερομηνιών του `LegalEvidence::describe()`. Κοσμητικό (η μέτρηση είναι
  σωστή), αλλά είναι γνήσιο bug στον submitter — μία γραμμή, εκτός scope του MYD-025.
- **Legal-retention hardening — το υπόλοιπο του MYD-025 (P2)** _(η σιωπηλή διαγραφή έκλεισε
  2026-09-02)._ Δεν έγιναν: (α) **`restrictOnDelete`** στα `mydata_marks`/`delivery_marks` αντί για
  cascade — χρειάζεται πιο ακριβή κανόνα απ' ό,τι εκφράζει ένα FK, γιατί ένα **DRY_RUN mark** θα
  έκανε ένα απλό πρόχειρο αδιάγραφο· (β) **archival/soft-delete tenant** (ο finding ζητά «αρχειοθέτηση
  αντί διαγραφής») — είναι feature, όχι δικλείδα, και η διαγραφή είναι πλέον συνειδητή· (γ) UI action
  για το `company:export-pdfs` (σήμερα CLI — σωστά, γιατί χιλιάδες PDF δεν χωράνε σε HTTP request·
  θα ήθελε queued job + download). Ξανα-άνοιγμα αν χρειαστεί αρχειοθέτηση tenant χωρίς διαγραφή.
- **Bulk-delete guard** — single-record guarded (PR #258)· `DeleteBulkAction`/`ForceDeleteBulkAction` αφύλακτα.
- **Soft-deleted FK rows render blank** — `withTrashed()` label + «deleted» badge για rows πριν τον guard.
- _**`GrProviderSubmitter::cancel()` non-9.3 guard** — ✅ SHIPPED 2026-07-07: service-level hard-refuse με μήνυμα «έκδοσε πιστωτικό (5.1)» για κάθε τύπο ≠ 9.3, ώστε μη-UI callers (automation/bulk) να μη χτυπούν opaque `[283]`. (Το UI ήδη γκρεϊτάρει το `cancel_at_mydata` σε 9.3-only.) Βλ. `mydata-sandbox-myd2-retry-2026-07-07.md`._

## 💡 PDF / UX & ideas
- **«Πρόχειρα»: legacy-imported stuck-drafts δεν φαίνονται στην καρτέλα, αλλά ΕΙΝΑΙ editable αλλού
  (P2, από review draft-edit flow)** — το section «Πρόχειρα» στην καρτέλα πελάτη χρησιμοποιεί το κοινό
  `onlyUnissuedDrafts` (αποκλείει `legacy_id`, όπως το dashboard tile & τα money totals), ενώ ο πίνακας
  Παραστατικά, το κουμπί «Επεξεργασία» στο ViewInvoice και το `EditInvoice::mount` επιτρέπουν
  επεξεργασία **οποιουδήποτε** draft (μόνο `local_status`/`mydata_state`), ανεξαρτήτως `legacy_id`. Άρα
  ένα legacy-imported παραστατικό με κολλημένο `local_status='draft'` επεξεργάζεται από τη λίστα αλλά
  ΔΕΝ βρίσκεται από την καρτέλα του πελάτη. Επιλογή: είτε να δείχνει η καρτέλα και τα legacy stuck-drafts
  (χαλαρώνει το κοινό scope — αγγίζει dashboard/money semantics), είτε να αποκλείει το edit-gate τα
  legacy drafts (αλλαγή συμπεριφοράς που ίσως κάποιος βασίζεται). Edge case (κανονικά δεν υπάρχουν
  legacy drafts)· χρειάζεται απόφαση προϊόντος πριν αγγίξουμε το κοινό `onlyUnissuedDrafts`.
- _(G10 «ένα template αντί 8»: **non-issue** — τα 8 legacy FastReport δεν πορτάρονται· έχουμε
  ήδη καθαρά Blade ανά τύπο. **Δίγλωσσο/EN output: ✅ shipped** — βλ. «Done recently».)_
  Προαιρετικό μελλοντικό cleanup: κοινό layout partial στα 4 PDF templates (χαμηλή αξία).
- **«Show all / browse» picker** (search-beyond-typing) στους product/customer pickers —
  να ξεφυλλίζεις όλον τον κατάλογο χωρίς πληκτρολόγηση. _(Tags σε **γραμμές**: dropped — δεν
  έχει use case· μια γραμμή δεν είναι οντότητα που ταξινομείς. Tags σε **πελάτες/προϊόντα**
  ήδη υπάρχουν.)_
- **Curated tax-presets** expansion ανά κλάδο + **%-ανά-προϊόν** (όχι μόνο €/τεμ).
- **Seeder «προϊόντα με θεσμικό τέλος»** — _✅ SHIPPED 2026-06-17: «Πρότυπα τελών» selective-import στη
  λίστα Προϊόντων (`LeviedProductTemplates` + `ImportLeviedProducts`) — σακούλα €0,07 / πλαστικά €0,04 /
  ανακύκλωσης €0,08 / διαμονής, προ-ρυθμισμένα με myDATA Τέλη §8.7· idempotent._
- **`clear:right`** σε single-word doc-types (PDF tweak).

## 🧰 Setup / onboarding helpers (from-zero — sweep 2026-06-17)
_Ήδη: **web installer `/install`** (from-zero σε φρέσκο host: `.env`+`APP_KEY`+`migrate`+super-admin,
fail-closed/self-disabling, filesystem-token gate — βλ. FEATURES §17) · `ekdosi:install` wizard (CLI) ·
`MyDataLookupSeeder` (VAT/invoice types/payment-delivery methods/aims/units, με one-click
`StandardLookupSeedAction` ανά resource) · `DemoCompanySeeder` · GSIS/VIES lookup · `suppliers:sync` ·
«Πρότυπα τελών». Ιδέες για ευκολότερο στήσιμο από το 0:_
- **`ekdosi:install --bundle` partial-failure recovery** _(P2, from the install-bundle review)._ If the
  importer COMMITS the company but its post-commit role provisioning throws, `installFromBundle` fails before
  attaching the admin, and a re-run can't finish: `--new` refuses an existing slug. The importer already prints
  «τρέξε shield:sync-super-admin», and manual attach + that command recover it, but there's no clean re-run path.
  Fix = on a `--force` re-run where the slug exists, attach the admin + re-provision (or route through `--into`)
  instead of refusing. Rare (role provisioning seldom throws after a clean company commit) — but on the WEB
  installer it USED to throw deterministically (Spatie's permission cache bound to the boot-time sqlite default,
  fixed by `InstallController::resetBootstrappedCaches`), so this recovery gap was hit on every real bundle
  install until that fix. Still worth closing for the residual cases; workaround remains empty DB + fresh `/install`.
- **Web installer — follow-ups:** (α) προαιρετικό `CREATE DATABASE` όταν ο DB χρήστης έχει δικαίωμα (τώρα
  απαιτεί προ-δημιουργημένη κενή βάση — το σωστό default σε shared hosting)· (β) ~~auto-detect writable
  dirs / PHP extensions ως preflight βήμα~~ **DONE** (`RequirementsChecker`, incl. `env_writable` OPS-002)·
  (γ) optional «γράψε το cron line / systemd unit» helper αντί για απλή λίστα ελέγχου (μερικώς: `ops:cron`).
- **OPS-002 residual — exotic-host writability false-positive** _(P2, from the OPS-002 round-2 review)._ The
  `env_writable` preflight is a read-only `is_dir`+`is_writable` stat, which `access()` can misreport on a
  few host classes (POSIX ACL mask, SELinux/AppArmor, NFS `root_squash`, a remounted-ro overlay): it passes,
  the DB gets built, then `EnvWriter::write()` fails — the exact half-install OPS-002 guards, on those hosts
  only. **Bounded + backstopped:** the retry is idempotent (no duplicate tenant/admin) and the operator gets
  a clear «διόρθωσε δικαιώματα ρίζας και ξαναπροσπάθησε» message. A truly faithful test needs an actual
  temp-file+`rename()` write-probe, which was deliberately NOT taken (side-effects on the code root, per the
  OPS-002 round-1 review). Revisit only if a real host hits it.
- **Generic CSV importer (προϊόντα / πελάτες)** — bulk onboarding από άλλο σύστημα (έχουμε CSV *export*
  `CsvEntityExporter`· λείπει το *import*). Column-map + dry-run preview + tenant-scope. _Το μεγαλύτερο
  win για μεταφορά καταλόγου/πελατολογίου._
- **Setup profiles ανά κλάδο** (λιανική / εστίαση / ξενοδοχείο / υπηρεσίες) — bundle σε ένα κλικ: invoice
  types + default ΦΠΑ + σχετικά «πρότυπα τελών» (ξενοδοχείο → διαμονής· λιανική → σακούλα/ανακύκλωσης) +
  payment methods. Πάνω στο υπάρχον seeding.
- **Curated tax-presets** (βλ. PDF/UX ideas) — withholding/Ψηφιακό Τέλος Συναλλαγής presets ανά κλάδο για το per-invoice
  «Τυπικά τέλη/φόροι».
- **Κατάλογος συνήθων υπηρεσιών** (hosting/domain/SSL…) για WHMCS-style tenants — προαιρετικό template.

## 📡 myDATA sync — insights & next (sweep 2026-06-16)
_Από το interface sweep. Το **#1 Outbox** + **dashboard tiles** + **#2 διερεύνηση** πιάνονται
ΤΩΡΑ (βλ. `claude/polish-touches`)· τα παρακάτω είναι το follow-up._
- **#2 console split — «εκτός τρέχοντος καναλιού» vs «πραγματικά ανεπιβεβαίωτο».** _✅ SHIPPED (ήταν
  ήδη χτισμένο, η σημείωση ήταν stale): το `missingAtAade` σπάει mode-aware σε **imported legacy ΜΑΡΚ**
  (`legacy_id` set → prod MARK που το sandbox δεν επιστρέφει = ενημερωτικό) vs **native** (πραγματική
  ασυμφωνία). `SalesReconciliationResult::{imported,real,noise}MissingAtAade()` + `discrepancyCount()`
  εξαιρεί το sandbox-noise· η κονσόλα δείχνει banner + «Λείπουν από AADE» (μόνο real) + collapsed
  «Εισαγμένα (ΜΑΡΚ άλλου καναλιού)»· 3 unit tests (`SalesReconciliationResultTest`)._
- **#5 χαρακτηρισμός εισροών — rules-engine ✅ SHIPPED** («Κανόνες χαρακτηρισμού» + `ExpenseClassifier`:
  auto-apply στο import + bulk «Εφαρμογή κανόνων» + worklist «Προς χαρακτηρισμό» + «Δημιουργία κανόνα»).
  **Μένει deferred:** η **υποβολή για λογαριασμό τρίτου** (λογιστής) που θέλει `entityVatNumber` [323]
  (βλ. «Expenses — λογιστής/`entityVatNumber`»). Ιδέα: ekdosi **ετοιμάζει** τους χαρακτηρισμούς, ο
  λογιστής (δικό του login + ΑΦΜ + έγκριση) τους **στέλνει** — θέλει διερεύνηση ρόλων/δικαιωμάτων.
  _(#6 Βιβλίο→period report + Panel utility CSS: ✅ SHIPPED — βλ. «Done recently».)_

## 🔎 MCP forensics για το cutover — **OBS-001** (bucket A, SHIPPED)
**✅ SHIPPED** τα 5 read-only tools (`invoice_filing`, `mydata_failures`, `stuck_documents`,
`mydata_discrepancies`, `preflight`, βάση `ForensicMcpTool`) — βλ. `CHANGELOG.md` [Unreleased] +
`FEATURES.md §16γ` + `MCP.md`. Τα στοιχεία υπήρχαν ήδη (byte-exact XML ανά προσπάθεια)· προστέθηκε η
πρόσβαση. **Μένει (προαιρετικό, φθηνό):**
- _(**δομημένη INFO γραμμή ανά ΕΠΙΤΥΧΗ έκβαση**: ✅ SHIPPED — `App\Support\EInvoice\FilingLog::filed`,
  καλείται από το ένα success choke-point κάθε submitter (direct myDATA + πάροχος): invcode+ΜΑΡΚ στο
  ίδιο το μήνυμα (grep σε οποιοδήποτε), κανάλι/τύπος/διάρκεια στο context. Τώρα το
  `log_tail --contains=<invcode>` βρίσκει και τις ΕΠΙΤΥΧΕΙΣ υποβολές, όχι μόνο τις αποτυχίες.)_
- **delivery-mark forensics**: το `invoice_filing`/`mydata_failures` καλύπτουν τα `mydata_marks`
  (τιμολόγια)· τα `delivery_marks` (ΔΑ) έχουν δικό τους ιστορικό — να επεκταθούν όταν μπει η ΔΑ ροή.

## 🧾 MYD-007 follow-ups (per-line §8.3 model shipped)
Το per-line snapshot + guidance helper + preflight block **✅ SHIPPED**. Μένουν:
- **Type↔reason cross-check στην έκδοση** — προειδοποίηση/μπλοκ αν η αιτία γραμμής αντιφάσκει με τον
  τύπο (π.χ. αιτία 4 σε εγχώριο 1.1, ή αιτία 14 σε υπηρεσία 2.2). Υπάρχει ήδη `assertCounterpartCountryMatchesType`
  για χώρα↔τύπος· να προστεθεί το αντίστοιχο αιτία↔τύπος.
- **WHMCS inbox mapper** — οι προγραμματιστικά δημιουργημένες 0% γραμμές δεν ορίζουν per-line αιτία
  (πέφτουν στο tenant-wide fallback)· να περνά την αιτία από τον τύπο/κατηγορία.
- **Cleanup υπαρχόντων tenants** — το preflight ΕΠΙΣΗΜΑΙΝΕΙ τις reason-less/λάθος 0% κατηγορίες· να
  γίνει operator-review pass (χωρίς auto-guess ιστορικών αιτιών).
- **Ιστορικές γραμμές**: το migration `..._000021_backfill_zero_vat_line_exemption` γεμίζει την αιτία
  ανά γραμμή για tenants με ΜΙΑ μόνο αιτία 0% (unambiguous)· tenants με πολλές/καμία → preflight review.
- **Scenario-picker UI** — το `VatExemptionGuidance::scenarioOptions()`/`SCENARIOS` (πλήρης αναφορά,
  test-guarded) να συνδεθεί σε picker «Τι είδους 0%;» στη φόρμα κατηγορίας ΦΠΑ (σαν το DeliveryGuidance),
  ώστε ο χειριστής να διαλέγει σενάριο αντί για ωμό κωδικό. Σήμερα wired μόνο το `recommendForType()`.
- **Auto-suggest ordering** — η πρόταση §8.3 στη γραμμή ενεργοποιείται στην αλλαγή συντελεστή· αν ο
  χειριστής βάλει 0% ΠΡΙΝ επιλέξει τύπο, το πεδίο μένει κενό (υποχρεωτικό → μπλοκάρει save· ασφαλές).
  Follow-up: re-suggest και στην αλλαγή `invoice_type_id`.

## 🧾 MYD-006 follow-ups (business classification policy shipped)
Το `business_activity_type` + `ClassificationGuidance` + go-live gate + credit inheritance **✅ SHIPPED**. Μένουν:
- **Per-LINE income-class snapshot** — ✅ **SHIPPED** (WHMCS-bridge PR): στήλες
  `invoice_lines.mydata_income_class(+_category)`, ο `AadeInvoiceDocument::resolveIncomeClass` τις διαβάζει
  ΠΡΩΤΕΣ, ο WHMCS mapper τις γεμίζει, ΚΑΙ ο `IssueCreditNote` τις αντιγράφει στη γραμμή του πιστωτικού
  (ώστε ένα credit να αντιστρέφει την ΙΔΙΑ §8.6 κατηγορία). Manual invoices δεν επηρεάζονται (null → παλιά
  resolution). Μένει προαιρετικό: UI για χειροκίνητο per-line override στη φόρμα παραστατικού.
- **Mixed per-category enforcement** — το go-live gate κάνει PASS-με-υπενθύμιση για μικτό tenant (η
  ΕΠΙΛΟΓΗ πολιτικής είναι το blocking act· η ανά-κατηγορία ταξινόμηση είναι καθοδήγηση, όχι hard block —
  μια all-services κατηγορία νόμιμα μένει null). Δεν κάνει FAIL/WARN σε count of null overrides (θα ήταν
  θόρυβος). Follow-up: αν χρειαστεί σκλήρυνση, ένα goods/services flag στο ProductCategory θα επέτρεπε
  ακριβή απαίτηση «κάθε goods κατηγορία ταξινομημένη».
- **Policy re-application σε πιστωτικά** — η πολιτική εφαρμόζεται στις γραμμές πιστωτικού ΟΠΩΣ και στο
  αρχικό (product_id αντιγράφεται από τον `IssueCreditNote`, οπότε product-linked γραμμές κληρονομούν
  μέσω override· free-text→free-text παίρνουν και τα δύο την πολιτική) → συνεπές. Η ΜΟΝΗ απόκλιση: αν ο
  tenant ΑΛΛΑΞΕΙ `business_activity_type` μεταξύ έκδοσης αρχικού και πιστωτικού. Ο οριστικός fix
  (per-line income-class snapshot, παραπάνω) το κλείνει· μέχρι τότε αποδεκτό (mid-life αλλαγή είδους
  δραστηριότητας είναι σπάνια + αμφιλεγόμενο ποιο είναι το «σωστό»).
- **WHMCS «Εισερχόμενα» γραμμές → κατηγορία εσόδων** — ✅ **SHIPPED** (group-first χάρτης): σελίδα
  «Αντιστοίχιση WHMCS (έσοδα)» + `WhmcsIncomeMap` (scope group|product) + `WhmcsIncomeClassifier` +
  per-line stamp· plugin feed v0.44.0 δίνει `whmcs_product_id`/`whmcs_group_id`. Μένουν:
  - **Product-level override UI** — το schema (scope=product) + ο resolver το υποστηρίζουν ήδη· λείπει
    ΜΟΝΟ το UI (δεύτερο section στη σελίδα: πακέτα μιας ομάδας με δικό τους select). Group-only σήμερα.
  - **Non-hosting lines** — το plugin enrichment λύνει pid/gid μόνο για HOSTING γραμμές· domains (δεν
    είναι προϊόντα) + addons μένουν χωρίς gid → πέφτουν στον τύπο. Τα domains είναι υπηρεσίες ούτως ή
    άλλως (category1_3 από τον τύπο)· addon-mapping = follow-up αν χρειαστεί.
  - **Native (plugin-less) tenants** — ο εμπλουτισμός γραμμής γίνεται στο plugin feed· ένας tenant που
    δεν χρησιμοποιεί το bridge για το inbox δεν παίρνει pid/gid ανά γραμμή (πέφτει στον τύπο). Ο κατάλογος
    (`GetProducts`) δουλεύει για όλους· το enrichment ανά γραμμή θέλει το bridge.
- **Seeder δεν εφαρμόζει την πολιτική** — στο fresh install το `business_activity_type` είναι null, οπότε
  ο seeder δεν μπορεί να εφαρμόσει την πολιτική στις seeded κατηγορίες προϊόντων· το go-live gate εξαναγκάζει
  την επιλογή. Follow-up (προαιρετικό): κατά την επιλογή πολιτικής, auto-apply το goods bucket στις
  Εμπορεύματα/Προϊόντα seeded κατηγορίες.

## 🖥️ Console/interface polish (B — sweep 2026-06-16)
- _(**Auto-refresh-on-stale** στην Κονσόλα myDATA: ✅ SHIPPED 2026-06-17 — stale banner >6h + opt-in
  `mydata:refresh-console` scheduled warmer (όλα τα snapshots, default OFF, σαν το VAT picture). FEATURES §3.)_
- **Per-row import + «held/needs-review» state** στην κονσόλα-Έξοδα (ήδη στο «myDATA/expenses completeness»)
  — τώρα που υπάρχει το selective picker στη λίστα Έξοδα, το ίδιο μοτίβο ταιριάζει και στην κονσόλα.
- _(**MARK lifecycle chip** — DROPPED 2026-06-17: η πληροφορία ήδη φαίνεται (badge `mydata_state` + ΜΑΡΚ
  + link σε MARK detail/XML + κουμπιά lifecycle + «Ιστορικό»)· ένα γραμμικό chip θα **αντέφασκε** με το
  μοντέλο των δύο ορθογώνιων καταστάσεων `local_status` × `mydata_state` — π.χ. ακυρωμένο τοπικά αλλά
  ακόμα VALID στην ΑΑΔΕ δεν χωράει σε ευθεία ακολουθία. Χαμηλή αξία + κίνδυνος σύγχυσης.)_

## 🧾 ERP-parity ideas (C — sweep 2026-06-16)
_Έχουμε ήδη: balances/Καρτέλα, τραπεζικοί λογαριασμοί, κανάλια είσπραξης (IRIS/vPOS/μετρητά), πληρωμές,
πιστωτικά, προσφορές, recurring services (v1)._
- **Dunning ladder** — κλιμακωτές αυτόματες υπενθυμίσεις ληξιπρόθεσμων (3/7/15/30 ημ.) πάνω στο υπάρχον
  auto-email + `InvoiceBalance` (σήμερα: single resend). Templates ανά σκαλί + opt-out ανά πελάτη.
- **Bank-statement import → match πληρωμών** — ανέβασμα κίνησης (CSV/MT940) → auto-match σε ανοιχτά
  τιμολόγια (ποσό/ημερομηνία/ΑΦΜ) → προτεινόμενες `Payment` εγγραφές προς έγκριση.
- **Per-customer εκπτώσεις/τιμές** — _✅ SHIPPED 2026-06-17 (έκπτωση + τρόπος-πληρωμής ανά πελάτη
  εφαρμόζονται στην έκδοση· τύπος-wins fallback)._ Το **per-product τιμοκατάλογο** (default τιμή
  μονάδας ανά προϊόν×πελάτη) **DROPPED** σκόπιμα: η **per-line έκπτωση** τη στιγμή της έκδοσης +
  το per-customer default discount καλύπτουν την ανάγκη· πίνακας `customer_product_prices` =
  over-engineering χωρίς πραγματικό use case (re-open μόνο αν εμφανιστεί).
- **Multi-currency invoicing** — `currency` υπάρχει στο payload (EUR hardcoded)· πραγματικό FX +
  στρογγυλοποίηση + εμφάνιση. (myDATA θέλει EUR ισοτιμία — προσοχή.)
  _(Aged-receivables + Sendable customer statement: ✅ SHIPPED — βλ. «Done recently».)_
- **Επαφές (shared CRM)** — κοινή οντότητα `Contact` ↔ many customers με ρόλους (π.χ. ένας λογιστής/
  γραφείο που εξυπηρετεί πολλούς πελάτες-πελάτη), αντί για τις σημερινές per-customer `customer_contacts`.
  Σκόπιμα DEFERRED («κρατάμε τις επαφές per customer να μην μπλέξουμε») — future CRM phase· να μη σπάσει
  το per-customer μοντέλο που χρησιμοποιεί ήδη ο Sendable statement.
