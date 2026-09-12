# Combined ΤΔΑ (Τιμολόγιο–Δελτίο Αποστολής) — design

> **Status:** DESIGN / proposal (Slice 3 of the myDATA v2.0.2 digital-delivery work). Not built.
> **Decision owner:** operator. **Author:** this doc is for review before any code.
> **Ties to:** `docs/BACKLOG.md` MYD-002 (Combined ΤΔΑ), MYD-003 (monetary vs movement split),
> `docs/aade/mydata-v2.0.2-changes.md` §C1, `docs/mydata-submit-payload.md` (the monetary payload).

## 0. TL;DR / the decision to approve

A **ΤΔΑ is a monetary invoice, not a delivery note.** It is a myDATA **type `1.1`** (Τιμολόγιο
Πώλησης) carrying **`isDeliveryNote = true`** + a movement header. So it lives with the other
**Παραστατικά** (`Invoice`), next to ΤΙΜ/ΤΠΥ/ΑΛΠ — **option «Α»** — NOT under «Ψηφιακή Διακίνηση»
(which is money-less 9.x `DeliveryNote`). The only thing shared with the ΔΑ world is the
**movement lifecycle** (RegisterTransfer → … → ConfirmDelivery/Return), which is keyed by the
`qrUrl` the `1.1` submission returns — so it applies to a ΤΔΑ unchanged.

**Recommended architecture:** `Invoice` gains the movement fields + lifecycle-cache columns and
implements a **`MovableDocument`** contract; `DeliveryLifecycleService` is generalised to that contract
(a real refactor — NOT «reuse verbatim»: the audit tables must become polymorphic and the
tenant/stock/cancel seams re-cut). **Money stays on `Invoice` (never duplicated); movement + its audit
become shared; a ΤΔΑ cancel goes through the monetary path, not the movement one.**

## 1. What a ΤΔΑ is — spec grounding

**The combined ΤΔΑ is NOT new and NOT gated on v2.0.2.** The `InvoiceHeader` fields that make a 1.1
also-a-delivery-note (`isDeliveryNote`, `otherDeliveryNoteHeader`, `otherMovePurposeTitle`,
`thirdPartyCollection`, `OtherDeliveryNoteHeaderType`) landed in **v1.0.8 (12/2/2024)** — the ERP
spec's own changelog (`…v2.0.2_official_erp.md` §"Έκδοση 1.0.8"), and firebed tags them
`@version 1.0.8` (`InvoiceHeader.php:419/433`). So a combined ΤΔΑ has been submittable since Feb 2024;
we simply never built the ekdosi side (MYD-002). v2.0.2 is just **the current spec we build against**,
and it adds two things that DO touch this design: **`withoutDigitalTransportTracking`** (a genuine
v2.0.2 `InvoiceHeader` field — lets a ΤΔΑ be issued with NO movement lifecycle, going straight to
*Completed*) and the `supportsDeliveryNote()` extension to 1.4/3.1/3.2/11.5.

Fields from the ERP spec (`…v2.0.2_official_erp.md`, tables — the md is a PDF conversion so the tree
diagrams are OCR-garbled; the field tables are reliable):

- **§5.3 `InvoiceHeader`** (L854–879, all optional): `isDeliveryNote` (bool, L878),
  `dispatchDate`/`dispatchTime` (L860–861), `vehicleNumber` (L862), `movePurpose` (int, L872),
  `otherDeliveryNoteHeader` (`OtherDeliveryNoteHeaderType`, L877), `otherMovePurposeTitle` (L879, only
  when movePurpose=19). Plus v2.0.2: `withoutDigitalTransportTracking`.
- **Note 13 (L929–935)** — verbatim the ΤΔΑ: *«Το πεδίο isDeliveryNote ορίζει αν πρόκειται για
  τιμολόγιο που είναι και δελτίο αποστολής (π.χ. το παραστατικό τύπου 1.1 … εφόσον φέρει την ένδειξη
  isDeliveryNote = true, τότε είναι και δελτίο διακίνησης και θα πρέπει να αποσταλούν και επιπλέον
  στοιχεία διακίνησης)».*
