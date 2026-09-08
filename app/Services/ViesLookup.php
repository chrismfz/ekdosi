<?php

namespace App\Services;

use App\DTOs\ViesResult;
use App\Exceptions\Vies\ViesInvalidFormat;
use App\Exceptions\Vies\ViesUnavailable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates an EU intra-community VAT number via the European Commission's
 * VIES REST service (no auth, no credentials):
 *
 *   POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *   { "countryCode": "AT", "vatNumber": "U18522105" }
 *
 * The companion to App\Services\AadeRegistryLookup (GSIS, Greek AFMs): GR
 * numbers go to GSIS (richer data, ΔΟΥ/ΚΑΔ); every OTHER EU member state goes
 * here. VIES returns `valid` always, and name/address for the member states
 * that publish them (AT yes, DE no — privacy).
 *
 * Result caching mirrors AadeRegistryLookup (24h) so re-checks on a form are
 * instant and we don't hammer the EU endpoint (which throttles).
 */
class ViesLookup
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private const CACHE_TTL_SECONDS = 86400; // 24h, same as the GSIS lookup

    private const HTTP_TIMEOUT_SECONDS = 15;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * The 27 EU member-state VAT prefixes VIES covers. EL is the VIES code for
     * Greece (NOT "GR") — but Greek numbers are handled by GSIS, so EL is here
     * only for completeness/parsing. XI = Northern Ireland (post-Brexit).
     */
    private const EU_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'XI',
    ];

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Check a VAT number. Accepts either a combined id ("ATU18522105") or a
     * pre-split country + number. The country prefix is stripped from the
     * number before sending (VIES wants {countryCode:"AT", vatNumber:"U..."}).
     *
     * @param  string  $vat        The VAT id (with or without country prefix).
     * @param  string|null  $countryHint  ISO country (e.g. customer.country) used
     *                                     when $vat carries no prefix of its own.
     *
     * @throws ViesInvalidFormat  Can't derive a {country, number} pair, or
     *                            country isn't an EU member state.
     * @throws ViesUnavailable    VIES/MS unreachable or SERVICE_UNAVAILABLE.
     */
    public function check(string $vat, ?string $countryHint = null): ViesResult
    {
        [$country, $number] = $this->parse($vat, $countryHint);

        $cacheKey = "vies.{$country}.{$number}";
        try {
            $cached = Cache::get($cacheKey);
            if ($cached instanceof ViesResult) {
                return $cached;
            }
        } catch (Throwable $e) {
            Log::warning('VIES cache read failed — live fetch', ['vat' => $country.$number, 'ex' => get_class($e)]);
        }

        $result = $this->fetch($country, $number);

        try {
            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
            Log::warning('VIES cache write failed', ['vat' => $country.$number, 'ex' => get_class($e)]);
        }

        return $result;
    }

    /**
     * Split a VAT id into [countryCode, number]. If the string starts with an
     * EU prefix, use it; otherwise fall back to $countryHint. Greek "GR" is
     * normalised to "EL" (the VIES code) so a GR-typed value still parses,
     * though GR lookups normally route to GSIS.
     *
     * @return array{0: string, 1: string}
     */
    private function parse(string $vat, ?string $countryHint): array
    {
        $clean = strtoupper(preg_replace('/[\s\-.]/', '', $vat) ?? '');
        if ($clean === '') {
            throw new ViesInvalidFormat('Empty VAT number.');
        }

        $prefix = substr($clean, 0, 2);
        if (in_array($prefix, self::EU_PREFIXES, true)) {
            $country = $prefix;
            $number = substr($clean, 2);
        } else {
            $country = strtoupper(trim((string) $countryHint));
            if ($country === 'GR') {
                $country = 'EL';
            }
            $number = $clean;
        }

        if (! in_array($country, self::EU_PREFIXES, true)) {
            throw new ViesInvalidFormat(
                'Cannot determine an EU country for VAT "'.$vat.'". '.
                'Prefix the number with its country code (e.g. ATU…) or set the customer country.'
            );
        }
        if ($number === '') {
            throw new ViesInvalidFormat('VAT number is only a country code, with no digits.');
        }

        return [$country, $number];
    }

    private function fetch(string $country, string $number): ViesResult
    {
        try {
            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'countryCode' => $country,
                    'vatNumber' => $number,
                ]);
        } catch (Throwable $e) {
            throw new ViesUnavailable('VIES endpoint unreachable: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new ViesUnavailable('VIES returned HTTP '.$response->status().'.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new ViesUnavailable('VIES returned a non-JSON body.');
        }

        // The EU service signals its own soft failures in the body even on a
        // 200: an `errorWrappers[].error` like SERVICE_UNAVAILABLE / MS_UNAVAILABLE
        // / TIMEOUT / GLOBAL_MAX_CONCURRENT_REQ. Treat all as transient.
        $errors = $data['errorWrappers'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $code = $errors[0]['error'] ?? 'UNKNOWN';
            throw new ViesUnavailable('VIES service error: '.$code.' — try again shortly.');
        }

        return new ViesResult(
            countryCode: (string) ($data['countryCode'] ?? $country),
            vatNumber: (string) ($data['vatNumber'] ?? $number),
            valid: (bool) ($data['valid'] ?? false),
            // VIES uses '---' for withheld/unknown identity fields; keep as-is,
            // ViesResult::hasIdentity() treats '---' as "no identity".
            name: trim((string) ($data['name'] ?? '')),
            address: trim((string) ($data['address'] ?? '')),
            requestDate: isset($data['requestDate']) ? (string) $data['requestDate'] : null,
        );
    }
}
