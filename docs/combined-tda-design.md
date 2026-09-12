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
implements a small **`MovableDocument`** contract; `DeliveryLifecycleService` is generalised to that
contract so it drives both `DeliveryNote` and a ΤΔΑ `Invoice`. **Money stays on `Invoice`
(never duplicated); movement becomes shared.**

## 1. What a ΤΔΑ is — spec grounding (v2.0.2)

The v2.0.2 ERP spec is the enabling document — `isDeliveryNote` + the structured address header
are **v2.0.2 additions** (`docs/aade/myDATA_API_Documentation_v2.0.2_official_erp.md`):

- **§5.3 `InvoiceHeader`** (table, md L854–879) carries, all optional:
  `isDeliveryNote` (boolean, L878), `dispatchDate`/`dispatchTime` (L860–861), `vehicleNumber`
  (L862), `movePurpose` (int, L872), `otherDeliveryNoteHeader` (`OtherDeliveryNoteHeaderType`,
  L877), `otherMovePurposeTitle` (L879, only when movePurpose=19).
- **Note 13 (L929–935)** — verbatim the ΤΔΑ: *«Το πεδίο isDeliveryNote ορίζει αν πρόκειται για
  τιμολόγιο που είναι και δελτίο αποστολής (π.χ. το παραστατικό τύπου 1.1 … εφόσον φέρει την ένδειξη
  isDeliveryNote = true, τότε είναι και δελτίο διακίνησης και θα πρέπει να αποσταλούν και επιπλέον
  στοιχεία διακίνησης)».*
- **§5.3.2 `OtherDeliveryNoteHeaderType`** — `loadingAddress`, `deliveryAddress`,
  `startShippingBranch`, `completeShippingBranch` (the loading/delivery points of the combined doc).
- **Note 9 (L1169)** — *«Το πεδίο qrUrl επιστρέφει μόνο στις υποβολές παραστατικών τύπου από 1.1 έως
  11.5»*. So a ΤΔΑ-1.1 submission returns a **qrUrl** → the lifecycle key.
- **Validation [280]** (L1470) — `dispatchDate` must be ≥ current date.
- The **movement lifecycle methods** (RegisterTransfer/ConfirmDeliveryOutcome/…/ConfirmDeliveryReturn)
  are specified in the SEPARATE AADE doc *«Ψηφιακό Δελτίο Αποστολής»* (we hold v2.0.1 of it). The ERP
  md only gives the **issue payload**.

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
| **Issue-time** (planned) | at ΤΔΑ filing (the 1.1 submission) | `isDeliveryNote=true`, `movePurpose`(+`otherMovePurposeTitle`), `dispatchDate`/`dispatchTime`, `vehicleNumber`, `otherDeliveryNoteHeader`(loading/delivery address + shipping branches), `thirdPartyCollection` | `InvoiceHeader` |
| **Lifecycle** (actual) | AFTER filing, keyed by `qrUrl` | `transportType` (1–7), `carrierVat`, `vehicleNumber` (actual) | RegisterTransfer `TransportDetails` (DGM), **not** the issue payload |

So a ΤΔΑ `Invoice` needs BOTH the issue-time movement header AND (for the lifecycle) the same
`transport_type`/`carrier_afm` + lifecycle-cache columns a `DeliveryNote` has.

## 4. Architecture — options

### ✅ Option A1 (recommended) — `Invoice` is the movable document
- Add movement + lifecycle-cache columns to `invoices` (§5).
- Extract a **`MovableDocument`** contract = exactly what `DeliveryLifecycleService` reads/writes:
  `qrUrl` (`mydata_url`), `mydata_mark`, `mydata_state`, `delivery_state`, `transfer_mark`/
  `outcome_mark`/`return_mark`, `transport_type`, `vehicle_number`, `carrier_afm`, `local_status`,
  `invcode`, `company_id`, `forceFill`/save. Both `DeliveryNote` and `Invoice` implement it.
- Generalise `DeliveryLifecycleService` to type against `MovableDocument` instead of `DeliveryNote`.
- **Pros:** one document = one MARK = one qrUrl; orthodox modelling; money stack untouched; lifecycle
  code reused verbatim. **Cons:** touches the lifecycle service signatures + a migration on `invoices`
  (net-new columns). Tests: the existing delivery-lifecycle tests must still pass against the contract.

### Option A3 (alternative, cleaner long-term, bigger) — polymorphic `document_movements` side table
- A `document_movements` table (`movable_type`/`movable_id` + the movement header + lifecycle cache),
  shared by `DeliveryNote` and ΤΔΑ `Invoice`. **Pros:** no movement columns on either parent; single
  home. **Cons:** much bigger refactor (migrate `DeliveryNote`'s existing movement/cache columns into
  it, or live with two storage shapes); not worth it just to unlock 1.1-ΤΔΑ. **Defer** unless we later
  add many movable types.

### ❌ Option A2 (rejected) — shadow `DeliveryNote` linked to the ΤΔΑ invoice
`delivery_notes.invoice_id` already exists, so a ΤΔΑ could spawn a linked `DeliveryNote` for its
movement. **Rejected:** a ΤΔΑ is **ONE** myDATA document (one 1.1 submission, one MARK), but a real
`DeliveryNote` files its own 9.x document — so the shadow would either double-file (wrong) or be a
half-entity that must be taught never to submit and to borrow the invoice's MARK/qrUrl. More special
cases than A1, for no benefit.