- **§5.3.2 `OtherDeliveryNoteHeaderType`** — `loadingAddress`, `deliveryAddress`,
  `startShippingBranch`, `completeShippingBranch` (the loading/delivery points of the combined doc).
- **Note 9 (L1169)** — *«Το πεδίο qrUrl επιστρέφει μόνο στις υποβολές παραστατικών τύπου από 1.1 έως
  11.5»*. So a ΤΔΑ-1.1 submission returns a **qrUrl** → the lifecycle key (when tracking is on).
- **Validation [280]** (L1470) — `dispatchDate` must be ≥ current date.
- The **movement lifecycle methods** (RegisterTransfer/…/ConfirmDeliveryReturn) are in the SEPARATE
  AADE *«Ψηφιακή Διακίνηση Αγαθών»* REST doc. **We must obtain the v2.0.2 version** (we hold only
  v2.0.1); it is the authority for the state machine + which states each call is reachable from (see
  §11-Q2 and the Slice-2 confirmReturn reachable-from check). The ERP md gives only the issue payload.

**Scope of types:** v2.0.2 `supportsDeliveryNote()` also allows 1.4/3.1/3.2/11.5, but the **classic
ΤΔΑ is `1.1`**. This design targets **1.1 only**; the rest are a later, trivial allowlist extension.

## 2. Current state — the gap (code grounding)

| Piece | Today | Evidence |
|---|---|---|
| `invoices` movement columns | **none** (all net-new) | `2026_05_26_000013_create_invoices_table.php` — only `delivery_date`, `delivery_method_id`, `distribution_aim_id` (money fields), counterpart snapshot |
| `invoices.is_delivery_note` | **none** | — |
| `AadeInvoiceDocument` (monetary builder) | sets only `series/aa/issueDate/invoiceType/currency` (+5.1 correlation); **no movement fields**; **rejects** movement types (MYD-003) | `app/Services/EInvoice/AadeInvoiceDocument.php:111–119`, `:92–100` |
| `Codes::allowsItemDescr()` | keys on 9.x type only | `app/Support/MyData/Codes.php:853–856` — MYD-002 says change to the flag |
| Movement header + lifecycle | live only on `DeliveryNote`/`DeliveryNoteSubmitter`/`DeliveryLifecycleService` | `create_delivery_notes_table.php`, `DeliveryLifecycleService.php` |
| ΤΔΑ invoice type | **removed from seed** (MYD-002) | `MyDataLookupSeeder.php:438–441` («no «ΤΔΑ» here …») |
| firebed `InvoiceHeader` | **fully supports** `setIsDeliveryNote` + `setMovePurpose`/`setDispatchDate`/`setDispatchTime`/`setVehicleNumber`/`setOtherDeliveryNoteHeader`/`setOtherMovePurposeTitle`/`setThirdPartyCollection` | `vendor/firebed/aade-mydata/src/Models/InvoiceHeader.php:435,307,259,275,291,412,456,480`; `OtherDeliveryNoteHeader.php:42,63,91,119` |

**Conclusion:** no vendor change needed; the whole feature is ekdosi-side (schema + builder + UI +
lifecycle generalisation).

## 3. The movement data splits in two (important)

| Group | When | Fields | Where in myDATA |
|---|---|---|---|
| **Issue-time** (planned) | at ΤΔΑ filing (the 1.1 submission) | `isDeliveryNote=true`, `movePurpose`(+`otherMovePurposeTitle`), `dispatchDate`/`dispatchTime`, `vehicleNumber`, `otherDeliveryNoteHeader`(loading/delivery address + shipping branches); optionally `withoutDigitalTransportTracking` (→ no lifecycle) | `InvoiceHeader` |
| **Lifecycle** (actual) | AFTER filing, keyed by `qrUrl` | `transportType` (1–7), `carrierVat`, `vehicleNumber` (actual) | RegisterTransfer `TransportDetails` (DGM), **not** the issue payload |

