<?php

namespace App\Services\EInvoice;

use App\Contracts\EInvoiceProviderTransport;
use App\Services\EInvoice\Transports\NullProviderTransport;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a provider key → EInvoiceProviderTransport. Config-driven via
 * config/ekdosi.php → einvoice.providers, mirroring BillingSourceRegistry and
 * ProvisioningModuleRegistry: a new provider is one config line + one class, no
 * core edit.
 *
 * Unlike those (which return null on an unknown key), this ALWAYS returns a
 * transport — a NullProviderTransport for unknown/empty/unconfigured keys — so
 * callers never null-check and a misconfigured tenant fails LOUDLY at send-time
 * (the Null transport throws) instead of silently not-filing a legal document.
 *
 * @see docs/paroxos/implementation-plan.md §2.3
 */
class ProviderTransportRegistry
{
    /** @var array<string, EInvoiceProviderTransport> resolved-instance cache */
    private array $cache = [];

    public function for(string $key): EInvoiceProviderTransport
    {
        $key = trim($key);
        if ($key === '' || $key === 'none') {
            return $this->null();
        }
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = $this->map()[$key] ?? null;
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, EInvoiceProviderTransport::class)) {
            Log::warning('Unknown e-invoice provider transport requested — using Null transport.', ['key' => $key]);

            return $this->null();
        }

        return $this->cache[$key] = new $class;
    }

    /** @return list<string> configured provider keys (excludes the Null fallback) */
    public function keys(): array
    {
        return array_keys($this->map());
    }

    private function null(): EInvoiceProviderTransport
    {
        return $this->cache['none'] ??= new NullProviderTransport;
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        $map = config('ekdosi.einvoice.providers', []);

        return is_array($map) ? $map : [];
    }
}
