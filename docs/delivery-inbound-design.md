# Εισερχόμενα Διακίνησης — inbound (recipient-side) movement inbox

> **Status: DESIGN CHECKPOINT (not built).** This is the design artifact for **Slice 4** of the
> ψηφιακή-διακίνηση family — the *receiving* ekdosi's view of movements OTHERS filed against us.
> Everything to date (Slices 1–3, `docs/combined-tda-design.md`) is the **issuer** side: docs WE
> send. This slice is the mirror: docs where WE are the **counterpart** (recipient of the goods).
> Closes **BACKLOG TIER-1 item 1(c)** («receiving-ekdosi Εισερχόμενα Διακίνησης is net-new»).
>
> Read `docs/combined-tda-design.md` first (the issuer lifecycle + `MovableDocument` contract this
> builds beside), and the DGM authority `docs/aade/…_DeliveryNote_v2.0.2_preofficial.md`
> (§1.2 state machine, §3.2.7 reachable-from states, §7.1 InvoiceDeliveryStatus).

## 1. What this is (and is NOT)

A **read-first inbox** under **Ψηφιακή Διακίνηση** that shows the delivery documents other
businesses have issued **to us** — the goods we are *receiving* — and lets an operator take the
two recipient-side myDATA actions that exist: **reject** a delivery note and (physical-QR-gated)
**confirm its outcome**. It is the exact structural twin of the WHMCS **«Εισερχόμενα»**
inbox (`pending_whmcs_invoices`): a lightweight staging table fed by a scheduler-gated poll, an
operator-gated Filament list, no automatic mutation of anything legal.

It is **NOT**:
- an issuer flow (that is Slices 1–3 — `DeliveryNote` + combined ΤΔΑ on the `MovableDocument` audit).
- an **expense/έξοδα** importer (that is `ExpenseReconciler`/`ExpenseImporter` — the *monetary*
  view of docs filed against us). Inbound movement and inbound expense are **orthogonal reads of
  the same `RequestDocs` feed**: expenses care about net/VAT/classification; this cares about the
  **movement lifecycle** (`invoiceDeliveryStatus` + `deliveryLifecycle`). A single 9.x ΔΑ is a
  movement-only doc with zero money and never becomes an expense; a ΤΔΑ is both, but its money
  half is already the expense importer's job. **This slice never writes `expenses`.**
- a new audit surface. Inbound docs are **someone else's** legal documents — they do NOT belong in
  our `delivery_marks`/`delivery_note_events` polymorphic audit (which is for docs we *issue* and
  own the MARK for). They get their own staging table, like `pending_whmcs_invoices`.

## 2. The load-bearing constraint (empirically settled)

From the **two-party sandbox rehearsal** (`docs/delivery-two-party-sandbox.md`, myip⇄nexon,
2026-09-13) and confirmed against firebed 5.12.0's action signatures:

| Recipient action | firebed | Key needed | Actionable from the inbox? |
|---|---|---|---|
| **Reject** (`RejectDeliveryNote`) | `rejectUsingMark(int $mark, ?reason)` **or** `rejectUsingQrUrl(...)` | **MARK** ✓ | **YES** — RequestDocs gives us the MARK |
| **Status refresh** (`RequestDeliveryNoteStatus`) | `handle(int $mark, ?issuerVat)` **or** `handleUsingQrUrl(...)` | **MARK** ✓ | **YES** |
| **Confirm outcome** (`ConfirmDeliveryOutcome`) | `handle(DeliveryOutcome)` — `DeliveryOutcome` is **keyed on `qrUrl`** (`array_unshift($expectedOrder,'qrUrl')`) | **qrUrl** only | **only with the physical QR** |

**The decisive fact:** `RequestDocs` to the **counterpart** returns the document **by MARK — it
does NOT return the issuer's `qrCodeUrl`** (recorded in BACKLOG 1(c); the firebed *test stub*
`request-doc-with-delivery-lifecycle.xml` shows a `<qrCodeUrl>`, but the real counterpart feed
does not — the sandbox is authoritative over the stub). The qrUrl is printed on the **physical
delivery note / QR sticker on the goods**. Therefore:

- **Reject and Refresh are fully desk-actionable** — the inbox has the MARK.
- **Confirm-outcome cannot be done from the MARK alone.** It needs the qrUrl, which the operator
  only obtains by **scanning the QR on the received goods**. So the inbox's confirm action is a
  **scan-and-paste** (or camera-scan) flow, not a one-click-from-the-row flow.