So a ΤΔΑ `Invoice` needs BOTH the issue-time movement header AND (for the lifecycle) the same
`transport_type`/`carrier_afm` + lifecycle-cache columns a `DeliveryNote` has.

> **`thirdPartyCollection` is NOT a ΤΔΑ field** (dropped after review): the spec accepts it **only for
> types 8.4/8.5** (POS-collection on behalf of third parties — a *payments* concept, `InvoiceHeader.php:470-475`).
> ekdosi's `delivery_notes.third_party_collection` («παραλαβή από τρίτο/μεταφορέα») is a **name
> collision** with a different meaning; do not carry it onto a 1.1 ΤΔΑ without sandbox confirmation.

> **`withoutDigitalTransportTracking` = the fork** (v2.0.2): true → the ΤΔΑ files and goes straight to
> *Completed*, no `qrUrl`/lifecycle. Default (tracking on) → `qrUrl` returned, full movement lifecycle
> applies. The ΤΔΑ form needs this toggle; §7 lifecycle only applies when tracking is on.

## 4. Architecture — options

### ✅ Option A1 (recommended) — `Invoice` is the movable document, with a polymorphic audit
`DeliveryLifecycleService` is **NOT reusable verbatim** — it is structurally bound to `DeliveryNote`
in four places that the contract must break (all verified in source):

1. **Audit-row FK (the real blocker).** `persistEvent`/`persistCancellation`/`applyRemoteCancellation`
   write `DeliveryMark::create(['delivery_note_id' => $note->id, …])` (`DeliveryLifecycleService.php:875,606,707`)
   and `syncLifecycleHistory` writes `DeliveryNoteEvent` (`:403`). Both columns are a hard
   **FK to `delivery_notes.id`** (`create_delivery_marks_table.php:22`, `create_delivery_note_events_table.php:24`).
   **An `Invoice` cannot populate `delivery_note_id`.**
2. **Tenant coherence** — `TenantCoherence::assertDeliveryNote($tenant, $note)` (`:121,202,257,295,463`), DeliveryNote-typed.
3. **Stock** — cancel calls `reverseSaleForDeliveryNote($note)` (`:635,739`); an invoice reverses via
   `reverseSaleForInvoice` (`InvoiceObserver.php:299`), so the DN hook is a no-op for a ΤΔΑ.
4. **Concrete-model queries** — `DeliveryNote::query()->lockForUpdate()` (`:688`),
   `DeliveryMark::query()->where('delivery_note_id', …)` (`:479`).

**The design (chosen, not deferred):**
- **Audit model = polymorphic.** Make `delivery_marks` + `delivery_note_events` **`morphs('movable')`**
  (`movable_type`/`movable_id`) instead of `delivery_note_id`; migrate existing rows to
  `movable_type=DeliveryNote`. The movement audit is conceptually about the MOVEMENT, so one home for
  both parents. This migration lives in **3a**, and it carries two easy-to-miss pieces (found in review):
  - **Preserve the dedup unique.** `delivery_note_events` has `unique(['delivery_note_id','dedup_key'])`
    (`create_delivery_note_events_table.php:37`) — it's what makes `syncLifecycleHistory`'s
    `updateOrCreate` idempotent on re-poll. Dropping `delivery_note_id` drops the unique → **duplicate
    lifecycle rows on every refresh**. 3a must recreate it as `unique(['movable_type','movable_id','dedup_key'])`
    AND change the `updateOrCreate` first-arg to the morph keys. (`delivery_marks`'s unique is
    `(company_id, legacy_id)`, not on the FK — safe.)
  - **`FiledSeriesBackfill` reads `delivery_marks.delivery_note_id` directly** (`FiledSeriesBackfill.php:45,57`),
    called by the re-runnable ETL (`MigrateFromFirebird.php:1103`) + a migration — NOT via the service.
    Dropping the column throws mid-ETL. 3a must either keep `delivery_note_id` additively **or** teach
    `FiledSeriesBackfill` the morph (`movable_type='DeliveryNote'` filter + `movable_id` join).
  - Model conversions (impl detail): `DeliveryMark::deliveryNote()`/`DeliveryNoteEvent::deliveryNote()`
    `belongsTo → morphTo`; `DeliveryNote::marks()/events()` `hasMany → morphMany`; both `$fillable` gain
    `movable_*`; ~20 test/seed writers of `delivery_note_id` updated.
