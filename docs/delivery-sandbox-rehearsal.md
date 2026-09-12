# Ψηφιακή Διακίνηση — sandbox rehearsal (Β' Φάση)

> **Purpose:** empirically validate the digital-delivery lifecycle against the **AADE test
> environment** (Β' Φάση, live since Σεπ 2026), now that a real sandbox exists. The whole DGM stack was
> shipped **`NOT SANDBOX-VALIDATED`** (`DeliveryLifecycleService` docblock) against firebed reference
> shapes only. This runbook drives it end-to-end and records what AADE actually accepts.
>
> **Run it from a terminal Claude Code session on the artisan host** (needs `pdo_firebird` not required
> here, but DOES need the tenant's **sandbox myDATA credentials** + outbound HTTPS to
> `mydataapidev.aade.gr`). This remote/web session has neither, so «--execute» is done from your terminal.

## 0. Prerequisites (once)

- A tenant in **sandbox** mode with valid **test** credentials:
  `php artisan mydata:set-credentials --tenant=<slug> --test` (key = hidden prompt), then
  `php artisan mydata:preflight --tenant=<slug>` → exit 0.
- At least one **9.3 Δελτίο Αποστολής** issued to the sandbox (gets a MARK + qrUrl):
  `php artisan delivery:test-submit <deliveryNoteId> --execute` (or «Έκδοση» in the panel). Confirm
  `mydata_state=VALID`, non-empty `mydata_mark` + `mydata_url`.

## 1. The questions this rehearsal must answer

1. **`confirmReturn` source states (the open Slice-2 question).** DGM v2.0.2 §3.2.7 lists the issuer's
   ConfirmDeliveryReturn «Προηγούμενη Κατάσταση» as **Rejected / DeliveredByCarrier(PARTIAL) /
   FailedDelivery**, and a bare **InTransit only for 9.2 or 9.3-with-`reverseDeliveryNote`**. Our notes
   are **plain 9.3**. The code (`DeliveryLifecycleService::CONFIRM_RETURN_FROM_STATES`) currently allows
   `rejected/partial/failed` **plus** `in_transit`/`in_transit_return` — the latter two kept only as
   fail-safe. **Determine empirically:** does AADE accept ConfirmDeliveryReturn on a plain 9.3 from
   `in_transit`? from `in_transit_return`? (If it rejects them, prune them from the constant.)
2. **`confirmDelivery(PARTIAL)` by the issuer.** v2.0.2 says PARTIAL is «μόνο … από τον μεταφορέα». Our
   `confirmDelivery` is the issuer's call — **does AADE reject issuer-side PARTIAL?** (If so, that's a
   separate follow-up: gate PARTIAL out of the issuer flow.)
3. **The full happy path** round-trips: RegisterTransfer → ConfirmDeliveryOutcome(FULL) → status
   COMPLETED; and the return path: … → (a failure) → ConfirmDeliveryReturn → status COMPLETED +
   `deliveryReturnMark`.
4. **`refreshStatus` (GetDeliveryNoteStatus)** returns a coherent status + lifecycleHistory, including
   any `IN_TRANSIT_RETURN` / return events, and (v2.0.2) the **`qrUrl` variant** if wired.
5. (later, for ΤΔΑ / Slice 3) a **combined 1.1 with `isDeliveryNote=true`** files, returns a MARK +
   qrUrl, and drives the same lifecycle — and the `withoutDigitalTransportTracking=true` variant goes
   straight to Completed.

## 2. Commands (the harness — all already built)

```bash
php artisan delivery:test-submit <id> --execute              # file a 9.3 to sandbox (MARK + qrUrl)
php artisan delivery:test-lifecycle <id> --execute           # ΕΝΑΡΞΗ → ΠΑΡΑΔΟΣΗ(FULL) → ΕΛΕΓΧΟΣ, writes a .txt report
php artisan delivery:test-lifecycle <id> --execute --return  # ΕΝΑΡΞΗ → ΕΠΙΣΤΡΟΦΗ → ΕΛΕΓΧΟΣ
php artisan delivery:test-lifecycle <id> --execute --cancel  # + ΑΚΥΡΩΣΗ
php artisan delivery:sandbox-validate                        # scripted end-to-end validation, if present
php artisan mydata:test-submit <invoiceId> --execute         # (for the ΤΔΑ combined-1.1 test, Slice 3)
```
Every call writes a report under `storage/app`; the raw request/response XML is also kept per event in
«Ιστορικό myDATA» on the δελτίο.