## 5. Schema changes (A1)

New migration on **`invoices`** (all nullable; mirror `delivery_notes` names for a shared contract):
- **Flag:** `is_delivery_note` boolean default false (per-DOCUMENT, per spec note 13).
- **Issue-time movement:** `move_purpose` (tinyint), `other_move_purpose_title` (string 120),
  `dispatch_at` (datetime), `vehicle_number` (string 40), `third_party_collection` (bool default
  false); loading/delivery addresses `loading_street/number/postcode/city`,
  `delivery_street/number/postcode/city`, `start_shipping_branch`/`complete_shipping_branch` (uint).
  (`recipient_*` already covered by the counterpart snapshot + `counterpart_branch`; confirm mapping.)
- **Lifecycle:** `transport_type` (tinyint), `carrier_afm` (string 20); cache `delivery_state`
  (string 30), `transfer_mark`/`outcome_mark`/`return_mark`/`reject_mark` (string 50). `mydata_url`
  (qrUrl) — **check if `invoices` already stores qrUrl**; if not, add it (the lifecycle key).
- **`invoice_types.is_delivery_note`** boolean default false — so a «ΤΔΑ» type pre-sets the flag
  (convenience default), while the per-invoice column stays authoritative.

Reversible `down()`. No destructive change. Backfill: none (new columns, existing invoices keep NULL
/ false).

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
- **Fix `Codes::allowsItemDescr()`** (MYD-002 follow-up): gate on the `is_delivery_note` flag (a
  combined 1.1 may carry `<itemDescr>`) instead of the bare 9.x type.
- **Keep `<currency>`** (a ΤΔΑ is monetary — unlike pure 9.x which omits it and `isDeliveryNote`,
  `DeliveryNoteSubmitter.php:163–165`).
- Submit via the **existing `MyDataSubmitter`** (monetary path) — a ΤΔΑ is filed once as a 1.1. The
  returned MARK + qrUrl persist on the invoice (the lifecycle key).

## 7. Lifecycle (shared `MovableDocument` contract)

After a ΤΔΑ is filed (qrUrl present), the operator can run the movement lifecycle from the invoice:
RegisterTransfer → in_transit → ConfirmDelivery / ConfirmReturn → refreshStatus, cancel. Reuse
`DeliveryLifecycleService` verbatim via the contract (§4.A1). **Open:** whether AADE requires a
separate RegisterTransfer for a ΤΔΑ or treats the filed 1.1-with-movement as the transfer start —
**answer from the ΔΑ lifecycle doc + sandbox before building 7** (§11-Q2).

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

1. **A1 vs A3** — recommend A1 now; revisit A3 only if more movable types arrive.
2. **Is a separate RegisterTransfer required for a ΤΔΑ?** (does filing the 1.1-with-movement already
   start the movement, or is the DGM ΕΝΑΡΞΗ still needed?) — **answer from the ΔΑ lifecycle doc +
   sandbox first.** Gates §7.
3. **Types beyond 1.1** (1.4/3.1/3.2/11.5) — out of scope for v1; trivial allowlist add later.
4. **recipient/loading vs counterpart address** — map the combined-doc addresses to existing
   counterpart snapshot + the new loading/delivery fields without duplication.
5. **Not sandbox-validated** — like the rest of the DGM lifecycle, validate the combined payload on the
   AADE sandbox (dev creds available) before go-live. **No tenant issues ΤΔΑ today** (MCP: myip/nexon
   cut ΤΙΜ/ΤΠΥ/ΑΛΠ) — nexon will need it after its fresh migration; build is for that + completeness,
   so priority is «real but not on fire».

## 12. Sub-slices (each: code → sandbox rehearsal → review → merge)

1. **3a — Schema + type + flag:** migration (`invoices` movement/cache cols + `invoice_types.is_delivery_note`),
   re-add ΤΔΑ to the seed, legacy normaliser. Tests: seeding, normaliser idempotency.
2. **3b — Issue payload:** `AadeInvoiceDocument` emits the combined header for `is_delivery_note`;
   `allowsItemDescr()` → flag; keep pure-9.x reject. Tests: golden combined-1.1 XML vs spec; MYD-003
   still rejects pure 9.x. Sandbox: file a ΤΔΑ, confirm MARK + qrUrl.
3. **3c — Lifecycle contract:** extract `MovableDocument`, generalise `DeliveryLifecycleService`,
   implement on `Invoice`. Tests: existing delivery-lifecycle suite green against the contract + a ΤΔΑ
   drives RegisterTransfer/refresh. (Blocked on Q2.)
4. **3d — UI/wizard:** the Παραστατικά toggle + movement sub-form + lifecycle actions on the invoice
   view; the Διακίνηση helper/tooltip; re-offer ΤΔΑ in the picker.
5. **3e — Stock + polish:** confirm single stock event; PDF; docs (FEATURES/CHANGELOG); move MYD-002
   BACKLOG → FEATURES.

Each sub-slice is independently mergeable; 3a/3b deliver value (a filed ΤΔΑ) even before 3c lands.