- **`MovableDocument` contract** = every accessor + seam the service touches:
  `qrUrl`(`mydata_url`), `mydata_mark`, `mydata_state`, `delivery_state`, `transfer_mark`/`outcome_mark`/
  `return_mark`/`reject_mark`, `transport_type`, `vehicle_number`, `carrier_afm`, `local_status`,
  `invcode`, `company_id`, `issued_at`, `getKey()`, `forceFill`/save, the polymorphic `marks()`/`events()`
  relations, a `assertTenantCoherence($tenant)` seam, and a `reverseSaleForMovable()` stock seam.
  Both `DeliveryNote` and `Invoice` implement it.
- Generalise the service to type against `MovableDocument`; replace the four DeliveryNote-typed seams above.
- **Cancel is NOT part of this** — a ΤΔΑ cancels through the monetary path (§7), so the service's
  `cancel()` stays DeliveryNote-only.
- **Pros:** one document = one MARK = one qrUrl; money stack untouched; movement audit unified.
  **Cons:** a polymorphic migration on two audit tables + a real (not cosmetic) refactor of the service
  seams. Tests: the existing delivery-lifecycle suite must stay green against the contract.

### Option A2 (rejected, but its one real merit noted) — shadow `DeliveryNote` linked to the ΤΔΑ invoice
`delivery_notes.invoice_id` already exists, so a ΤΔΑ could spawn a linked `DeliveryNote` for its
movement. **Its ONE genuine advantage over A1:** the audit tables already FK `delivery_notes`, so a
shadow note needs **zero** audit rework (no polymorphic migration). **Still rejected:** a ΤΔΑ is **ONE**
myDATA document (one 1.1 submission, one MARK), but a real `DeliveryNote` files its own 9.x document — so
the shadow must be taught to NEVER submit, to borrow the invoice's MARK/qrUrl, and to not double-count
stock. That is a permanent half-entity plus a two-rows-one-truth invariant, versus A1's one-off
polymorphic migration. A1 wins, but the tradeoff is closer than the first draft implied.

