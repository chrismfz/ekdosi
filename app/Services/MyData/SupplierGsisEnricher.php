<?php

namespace App\Services\MyData;

use App\Exceptions\Aade\AadeRegistryException;
use App\Models\Company;
use App\Services\AadeRegistryLookup;
use Illuminate\Support\Facades\Log;

/**
 * Turn a bare ΑΦΜ into named supplier columns via the GSIS registry.
 *
 * Greek myDATA documents forbid the domestic party name ([219]/[220]) — only
 * the ΑΦΜ arrives — so a supplier auto-discovered from a myDATA doc would be a
 * nameless «παύλα» without this. This is the single place that resolves an ΑΦΜ
 * to επωνυμία/ΔΟΥ/διεύθυνση/δραστηριότητα, shared by the bulk supplier sync
 * ({@see SupplierSyncFromMyData}) and the expense importer's supplier
 * auto-create ({@see ExpenseImporter}) so the two can't drift.
 *
 * Best-effort: returns null on ANY GSIS failure (missing creds / ΑΦΜ not found /
 * unreachable) so a bulk run never aborts — the caller creates the ΑΦΜ-only
 * supplier instead and the operator can fill it in later. Never throws.
 */
class SupplierGsisEnricher
{
    public function __construct(private readonly Company $tenant) {}

    /**
     * Resolve the ΑΦΜ against the AADE registry and map the result onto Supplier
     * columns (empties dropped, so we never overwrite with blank strings).
     *
     * @return array<string, string>|null name/tax_office/address1/city/postcode/
     *                                    country[/occupation], or null when GSIS could not resolve the ΑΦΜ.
     */
    public function enrich(string $afm): ?array
    {
        $afm = trim($afm);
        if ($afm === '') {
            return null;
        }

        try {
            $rec = app(AadeRegistryLookup::class, ['tenant' => $this->tenant])->findByAfm($afm);
        } catch (AadeRegistryException $e) {
            // Base of AadeAfmNotFound / AadeCredentialsInvalid / AadeUnreachable
            // (SOAP faults are wrapped into these). Catching the base keeps a
            // bulk run resilient — one bad ΑΦΜ never aborts the sweep — and
            // auto-covers any future subtype, without swallowing unrelated bugs.
            Log::info('Supplier GSIS enrichment skipped', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'reason' => class_basename($e),
            ]);

            return null;
        }

        $out = [
            'name' => $rec->name,
            'tax_office' => $rec->doy,
            'address1' => $rec->address,
            'city' => $rec->city,
            'postcode' => $rec->postcode,
            'country' => 'GR',
        ];

        if (($primary = $rec->primaryActivity()) !== null && ! empty($primary['description'])) {
            $out['occupation'] = $primary['description'];
        }

        // Drop empties so we don't overwrite with blank strings.
        return array_filter($out, static fn ($v): bool => trim((string) $v) !== '');
    }
}
