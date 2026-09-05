<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\NullPaymentGateway;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a gateway key → PaymentGateway. Config-driven via
 * config/ekdosi.php → payments.gateways, mirroring ProviderTransportRegistry /
 * BillingSourceRegistry: a new gateway is one config line + one class, no core
 * edit. Unknown/empty key → NullPaymentGateway (log + a gateway that can't
 * charge), so callers never null-check and a misconfigured tenant fails loudly.
 */
class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> resolved-instance cache */
    private array $cache = [];

    public function for(string $key): PaymentGateway
    {
        $key = trim($key);
        if ($key === '' || $key === 'none') {
            return $this->null();
        }
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = $this->map()[$key] ?? null;
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, PaymentGateway::class)) {
            Log::warning('Unknown payment gateway requested — using Null gateway.', ['key' => $key]);

            return $this->null();
        }

        return $this->cache[$key] = new $class;
    }

    /** @return list<string> configured gateway keys (excludes the Null fallback) */
    public function keys(): array
    {
        return array_keys($this->map());
    }

    /**
     * Display name for a possibly-STALE stored key, for read-only rendering (the
     * admin list) — WITHOUT logging or falling back to Null. A known key → its
     * gateway's displayName; an unknown/removed key → the raw key itself (so the
     * operator still sees what was configured), never a warning per row.
     */
    public function label(string $key): string
    {
        $key = trim($key);
        $class = $this->map()[$key] ?? null;
        if ($class !== null && class_exists($class) && is_subclass_of($class, PaymentGateway::class)) {
            return (new $class)->displayName();
        }

        return $key !== '' ? $key : '—';
    }

    /**
     * Every configured gateway instance — for the admin «Add method» picker
     * (key → displayName).
     *
     * @return list<PaymentGateway>
     */
    public function all(): array
    {
        return array_map(fn (string $key): PaymentGateway => $this->for($key), $this->keys());
    }

    private function null(): PaymentGateway
    {
        return $this->cache['none'] ??= new NullPaymentGateway;
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        $map = config('ekdosi.payments.gateways', []);

        return is_array($map) ? $map : [];
    }
}
