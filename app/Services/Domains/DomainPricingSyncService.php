<?php

namespace App\Services\Domains;

use App\Models\DomainTld;
use App\Support\Domains\TldPricing;
use Illuminate\Support\Facades\DB;

/**
 * Applies one registrar COST pull to one TLD's price rows (Πυλώνας A / A2c) —
 * shared by `domains:sync-pricing` and any future per-TLD «Άντληση κόστους»
 * action. The write discipline is the whole point:
 *
 *   - ONLY the `cost` column is ever written. The sell `price` and
 *     `is_enabled` are operator decisions (margins are manual per TLD,
 *     README §7.1) — the sync must never move a number a customer pays.
 *   - A missing row is created cost-only: price = null, is_enabled = FALSE.
 *     Belt and braces with AssignDomainToCustomer's whereNotNull(price):
 *     a synced-in row can never silently become billable.
 *   - Costs land on the TLD's MINIMUM term row (max(1, min_years)) — the
 *     registrar quotes the minimum registrable period (.gr = biennial), and
 *     that's the row the operator prices against.
 */
class DomainPricingSyncService
{
    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /**
     * Can this TLD's costs be pulled at all? Active (not trashed/disabled),
     * routed to a USABLE non-manual connection whose adapter declares
     * supportsPricingSync (grEPP has no price API — .gr costs stay manual).
     */
    public function isSyncable(DomainTld $tld): bool
    {
        $connection = $tld->registrarConnection;
        if ($tld->trashed() || ! $tld->is_active || $connection === null || ! $connection->isUsable()) {
            return false;
        }

        $adapter = $this->factory->for($connection);

        return $adapter->key() !== 'manual' && $adapter->capabilities()->supportsPricingSync;
    }

    /**
     * Pull + apply. Throws on transport/API failure (the command counts and
     * reports; one broken TLD must not stall the tenant's run).
     *
     * @return array{updated: int, created: int}
     */
    public function sync(DomainTld $tld): array
    {
        $connection = $tld->registrarConnection;
        if ($connection === null) {
            throw new DomainRegistrarNotConfigured('Το TLD δεν δρομολογείται σε καμία σύνδεση registrar.');
        }

        $adapter = $this->factory->for($connection);
        $pricing = $adapter->getTldPricing($tld->tld, $this->factory->credentialsFor($connection));

        return $this->apply($tld, $pricing);
    }

    /** @return array{updated: int, created: int} */
    private function apply(DomainTld $tld, TldPricing $pricing): array
    {
        $years = max(1, (int) $tld->min_years);
        $updated = 0;
        $created = 0;

        DB::transaction(function () use ($tld, $pricing, $years, &$updated, &$created): void {
            foreach ($pricing->costs as $operation => $entry) {
                $row = $tld->prices()
                    ->where('operation', $operation)
                    ->where('years', $years)
                    ->where('currency', $entry['currency'])
                    ->first();

                if ($row !== null) {
                    $row->update(['cost' => round($entry['cost'], 2)]);
                    $updated++;

                    continue;
                }

                $tld->prices()->create([
                    'company_id' => $tld->company_id,
                    'operation' => $operation,
                    'years' => $years,
                    'currency' => $entry['currency'],
                    'cost' => round($entry['cost'], 2),
                    'price' => null,
                    'is_enabled' => false,
                ]);
                $created++;
            }
        });

        return ['updated' => $updated, 'created' => $created];
    }
}
