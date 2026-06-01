<?php

namespace App\Services\Billing;

use App\Contracts\BillingSource;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0 (Bridges/Connectors): resolves a source key → BillingSource
 * implementation. Mirrors EInvoiceSubmitterFactory: config-driven via
 * `config/ekdosi.php → billing.sources`, so adding a source is one config line
 * + one class — no core edit. An unknown key returns null + a logged warning
 * (forward-compatible: a stale DB value can never crash a page).
 *
 * @see docs/bridges-connectors.md
 */
class BillingSourceRegistry
{
    /** @var array<string, BillingSource> resolved-instance cache */
    private array $cache = [];

    public function for(string $source): ?BillingSource
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }
        if (isset($this->cache[$source])) {
            return $this->cache[$source];
        }

        $map = $this->map();
        $class = $map[$source] ?? null;
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, BillingSource::class)) {
            Log::warning('Unknown billing source requested.', ['source' => $source]);

            return null;
        }

        return $this->cache[$source] = new $class();
    }

    /** All configured sources, keyed by source key. @return array<string, BillingSource> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->map()) as $key) {
            $src = $this->for($key);
            if ($src !== null) {
                $out[$key] = $src;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->map());
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        $map = config('ekdosi.billing.sources', []);

        return is_array($map) ? $map : [];
    }
}
