<?php

namespace App\Support\EInvoice;

use App\Models\Company;

/**
 * Per-tenant provider credentials, read from the encrypted
 * companies.einvoice_provider_config blob. Deliberately a generic key/value bag
 * (+ sandbox flag) rather than typed fields: providers' auth differs wildly —
 * SBZ wants an `API-KEY` header, InvoSign a `token` form field, others OAuth
 * (providers-survey.md). Each transport reads the keys it needs via get();
 * adding a provider never needs a schema change (the blob is free-form JSON).
 *
 * Immutable.
 */
final class ProviderCredentials
{
    /**
     * @param  array<string, mixed>  $config  Decrypted provider config (api_key, token,
     *                                        base_url, demo_base_url, provider_afm, licence_no, …).
     * @param  bool  $sandbox  True for the demo/sandbox environment.
     */
    public function __construct(
        public readonly array $config = [],
        public readonly bool $sandbox = true,
    ) {}

    /**
     * Build from a tenant: the encrypted config blob + the provider mode. Anything
     * other than 'production' is treated as sandbox (off has no live endpoint).
     */
    public static function fromCompany(Company $tenant): self
    {
        $config = $tenant->einvoice_provider_config;
        if (! is_array($config)) {
            $config = [];
        }

        return new self(
            config: $config,
            // Fail-safe direction: ONLY an explicit 'production' hits the live
            // endpoint. 'off' / 'sandbox' / any typo ('prod', …) → sandbox, so a
            // bad mode value can never accidentally file against production.
            // (P2 may promote this to a MyDataMode-style enum cast.)
            sandbox: ($tenant->einvoice_provider_mode ?? 'off') !== 'production',
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config) && $this->config[$key] !== null && $this->config[$key] !== '';
    }
}