This asymmetry is the whole shape of the UI (§5). We must **not** pretend confirm is a desk action.

> **Residual empirical checks** (cheap, against a real counterpart capture — `php artisan
> mydata:fetch-docs --tenant=<sandbox> --raw` on a delivery-bearing doc filed *to* the sandbox):
> - grep for `<qrCodeUrl>` — if AADE has *started* returning it to the counterpart in v2.0.2 (it did
>   not at the 2026-09-13 rehearsal), confirm-outcome upgrades to a desk action and the scan step
>   becomes optional. The design works either way — the qrUrl field is just pre-filled when present.
> - grep for `<otherDeliveryNoteHeader>` / `<invoiceDeliveryStatus>` (Slice-4a review P2-4) — the movement
>   filter's only hook for an inbound **combined 1.x ΤΔΑ** is these two elements (a pure 9.x is caught by
>   its type). If AADE strips them from the counterpart view like `qrCodeUrl`, an inbound ΤΔΑ would be
>   silently skipped; confirm they survive before relying on the filter for ΤΔΑ.
>
> **Neither blocks reject/refresh on a pure 9.x** (caught by type + MARK) — they gate the ΤΔΑ and confirm
> paths only. **These are verification steps, not blockers for shipping 4a/4b.**

## 3. Discovery (reuse, don't reinvent)

`RequestDocs` is **already** the machinery `ExpenseReconciler::fetchAadeDocs()` /
`MyDataFetchDocs` use to pull "docs others filed against us" for a tenant + date window, with
`FirebedCredentials::init($tenant)` + `getContinuationToken()` pagination. Slice 4 adds a **thin
filter + a different sink**:

- **Filter to movement-bearing docs:** keep a `RequestedDoc` `<invoice>` only when its
  `invoiceHeader.invoiceType` supports a delivery note — i.e. `Codes`/`AadeInvoiceType`
  `supportsDeliveryNote()` is true (the 9.x family + the 1.x ΤΔΑ types, 1.4/3.1/3.2/11.5, etc.),
  **and** it actually carries an `invoiceDeliveryStatus` / `otherDeliveryNoteHeader`. A plain
  1.1 invoice with no movement header is an expense, not an inbound movement — skip it here.
- **Sink into the staging table** (§4), keyed on `(company_id, mydata_mark)`, upserting the JSON
  snapshot + the parsed `invoiceDeliveryStatus` + `deliveryLifecycle` events on each poll. This is
  exactly `ExpenseImporter`'s idempotency contract (`(company_id, mydata_mark)` unique, withTrashed
  guard, `company_id` on every query/create — never a global-scope reliance).
- **Command:** `php artisan delivery:fetch-inbound --tenant=SLUG [--from --to --dry-run]`, mirroring
  `mydata:fetch-docs`. **Scheduler-gated, default OFF**, new flag
  `EKDOSI_SCHEDULE_DELIVERY_FETCH_INBOUND` → `config('ekdosi.schedule.delivery_fetch_inbound_*')`
  → `routes/console.php` `$scheduleEnabled('delivery_fetch_inbound_enabled')`, `withoutOverlapping`,
  a conservative cron (`0 */6 * * *`, same cadence as `mydata:fetch-expenses`). READ-ONLY: it only
  stages — it never rejects/confirms/mutates a legal state.

## 4. Data model — a dedicated staging table (twin of `pending_whmcs_invoices`)

**Decision (locked): a new lightweight `inbound_delivery_notes` table, NOT the polymorphic audit.**
Rationale in §1: these are other parties' documents; we hold no issued MARK for them; their state
lives at AADE, not in our issue lifecycle. The WHMCS inbox proved this pattern (stage → operator
acts → terminal), and it keeps the `delivery_marks`/`delivery_note_events` invariants (one owner,
one issued MARK) intact.

