<?php

namespace App\Services\Provisioning;

use App\Contracts\ProvisioningModule;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a provisioning-module key → ProvisioningModule implementation.
 * Mirrors EInvoiceSubmitterFactory / BillingSourceRegistry: config-driven via
 * `config/ekdosi.php → provisioning.modules` (key => class), so adding a real
 * module is one config line + one class — no core edit.
 *
 * Forward-compatible: an unknown key, 'none', 'custom', '' or a class that
 * doesn't implement the interface all resolve to NullProvisioningModule (with a
 * logged warning for the genuinely-unknown case). The registry NEVER throws — a
 * stale `provisioning_module` value on a contract can't crash the dunning run.
 */
class ProvisioningModuleRegistry
{
    /** @var array<string, ProvisioningModule> resolved-instance cache */
    private array $cache = [];

    private ?NullProvisioningModule $null = null;

    public function for(string $key): ProvisioningModule
    {
        $key = trim($key);

        // 'none'/'custom'/'' are deliberately local-only — no warning.
        if ($key === '' || $key === 'none' || $key === 'custom') {
            return $this->nullModule();
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = $this->map()[$key] ?? null;
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, ProvisioningModule::class)) {
            Log::warning('Unknown provisioning module requested — falling back to none (no-op).', ['module' => $key]);

            return $this->nullModule();
        }

        return $this->cache[$key] = new $class;
    }

    private function nullModule(): NullProvisioningModule
    {
        return $this->null ??= new NullProvisioningModule;
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        $map = config('ekdosi.provisioning.modules', []);

        return is_array($map) ? $map : [];
    }
}
