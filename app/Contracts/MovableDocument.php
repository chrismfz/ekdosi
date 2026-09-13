<?php

namespace App\Contracts;

use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A document that drives the myDATA e-transport (Ψηφιακή Διακίνηση) movement
 * LIFECYCLE — either a money-less 9.x `DeliveryNote` OR a monetary 1.1 Combined
 * ΤΔΑ `Invoice` (`is_delivery_note = true`). Combined ΤΔΑ, Slice 3c
 * (`docs/combined-tda-design.md` §4-A1).
 *
 * `DeliveryLifecycleService` is typed against this so the SAME ISSUER lifecycle
 * (RegisterTransfer → refresh → ConfirmReturn) drives both parents. The 3a
 * migration mirrored the DeliveryNote movement columns onto `invoices`, so the
 * plain attribute reads the service does (`mydata_url`, `mydata_mark`,
 * `mydata_state`, `delivery_state`, `transport_type`, `vehicle_number`,
 * `carrier_afm`, `local_status`, `invcode`, `company_id`, …) resolve identically
 * on both — documented in the @property block below. Only Eloquent models
 * implement this interface, so `getKey()`/`forceFill()`/`save()` come from the
 * base Model (the @method block documents the ones the service calls).
 *
 * What the interface DECLARES is the seams whose IMPLEMENTATION genuinely differs
 * between a money-less note and a monetary invoice — the structural bindings §4-A1
 * had to break: the polymorphic audit relations (audit-row FK), the fail-closed
 * tenant-coherence check, and the stock reversal. The ONE remaining type-specific
 * seam — routing a remote cancel through the right choke-point (§7) — the service
 * keeps as an explicit `instanceof Invoice` branch (a conscious exception, not a
 * contract method), so this interface carries no discriminator the service ignores.
 *
 * NOT part of the contract: cancel. A ΤΔΑ is ONE 1.1 document/MARK, so it cancels
 * through the MONETARY path (`MyDataSubmitter::cancel` → `finaliseCancellation`),
 * never the movement `DeliveryLifecycleService::cancel` — which stays
 * `DeliveryNote`-typed and so refuses an invoice at the call boundary (§7).
 *
 * @property int|string|null $id
 * @property int|null $company_id
 * @property string|null $invcode
 * @property string|null $mydata_url the qrUrl — the lifecycle key (RegisterTransfer/ConfirmReturn)
 * @property string|null $mydata_mark the issue MARK — the refresh key
 * @property string|null $mydata_state VALID | CANCELLED | null (the AADE truth)
 * @property string|null $delivery_state our §8.22 movement cache (registered/in_transit/…)
 * @property string|null $local_status draft | active | cancelled (business intent)
 * @property int|null $transport_type §8.15 TransportType (1–7)
 * @property string|null $vehicle_number
 * @property string|null $carrier_afm
 * @property int|null $move_purpose §8.14 goods-movement purpose
 * @property string|null $other_move_purpose_title free-text title when move_purpose=19
 * @property Carbon|null $dispatch_at planned dispatch date/time
 * @property string|null $transfer_mark the RegisterTransfer MARK (issuer-written)
 * @property string|null $return_mark the ConfirmReturn MARK (issuer-written)
 *
 * @method mixed getKey()
 * @method static forceFill(array $attributes)
 * @method bool save(array $options = [])
 */
interface MovableDocument
{
    /**
     * The polymorphic movement-audit MARK rows (`delivery_marks`, morph `movable`).
     * The service writes REGISTER_TRANSFER / CONFIRM_RETURN rows through this so
     * the morph keys (`movable_type`/`movable_id`) are set for EITHER parent.
     */
    public function movementMarks(): MorphMany;

    /**
     * The polymorphic movement LIFECYCLE timeline (`delivery_note_events`, morph
     * `movable`) — the carrier/recipient events synced on refresh, deduped by the
     * `(movable_type, movable_id, dedup_key)` unique (3c-1).
     */
    public function movementEvents(): MorphMany;

    /**
     * Fail-closed tenant/credential coherence for this document (MYD-022): every
     * lifecycle event is filed under the tenant's own ΑΦΜ + credentials, so a
     * cross-tenant document must be refused before any AADE call. Dispatches to the
     * right `TenantCoherence` check for the concrete type.
     */
    public function assertMovementTenant(Company $tenant): void;

    /**
     * Return the document's goods to stock on a cancel/return (STOCK-001).
     * Idempotent and best-effort — a no-op for a σκοπός that never moved stock.
     * Routes to `reverseSaleForDeliveryNote` or `reverseSaleForInvoice`.
     */
    public function reverseMovementStock(): void;
}