```
inbound_delivery_notes
  id
  company_id            FK companies, cascadeOnDelete            # tenant, on every query/create
  mydata_mark           string  (unique per company_id)          # AADE MARK — the idempotency key
  issuer_afm            string                                    # counterparty (the sender of goods)
  issuer_name           string  nullable
  supplier_id           FK suppliers nullable, nullOnDelete       # best-effort ekdosi-side match (by afm)
  invoice_type          string  (e.g. '9.3', '1.1')              # from invoiceHeader.invoiceType
  aa                    string  nullable                          # issuer's series/AA (display only)
  issue_date            date    nullable
  qr_code_url           string  nullable                          # normally NULL (counterpart feed);
                                                                  #   filled by scan when confirming
  aade_delivery_status  unsignedTinyInteger nullable              # §7.1 InvoiceDeliveryStatus code
  local_state           string  default 'new'                     # new|acknowledged|rejected|confirmed|
                                                                  #   cancelled_by_issuer  (OUR view)
  reject_mark           string  nullable                          # our RejectDeliveryNote MARK, once rejected
  outcome_mark          string  nullable                          # our ConfirmDeliveryOutcome MARK, once confirmed
  payload               json                                      # full RequestedDoc <invoice> snapshot
  lifecycle             json    nullable                          # parsed deliveryLifecycle events (display)
  last_fetched_at       timestamp nullable
  timestamps / softDeletes
  unique (company_id, mydata_mark)
```

- `local_state` is **our** disposition, deliberately separate from `aade_delivery_status` (the AADE
  truth) — the same two-orthogonal-statuses discipline as `local_status` × `mydata_state` on
  invoices. A refresh updates `aade_delivery_status`; an operator action updates `local_state`.
- `reject_mark`/`outcome_mark` avoid the vestigial-column mistake flagged in BACKLOG 1(b): they are
  **populated by the action that creates them** (our own reject/confirm MARK), not left write-only.
- No line detail table in the MVP — the movement is header-level; lines live in `payload` if ever
  needed (same "file what the operator saw" choice as the WHMCS payload JSON).

## 5. UI — `InboundDeliveryNoteResource` under Ψηφιακή Διακίνηση

