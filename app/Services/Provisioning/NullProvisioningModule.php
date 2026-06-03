<?php

namespace App\Services\Provisioning;

use App\Contracts\ProvisioningModule;
use App\Models\ServiceContract;

/**
 * The no-op provisioning module (key 'none') — and the safe fallback for any
 * unknown/'custom' key. The contract's lifecycle is kept as LOCAL STATE only:
 * no remote account is touched, nothing is reachable, nothing can fail. This is
 * what makes dunning safe to ship before any real cPanel/Mailcow module exists —
 * suspend/terminate flip the contract status (+ cascade unissued drafts) and the
 * "provisioning" step is simply a no-op.
 *
 * A real module replaces this for its key via config/ekdosi.php → provisioning.modules.
 */
class NullProvisioningModule implements ProvisioningModule
{
    public function key(): string
    {
        return 'none';
    }

    public function create(ServiceContract $contract): void
    {
        // no-op (local state only)
    }

    public function suspend(ServiceContract $contract): void
    {
        // no-op (local state only)
    }

    public function unsuspend(ServiceContract $contract): void
    {
        // no-op (local state only)
    }

    public function terminate(ServiceContract $contract): void
    {
        // no-op (local state only)
    }
}
