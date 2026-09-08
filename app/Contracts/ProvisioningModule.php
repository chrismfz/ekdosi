<?php

namespace App\Contracts;

use App\Models\ServiceContract;
use App\Services\Provisioning\NullProvisioningModule;

/**
 * A provisioning automation hook for a recurring service: the seam that lets a
 * future native (WHMCS-independent) module act on the remote system when a
 * contract's lifecycle changes — create the account, suspend it when the
 * customer falls behind, unsuspend on payment, terminate when overdue too long.
 *
 * Today only {@see NullProvisioningModule} (key
 * 'none') exists: every method is a no-op, so the contract's lifecycle is purely
 * local state. A real cPanel / Mailcow / DirectAdmin / license-server module
 * drops in later by implementing this interface + one config line in
 * `config/ekdosi.php → provisioning.modules` — no core edit (mirrors the
 * EInvoiceSubmitter / BillingSource registries).
 *
 * Implementations MUST be best-effort callable from the dunning engine: a
 * failure to reach the remote system must not roll back the local status change
 * (the engine logs + persists the local state regardless). The Null module
 * never throws; a real one should swallow/log its own transport errors or let
 * the caller's try/catch handle them.
 */
interface ProvisioningModule
{
    /** The registry key (matches contract.provisioning_module + the config map). */
    public function key(): string;

    /** Provision the service (account creation) — called on activation. */
    public function create(ServiceContract $contract): void;

    /** Suspend the remote service (dunning: overdue past the suspend threshold). */
    public function suspend(ServiceContract $contract): void;

    /** Restore a suspended service (dunning: the customer paid). */
    public function unsuspend(ServiceContract $contract): void;

    /** Tear down the remote service (dunning: overdue past the terminate threshold). */
    public function terminate(ServiceContract $contract): void;
}
