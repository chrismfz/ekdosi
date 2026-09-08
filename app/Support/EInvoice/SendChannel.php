<?php

namespace App\Support\EInvoice;

use App\Models\Company;

/**
 * The operator-facing "Τρόπος αποστολής παραστατικών" is ONE flat dropdown, but it
 * drives FOUR storage columns (einvoice_provider / mydata_mode /
 * einvoice_provider_key / einvoice_provider_mode). This is the pure brain that maps
 * a channel key ↔ those columns, so the Company form stays simple and the mapping
 * is unit-tested in isolation (P3, docs/paroxos/implementation-plan.md §3).
 *
 * Channel keys:
 *   'mydata-production'      → gr-mydata, mode=production  (LIVE myDATA)
 *   'mydata-sandbox'         → gr-mydata, mode=sandbox     (AADE test endpoint)
 *   'mydata-off'             → gr-mydata, mode=off         (Καθόλου — PDF only, staged)
 *   '<provider>-sandbox'     → gr-provider, key=<provider>, provider_mode=sandbox
 *   '<provider>-production'  → gr-provider, key=<provider>, provider_mode=production
 *   'peppol'                 → ee-peppol                   (Estonia, stub)
 *
 * Decomposition is total + fail-safe: an unknown channel maps to 'mydata-off'
 * (PDF only) — never accidentally to a LIVE filing path.
 */
final class SendChannel
{
    public const FALLBACK = 'mydata-off';

    /**
     * Channel key → the four storage columns.
     *
     * @return array{einvoice_provider:string, mydata_mode:string, einvoice_provider_key:?string, einvoice_provider_mode:string}
     */
    public static function decompose(string $channel): array
    {
        return match ($channel) {
            'mydata-production' => self::cols('gr-mydata', 'production', null, 'off'),
            'mydata-sandbox' => self::cols('gr-mydata', 'sandbox', null, 'off'),
            'mydata-off' => self::cols('gr-mydata', 'off', null, 'off'),
            'peppol' => self::cols('ee-peppol', 'off', null, 'off'),
            'off' => self::cols('none', 'off', null, 'off'),
            default => self::decomposeProvider($channel),
        };
    }

    /** The four storage columns → the channel key (the reverse of decompose). */
    public static function fromColumns(?string $provider, ?string $mydataMode, ?string $providerKey, ?string $providerMode): string
    {
        return match ($provider ?: 'gr-mydata') {
            // A gr-provider row with no key is out-of-band (legacy/partial import) —
            // map it back to the fail-safe channel so the edit form never renders a
            // non-existent option (which would silently drop the selection).
            'gr-provider' => $providerKey
                ? $providerKey.'-'.(($providerMode ?: 'sandbox') === 'production' ? 'production' : 'sandbox')
                : self::FALLBACK,
            'ee-peppol' => 'peppol',
            'none' => 'off',
            default => 'mydata-'.match ($mydataMode) {
                'production' => 'production',
                'sandbox' => 'sandbox',
                default => 'off',
            },
        };
    }

    public static function fromCompany(Company $company): string
    {
        return self::fromColumns(
            $company->einvoice_provider,
            $company->mydata_mode,
            $company->einvoice_provider_key,
            $company->einvoice_provider_mode,
        );
    }

    /**
     * Build the dropdown options. $providerLabels = ['invosign' => 'InvoSign', …]
     * (from config('ekdosi.einvoice.provider_labels')). myDATA channels are always
     * shown; each provider yields a Δοκιμαστικό + Παραγωγή pair; PEPPOL last.
     *
     * @param  array<string, string>  $providerLabels
     * @return array<string, string>
     */
    public static function options(array $providerLabels = []): array
    {
        $opts = [
            'mydata-production' => 'myDATA — Παραγωγή',
            'mydata-sandbox' => 'myDATA — Δοκιμαστικό',
            'mydata-off' => 'Καθόλου (μόνο PDF)',
        ];

        foreach ($providerLabels as $key => $label) {
            $opts[$key.'-sandbox'] = $label.' — Δοκιμαστικό';
            $opts[$key.'-production'] = $label.' — Παραγωγή';
        }

        $opts['peppol'] = 'PEPPOL (Εσθονία)';

        return $opts;
    }

    /** True when the channel files through a third-party provider (gr-provider). */
    public static function isProvider(string $channel): bool
    {
        return self::decompose($channel)['einvoice_provider'] === 'gr-provider';
    }

    /** True when the channel files directly to myDATA (gr-mydata, any non-off mode). */
    public static function isDirectMyData(string $channel): bool
    {
        $cols = self::decompose($channel);

        return $cols['einvoice_provider'] === 'gr-mydata' && $cols['mydata_mode'] !== 'off';
    }

    /** The provider key for a provider channel, else null. */
    public static function providerKey(string $channel): ?string
    {
        return self::decompose($channel)['einvoice_provider_key'];
    }

    private static function decomposeProvider(string $channel): array
    {
        foreach (['production', 'sandbox'] as $mode) {
            $suffix = '-'.$mode;
            if (str_ends_with($channel, $suffix)) {
                $key = substr($channel, 0, -strlen($suffix));
                if ($key !== '') {
                    return self::cols('gr-provider', 'off', $key, $mode);
                }
            }
        }

        // Unknown channel → PDF only, never a live filing path.
        return self::decompose(self::FALLBACK);
    }

    /**
     * @return array{einvoice_provider:string, mydata_mode:string, einvoice_provider_key:?string, einvoice_provider_mode:string}
     */
    private static function cols(string $provider, string $mydataMode, ?string $providerKey, string $providerMode): array
    {
        return [
            'einvoice_provider' => $provider,
            'mydata_mode' => $mydataMode,
            'einvoice_provider_key' => $providerKey,
            'einvoice_provider_mode' => $providerMode,
        ];
    }
}