## 3. The analytical prompt — paste into a terminal Claude Code session

> You are on the ekdosi artisan host. A tenant `<slug>` is in **sandbox** mode with valid **test**
> myDATA credentials, and `mydata:preflight --tenant=<slug>` exits 0. Your job: **empirically validate
> the Ψηφιακή Διακίνηση (Β' Φάση) lifecycle against the AADE test environment** and report exactly what
> AADE accepts vs rejects — do NOT reason from the spec alone; run the calls and read the real XML.
>
> Do this, recording every AADE response (statusCode + any error code/message) verbatim:
> 1. **Baseline happy path.** Issue a fresh 9.3 δελτίο (`delivery:test-submit <id> --execute`), then
>    `delivery:test-lifecycle <id> --execute` (RegisterTransfer → ConfirmDeliveryOutcome FULL →
>    GetDeliveryNoteStatus). Confirm COMPLETED. Save the report path.
> 2. **Return path.** On a fresh δελτίο, run `delivery:test-lifecycle <id> --execute --return`
>    (RegisterTransfer → ConfirmDeliveryReturn → status). Confirm it returns a `deliveryReturnMark` and
>    the status the spec claims (Completed). Record it.
> 3. **`confirmReturn` source-state matrix (THE key question).** For a plain 9.3, drive a δελτίο into
>    each of these states and then attempt ConfirmDeliveryReturn, recording accept/reject per state:
>    `rejected` (RejectDeliveryNote by the recipient), `failed` (ConfirmDeliveryOutcome NONE),
>    `partial` (ConfirmDeliveryOutcome PARTIAL — note also whether AADE even accepts issuer-side
>    PARTIAL), `in_transit` (right after RegisterTransfer, no outcome), and `in_transit_return` (if
>    reachable). **The bundled `delivery:test-lifecycle` only calls confirmReturn from `in_transit`, so
>    for the other states drive it directly from tinker** after reaching the state, e.g.
>    `php artisan tinker --execute='$n = App\Models\DeliveryNote::find(<id>); app(App\Services\Delivery\DeliveryLifecycleService::class, ["tenant" => $n->company])->confirmReturn($n);'`
>    (reach `failed`/`partial` via a real ConfirmDeliveryOutcome NONE/PARTIAL call, `rejected` via a
>    RejectDeliveryNote from the recipient — use separate δελτία so one AADE state doesn't block the next).
>    Report a table: {source state → ConfirmDeliveryReturn accepted? / AADE error}.
> 4. **Compare to code.** `DeliveryLifecycleService::CONFIRM_RETURN_FROM_STATES` currently =
>    `['rejected','partial','failed','in_transit','in_transit_return']`. State precisely which of these
>    AADE actually accepts for a plain 9.3, so we can prune the constant to the truth. If AADE rejects
>    `in_transit`/`in_transit_return`, that's the fix; if it rejects issuer-side `partial`, flag it.
> 5. **GetDeliveryNoteStatus.** Confirm `refreshStatus` maps the returned status correctly (esp.
>    `IN_TRANSIT_RETURN` → our `in_transit_return`) and that lifecycleHistory events parse (event types
>    incl. the v2.0.2 return events). Try the **qrUrl** lookup variant if available.
> 6. **Report** a concise findings doc: per call, the AADE statusCode + errors; the confirmReturn
>    source matrix; and a punch-list of code changes the empirics demand (with file:line). Do NOT change
>    code unless asked — this pass is to LEARN the sandbox truth first.

## 4. Feeding results back

Paste the terminal session's findings here (or the report `.txt` files). We then:
- prune `CONFIRM_RETURN_FROM_STATES` to the AADE-accepted set (its own small PR),
- fix any issuer-side PARTIAL / other rejections the matrix reveals,
- lift the `NOT SANDBOX-VALIDATED` docblock on `DeliveryLifecycleService` once green,
- and reuse the same harness for the ΤΔΑ combined-1.1 payload (Slice 3).