A Filament **resource** (list + view; no create/edit — operators don't author inbound docs),
super-admin/operator-gated like the WHMCS inbox, tenant-scoped.

**List** columns: issuer (name/afm), type badge, AA, issue date, **AADE status badge**
(`DeliveryCodes::deliveryStatusLabel`), **local state badge**, last fetched. Filters: local_state,
AADE status, date range. A «Λήψη νέων» header action runs `delivery:fetch-inbound` on demand
(same as the WHMCS inbox's manual fetch).

**Per-row / view actions** — gated exactly by the §2 constraint:

1. **«Απόρριψη» (Reject)** — always available while `local_state ∈ {new, acknowledged}` and the doc
   is not already terminal at AADE. Modal: rejection reason (optional). Calls
   `RejectDeliveryNote::rejectUsingMark($mark, $reason)` → stamps `reject_mark`, `local_state=rejected`.
   **Desk-actionable — the whole point of the inbox.**
2. **«Έλεγχος κατάστασης» (Refresh)** — always available. Calls
   `RequestDeliveryNoteStatus::handle($mark, $issuerVat)` → updates `aade_delivery_status` +
   `lifecycle`; if AADE reports the issuer cancelled it, `local_state=cancelled_by_issuer`. Read-only.
3. **«Επιβεβαίωση παραλαβής» (Confirm outcome)** — **qrUrl-gated.** The action opens a modal that
   **requires a qrUrl** (pre-filled iff `qr_code_url` is set — normally it is not, so the operator
   pastes the scanned QR URL from the physical note; a "how to scan" helper line). Only on a valid
   qrUrl does it call `ConfirmDeliveryOutcome::handle($outcome)` → stamps `outcome_mark`,
   `local_state=confirmed`. The button copy + tooltip say plainly «απαιτεί σάρωση του QR του
   φυσικού παραστατικού» so no operator expects a one-click confirm. If §2's residual check finds
   AADE now returns the qrUrl to the counterpart, the field pre-fills and the scan step is optional.

**«Acknowledge»** (local-only, no AADE call) sets `local_state=acknowledged` — a pure worklist
convenience so an operator can mark "seen, goods received, nothing to file yet" without touching AADE.

Panel-CSS discipline: any custom badge/utility classes go in `resources/css/panel.css` (the panel
has no Tailwind utility layer) — reuse the existing delivery-state badge styles from the DeliveryNote
resource where possible.

## 6. Tenant safety & the CLI/queue scope rule

The fetch command + any job touches a tenant-owned model **outside** a Filament context, so it MUST
pick one of the three declared paths (CLAUDE.md): explicit `->where('company_id', …)` on every
`InboundDeliveryNote` query/write (the chosen path, matching `ExpenseImporter`), and
`FirebedCredentials::init($tenant)` per tenant. No global-scope reliance. `RejectDeliveryNote` /
`ConfirmDeliveryOutcome` are invoked from the panel (operator has a tenant context) but the service
still asserts the row's `company_id` matches the acting tenant before any AADE call (the
`assertMovementTenant` discipline from the issuer side).

## 7. Scope — MVP vs later

**MVP (this slice):**
- `inbound_delivery_notes` table + model (tenant-scoped, softDeletes).
- `delivery:fetch-inbound` command + scheduler wiring (default OFF) — READ-ONLY staging.
- `InboundDeliveryNoteResource` (list + view) with **Reject / Refresh / Acknowledge** (desk actions)
  and **Confirm-outcome** (qrUrl/scan-gated).
- Tests: fetch filters to movement-bearing docs only + idempotent upsert on `(company_id, mark)`;
  reject-by-MARK stamps state; refresh maps AADE status; confirm requires a qrUrl (rejects blank);
  tenant isolation.
- Docs: FEATURES §5 (inbound half), CHANGELOG, close BACKLOG 1(c).

**Explicitly OUT of the MVP (BACKLOG, revisit only on real demand):**
- **Receiving Note flow** (Δελτίο Ποσοτικής Παραλαβής, types 10.1/10.2 — `CancelReceivingNote`,
  `ReceivingNotePurpose`; BACKLOG 1 "new Receiving Note flow"). Separate document family; only if a
  tenant actually issues quantitative-receipt notes.
- **Auto-linking an inbound movement to a matching inbound expense** (`ExpenseImporter` row for the
  same MARK). Nice reconciliation, but the two feeds are orthogonal (§1) and no tenant needs the
  cross-link today. Forward-only `mydata_mark` makes it a cheap later join if asked.
- **Camera QR scanning in-browser** for confirm — MVP is paste-the-scanned-URL; a JS scanner is a
  portal-side (real Tailwind/Vite) enhancement, not panel work.
- **Group QR** (`GenerateGroupQrCode`/`RequestGroupQrDetails`) — issuer-side batching, not recipient.

## 8. Sub-slices (each: code → sandbox rehearsal → review → merge)

1. **4a — Table + model + fetch command (staging only). ✅ BUILT (PR #540).** Migration,
   `InboundDeliveryNote` model (tenant scope, casts, `supplier` relation), `delivery:fetch-inbound`
   reusing the `RequestDocs` pagination from `ExpenseReconciler`, the movement-bearing filter, scheduler
   wiring (OFF). **No AADE mutation.** Rehearsal still to run: `--raw` against the sandbox counterpart
   feed → confirm the qrUrl / `otherDeliveryNoteHeader` presence (§2 residual checks).
2. **4b — Resource + desk actions (Reject / Refresh / Acknowledge). ✅ BUILT.** `InboundDeliveryNoteResource`
   (list + view, «Διακίνηση» group, nav badge) + `InboundDeliveryService` (reject-by-MARK / refresh /
   local acknowledge, tenant-assert, shared `DeliveryEventSnapshot`) + `InboundDeliveryNotePolicy` +
   operator permission. Post-deploy: `shield:generate` + re-provision. Rehearsal still to run: reject a
   real sandbox inbound 9.x (nexon⇄myip); verify the issuer sees it rejected; refresh reflects it.
3. **4c — Confirm-outcome (qrUrl/scan-gated) + polish. DEFERRED (BACKLOG).** The qrUrl modal, the
   copy/tooltip. Build only when a tenant actually receives digitally-tracked movements to confirm.

Each sub-slice is independently mergeable; 4a is invisible staging, 4b makes the inbox useful, 4c
adds the QR-gated confirm.

## 9. Open questions for the operator (checkpoint)

1. **Confirm-outcome worth building at all for us?** We are almost always the *issuer* (myip/nexon
   send goods). Do our tenants ever *receive* digitally-tracked movements they must confirm? If
   "rarely/never", 4c (the QR-scan confirm) can be deferred to BACKLOG and the MVP ships as
   **4a+4b (stage + reject + refresh)** only — smaller, all desk-actionable, no scan UX.
2. **Poll default:** OFF (opt-in, like expenses) is my assumption. Agree? Any tenant that should
   have it ON from day one?
3. **Naming:** «Εισερχόμενα Διακίνησης» for the menu label — consistent with WHMCS «Εισερχόμενα». OK?