### Option A3 (deferred) — polymorphic `document_movements` side table
Move the whole movement header + cache off both parents into `document_movements` (`movable_*`).
Cleanest long-term, but a much bigger refactor (migrate `DeliveryNote`'s existing movement columns too).
Not worth it just to unlock 1.1-ΤΔΑ; **defer** unless many movable types arrive. Note A1's polymorphic
audit is a step toward it.

## 5. Schema changes (A1)

New migration on **`invoices`** (all nullable; mirror `delivery_notes` names for a shared contract):
- **Flag:** `is_delivery_note` boolean default false (per-DOCUMENT, per spec note 13).
- **Issue-time movement:** `move_purpose` (tinyint), `other_move_purpose_title` (string 120),
  `dispatch_at` (datetime), `vehicle_number` (string 40), `third_party_collection` (bool default
  false); loading/delivery addresses `loading_street/number/postcode/city`,
  `delivery_street/number/postcode/city`, `start_shipping_branch`/`complete_shipping_branch` (uint).
  (`recipient_*` already covered by the counterpart snapshot + `counterpart_branch`; confirm mapping.)
- **Lifecycle:** `transport_type` (tinyint), `carrier_afm` (string 20); cache `delivery_state`
  (string 30), `transfer_mark`/`outcome_mark`/`return_mark`/`reject_mark` (string 50).
  **`invoices` ALREADY has `mydata_url` (qrUrl), `mydata_state`, `mydata_mark`** (`create_invoices_table.php:51-53`)
  — so the net-new set is only the movement header + `delivery_state` + the four `*_mark` cache cols + `is_delivery_note`.
- **`invoice_types.is_delivery_note`** boolean default false — a «ΤΔΑ» type pre-sets the flag; the
  per-invoice column stays authoritative. **Extend the seeder's row-application code too:**
  `INVOICE_TYPE_SEED` rows carry only `{code,name,mydata_type,income…,is_credit?,goods?}`
  (`MyDataLookupSeeder.php:428-462`), so the new flag needs a new key + a line in the apply loop, not
  just the array (3a scope).
- **Audit tables → polymorphic** (the P1 fix, §4-A1): `delivery_marks` + `delivery_note_events`
  gain `morphs('movable')` and existing `delivery_note_id` rows migrate to `movable_type=DeliveryNote`.
  This is part of **3a**, not a later slice.

Reversible `down()`. No destructive change to invoice data; the audit-table morph backfills existing
rows deterministically. Backfill on `invoices`: none (new columns default NULL/false).

## 6. Issue payload — the combined 1.1 (`AadeInvoiceDocument`)

- When `invoice.is_delivery_note` is true: in addition to the current monetary header, call
  `setIsDeliveryNote(true)` + `setMovePurpose` + `setDispatchDate`/`setDispatchTime` +
  `setVehicleNumber` + `setOtherDeliveryNoteHeader(loading/delivery + branches)` +
  (`setOtherMovePurposeTitle` when movePurpose=19) + `setThirdPartyCollection`.
- **Relax MYD-003 precisely:** keep rejecting **pure 9.x** on the monetary path, but ALLOW a
  `1.1` (monetary) that has `is_delivery_note=true`. The guard currently keys on
  `Codes::isMovementOnlyType()` (9.x) — a ΤΔΑ is NOT movement-only (it is a 1.1), so it already passes
  the 9.x reject; the real work is EMITTING the movement header, not loosening the guard. Confirm no
  other gate blocks it.
- **Fix `Codes::allowsItemDescr()`** (MYD-002 follow-up) — **an API change:** it takes the type
  *string* today (`Codes.php:853`, called with the type at `AadeInvoiceDocument.php:203`); it must gate
  on the `is_delivery_note` flag (a combined 1.1 may carry `<itemDescr>`) — so pass the flag/invoice, not
  just the code. The call site already anticipates this (`AadeInvoiceDocument.php:194-201`).
- **Keep `<currency>`** (a ΤΔΑ is monetary — unlike pure 9.x which omits it and `isDeliveryNote`,
  `DeliveryNoteSubmitter.php:163–165`).
- Submit via the **existing `MyDataSubmitter`** (monetary path) — a ΤΔΑ is filed once as a 1.1. The
  returned MARK + qrUrl persist on the invoice (the lifecycle key).

## 7. Lifecycle + cancel ownership

**Tracking on (default):** a filed ΤΔΑ returns a `qrUrl` and enters the SAME state machine as a 9.3
(DGM v2.0.2 §1.2: Registered → RegisterTransfer → InTransit → ConfirmDeliveryOutcome/Return →
Completed). So the generalised `DeliveryLifecycleService` (§4-A1, via the contract — **not** verbatim)
drives RegisterTransfer/confirmDelivery/confirmReturn/refreshStatus on the ΤΔΑ invoice.

**Tracking off (`withoutDigitalTransportTracking=true`):** the ΤΔΑ files straight to *Completed*, no
`qrUrl`, no lifecycle — the movement actions must be hidden for it.

**Cancel is the exception — one owner (P1 fix):** a ΤΔΑ is ONE 1.1 document/MARK, so it cancels
through the **monetary path** (`MyDataSubmitter::cancel` → its single `finaliseCancellation()` choke-point,
the normal `ViewInvoice` «Ακύρωση»), NOT the movement `DeliveryLifecycleService::cancel`. **The design:**
extend `finaliseCancellation` so that for an `is_delivery_note` invoice it also reconciles
`delivery_state='cancelled'`. It need **not** call stock reversal explicitly — `InvoiceObserver`
already runs `reverseSaleForInvoice` on the `local_status → cancelled` transition
(`InvoiceObserver.php:280,299`), so stock moves once. The movement-lifecycle `cancel()` stays
DeliveryNote-only and refuses/redirects for an invoice-backed movable.

**Second cancel path — `refreshStatus` (the review's re-opener, must be handled too):** §12-3c
generalises `refreshStatus` to ΤΔΑ invoices, but a remote (portal-side) cancellation there is applied by
`applyRemoteCancellation()` (reached only from `refreshStatus`), which flips all three state fields,
reverses stock, and writes a `STATE_SYNC` row **into `delivery_marks`** — i.e. a full cancel OUTSIDE the
monetary choke-point, in the wrong audit table, and colliding with the monetary twin
`App\Services\MyData\SyncInvoiceStateFromAade` (whose whole job is invoice remote-cancel detection).
**The design:** for an invoice-backed movable, `refreshStatus`'s remote-cancel branch must delegate to
`SyncInvoiceStateFromAade` (the monetary choke-point), NOT the DN-typed `applyRemoteCancellation`. This
also resolves the §4 note about `reverseSaleForMovable()` — the only movement path that reverses stock is
this one, and for a ΤΔΑ it routes through the invoice reversal instead. (Design decision — not a sandbox
question.)

## 8. Type seed + legacy normalisation

- Re-add **`ΤΔΑ`** to `MyDataLookupSeeder::INVOICE_TYPE_SEED` (`:437–461`): `mydata_type='1.1'`,
  `is_delivery_note=true`, income/E3 from `Codes::typeDefaults('1.1')`, `show_on_menu=true`. Remove the
  «no ΤΔΑ here» comment. Seeding stays fill-empty (never overwrites operator edits).
- **Legacy import (nexon):** the imported `invoice_types` row for the old ΤΔΑ is «orphan» (wrong/empty
  `mydata_type`, no flag). Add a one-off normaliser (same pattern as `einvoice_provider_key`
  normalisation) mapping the legacy ΤΔΑ code → `mydata_type='1.1'` + `is_delivery_note=true`. Idempotent;
  never touches operator-edited rows.

## 9. UI / wizard (the confusion-killer)

- **Παραστατικά** create/edit of a 1.1: a toggle **«✓ Είναι και Δελτίο Αποστολής (ΤΔΑ)»** that reveals
  the movement-header sub-form (σκοπός, dispatch, vehicle, loading/delivery addresses, branches). The
  «ΤΔΑ» type pre-checks it. Lifecycle actions (Έναρξη διακίνησης κ.λπ.) appear on the invoice view once
  filed, mirroring `ViewDeliveryNote`.
- **Ψηφιακή Διακίνηση** create: a helper/tooltip — *«Αν το έγγραφο έχει αξία/ΦΠΑ → είναι ΤΔΑ· εκδώστε
  το από τα Παραστατικά με το ✓ ΔΑ. Εδώ μόνο καθαρά Δελτία Αποστολής (9.x) χωρίς αξία.»* — routes the
  operator and kills the «πού πάει το ΤΔΑ;» confusion.

## 10. Money + stock interactions (must verify)

- **Money:** a ΤΔΑ is a normal sale — `InvoiceBalance`/VAT breakdown/receivables/credit notes/payments
  apply unchanged (it already is an `Invoice`). No special-casing.
- **Stock:** a ΤΔΑ both **sells** and **dispatches** goods. Today stock reduction is tied to the sale
  (invoice) and delivery notes dedup via `delivery_notes.invoice_id`. A ΤΔΑ must reduce stock **once**
  (as the sale) — it must NOT be double-counted against a separate ΔΑ. Confirm `StockService` treats a
  ΤΔΑ as the single stock event (no shadow ΔΑ ⇒ A1 makes this simpler than A2).

## 11. Open questions / risks

1. **A1 vs A3** — recommend A1 now (with polymorphic audit); revisit A3 only if more movable types arrive.
2. ~~Is a separate RegisterTransfer required?~~ **RESOLVED** (DGM v2.0.2 §1.2, now in repo): a tracked
   ΤΔΑ enters the same Registered→RegisterTransfer→… machine as a 9.3; `withoutDigitalTransportTracking=true`
   skips it → *Completed*. See §7. Confirm on sandbox.
3. **Types beyond 1.1** (1.4/3.1/3.2/11.5) — out of scope for v1; trivial allowlist add later.
4. **recipient/loading vs counterpart address** — map the combined-doc addresses (`otherDeliveryNoteHeader`
   loading/delivery + branches) to the existing counterpart snapshot + the new loading/delivery fields
   without duplication.
5. **Sandbox NOW available** — the Β' Φάση DGM API is live in the AADE test env (2026-09, dev creds on
   hand), so the combined payload + lifecycle can finally be round-tripped before go-live — a change from
   the whole DGM stack's prior «NOT SANDBOX-VALIDATED» state.
6. **Priority** — **No tenant issues ΤΔΑ today** (MCP: myip/nexon cut ΤΙΜ/ΤΠΥ/ΑΛΠ); nexon will need it
   after its fresh migration + for completeness → «real but not on fire».
7. **Related Slice-2 fix (adjacent, being done alongside):** DGM v2.0.2 §3.2.7 lists ConfirmDeliveryReturn
   sources as Rejected / DeliveredByCarrier(PARTIAL) / FailedDelivery, and a bare InTransit ONLY for 9.2 or
   9.3-`reverseDeliveryNote`. For our **plain 9.3** that's `{rejected, partial, failed}` — a **reconcile**,
   not a clean «widen»: Slice 2's guard was `{in_transit, in_transit_return}` (overlap only at nothing the
   spec lists — `in_transit_return` is not a spec source at all). The fix (this branch) sets
   `CONFIRM_RETURN_FROM_STATES = {rejected, partial, failed, in_transit, in_transit_return}` — adds the 3
   real sources and KEEPS `in_transit`/`in_transit_return` as fail-safe pending the sandbox rehearsal
   (`docs/delivery-sandbox-rehearsal.md`), which will confirm whether AADE rejects them so we can prune.

