# Ψηφιακή Διακίνηση — TWO-PARTY sandbox validation (myip ⇄ nexon)

> **Purpose:** close the gap left by the single-tenant rehearsal
> (`docs/delivery-sandbox-rehearsal.md`), which proved the ISSUER side but hit **[833]
> «Only the recipient or carrier can confirm delivery outcome»** on every
> `ConfirmDeliveryOutcome`. With a **second sandbox tenant** acting recipient/carrier we
> drive a δελτίο into each recipient/carrier-produced state and empirically settle
> `CONFIRM_RETURN_FROM_STATES` and the `DELIVERED_BY_CARRIER` mapping.
>
> **Run from a terminal Claude Code session on the artisan host** (both tenants' sandbox
> myDATA credentials + outbound HTTPS to `mydataapidev.aade.gr`).

## 0. The two parties

| | **myip** — ISSUER | **nexon** — RECIPIENT / CARRIER |
|---|---|---|
| ΑΦΜ | `800561849` | `801280908` |
| einvoice_provider | gr-provider (InvoSign) | gr-mydata (direct) |
| sandbox creds | ✅ | ✅ |

> **⚠️ nexon is a LIVE production tenant** put on **sandbox** only for the rehearsal.
> **Revert it to production the moment the rehearsal is done** (`mydata_mode=production`,
> `mydata_read_env=auto`). Its production creds are never touched. Keep the window short;
> while in sandbox nexon must not issue any real παραστατικό.

## 1. Recipient/carrier calls are RAW firebed (not our service)

`DeliveryLifecycleService` is **issuer-scoped** (`TenantCoherence::assertDeliveryNote`),
so the recipient/carrier side is driven with **raw firebed**, keyed by the **qrUrl**
(`delivery_notes.mydata_url`), with **nexon's** credentials in `MyDataRequest::init`:

```php
$nexon = App\Models\Company::where('slug', 'nexon')->firstOrFail();
[$aadeId, $subKey] = $nexon->mydataCredentials(App\Enums\MyDataMode::Sandbox);
Firebed\AadeMyData\Http\MyDataRequest::init($aadeId, $subKey, 'dev');   // dev = mydataapidev.aade.gr
```

`MyDataRequest` holds credentials in **static** state → run each side in its OWN
`php artisan tinker --execute='…'` (fresh process = fresh creds). There is no callback:
myDATA is request-response, ERP-initiated; the recipient/carrier "confirms" by an
**outbound** call keyed by the qrUrl (scanned off the QR) or discovered via `RequestDocs`.

---

## §Findings — RAN 2026-09-13 (myip ISSUER ⇄ nexon RECIPIENT/CARRIER)

### confirmReturn source-state matrix (now empirically complete)

| Target | How reached | AADE status → our mapped state | confirmReturn? |
|--------|-------------|-------------------------------|----------------|
| `rejected` | nexon(recipient) `RejectDeliveryNote` | REJECTED → `rejected` | ✅ OK (deliveryReturnMark) |
| `failed` | nexon(**carrier**) `ConfirmDeliveryOutcome(NONE)` | FAILED_DELIVERY → `failed` | ✅ OK (deliveryReturnMark) |
| `partial` | nexon(**carrier**) `ConfirmDeliveryOutcome(PARTIAL)` | DELIVERED_BY_CARRIER → `delivered` **(was the bug)** → now `partial` | ✅ AADE accepts (raw myip proved it) |
| happy | nexon(recipient) `ConfirmDeliveryOutcome(FULL)` | COMPLETED → `delivered` | N/A (terminal) |

### Role & authorization rules (empirical)

- **RECIPIENT** = the 9.3 counterpart ΑΦΜ (`recipient_afm`). **CARRIER = whoever CALLS
  `RegisterTransfer`** (the credentials), NOT the declared `carrierVatNumber` field —
  myip declaring `carrierVatNumber=nexon` but calling itself → AADE still treated the
  caller (myip) as carrier, and nexon's outcome then got **[833]**. nexon self-calling
  RegisterTransfer → nexon became the carrier ✓.
- **[817]** «Only the carrier can set outcome to NONE» — the recipient cannot declare failure.
- **[814]** PARTIAL requires `deliveredPackaging` (a `PackagingDetail`) — our
  `DeliveryOutcome` builder does not set it (issuer never files PARTIAL anyway).
- **P2-2 (fixed):** DeliveredByCarrier is the SAME §8.22 status for a carrier FULL and a
  carrier PARTIAL delivery — only the lifecycleHistory `ConfirmOutcome` detail carries
  FULL/PARTIAL. §3.2.7 lists DeliveredByCarrier(PARTIAL) as a valid ConfirmDeliveryReturn
  source, and AADE **accepted** the return from that state (posted a deliveryReturnMark).
  The old map collapsed `DELIVERED_BY_CARRIER → 'delivered'` (∉ CONFIRM_RETURN_FROM_STATES)
  → the operator could never close the return. Fixed: `deliveryStateFromAade` now reads the
  lifecycleHistory and maps a carrier PARTIAL → `partial`, a carrier FULL → `delivered`.

### Recipient discovery (for a future «Εισερχόμενα Διακίνησης»)

1. **Discovery without a QR scan — YES.** nexon `RequestDocs(mark="0", dateFrom, dateTo)`
   returns the 9.3s where nexon is the **counterpart** (invType `9.3` + `MARK` + issuerVat).
   Carrier-only notes (nexon = carrier, someone else the counterpart) do **NOT** appear —
   `RequestDocs` is counterpart-scoped.
2. **qrUrl for confirm — NO, only the MARK.** Neither the `RequestDocs` `Invoice` nor
   `RequestDeliveryNoteStatus`(by MARK) expose the qrUrl (`DeliveryNoteStatusResponse` has
   `getInvoiceMark/getStatus/getLifecycleHistory`, no `getQrUrl`). Consequence:
   `RejectDeliveryNote::rejectUsingMark` works → **reject-from-feed is possible**, but
   `ConfirmDeliveryOutcome` is **qrUrl-only** → confirm-receipt needs the QR scan / handoff.

### Punch-list (code)

1. **DONE (this PR):** `DeliveryLifecycleService::deliveryStateFromAade` splits
   `DELIVERED_BY_CARRIER` by the ConfirmOutcome detail (PARTIAL → `partial`, else `delivered`).
2. **TODO:** issuer-side `confirmDelivery()` (UI «Δήλωση παράδοσης») is a [833]/[817]/[814]
   dead-end — gate it out of the issuer flow (it is not the issuer's call).
3. **TODO:** `delivery:test-lifecycle --return` calls confirmReturn from `in_transit`
   (already pruned → [828] dead path); rework or drop that flag.
4. **Future:** a receiving-ekdosi «Εισερχόμενα Διακίνησης» is a net-new recipient-side flow
   (our service is issuer-only). Reject can key by MARK; confirm needs the qrUrl.