## 12. Sub-slices (each: code → sandbox rehearsal → review → merge)

1. **3a — Schema + type + flag + audit morph:** migration for the `invoices` movement/cache cols +
   `invoice_types.is_delivery_note` (+ seeder apply-loop) + the **polymorphic `morphs('movable')` on
   `delivery_marks`/`delivery_note_events`** (backfill existing → DeliveryNote); re-add ΤΔΑ to the seed;
   legacy normaliser. Tests: seeding, normaliser idempotency, existing delivery suite green post-morph.
2. **3b — Issue payload:** `AadeInvoiceDocument` emits the combined header for `is_delivery_note`
   (incl. the `withoutDigitalTransportTracking` fork); `allowsItemDescr()` → flag; keep pure-9.x reject.
   Tests: golden combined-1.1 XML vs spec; MYD-003 still rejects pure 9.x. Sandbox: file a ΤΔΑ, confirm
   MARK + qrUrl (or straight-to-Completed when tracking off).
3. **3c — Lifecycle contract:** extract `MovableDocument` (incl. the audit/coherence/stock seams),
   generalise `DeliveryLifecycleService`, implement on `Invoice`; wire the monetary cancel to reconcile
   `delivery_state` + `reverseSaleForInvoice` (§7). Tests: existing delivery-lifecycle suite green against
   the contract + a ΤΔΑ drives RegisterTransfer/refresh. **No longer blocked** (Q2 resolved); the DGM doc
   only decides which actions surface (tracked vs `withoutDigitalTransportTracking`).
4. **3d — UI/wizard:** the Παραστατικά toggle + movement sub-form + lifecycle actions on the invoice
   view; the Διακίνηση helper/tooltip; re-offer ΤΔΑ in the picker.
5. **3e — Stock + polish:** confirm single stock event; PDF; docs (FEATURES/CHANGELOG); move MYD-002
   BACKLOG → FEATURES.

Each sub-slice is independently mergeable; 3a/3b deliver value (a filed ΤΔΑ) even before 3c lands.
