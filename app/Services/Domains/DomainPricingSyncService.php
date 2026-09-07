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
     * `currency_mismatches` names operations where the registrar quoted a
     * DIFFERENT currency than an existing row of the same term — that row's
     * cost goes stale silently otherwise (the operator prices against it).
     *
     * @return array{updated: int, created: int, currency_mismatches: list<string>}
     */
    public function sync(DomainTld $tld): array
    {
        $connection = $tld->registrarConnection;
        if ($connection === null) {
            throw new DomainRegistrarNotConfigured('Το TLD δεν δρομολογείται σε καμία σύνδεση registrar.');
        }

        $adapter = $this->factory->for($connection);
        $pricing = $adapter->getTldPricing($tld->tld, $this->factory->credentialsFor($connection));

        // An all-empty answer (no reseller quote on any operation) is a FAILED
        // pull, not a quiet success — otherwise «synced: 1, 0 κόστη» exits 0
        // and the explicit --tld loud-failure guard never sees it.
        if ($pricing->costs === []) {
            throw new \RuntimeException('Ο registrar δεν επέστρεψε κανένα reseller κόστος για το .'.$tld->tld.' — τίποτα δεν καταγράφηκε.');
        }

        return $this->apply($tld, $pricing);
    }

    /** @return array{updated: int, created: int, currency_mismatches: list<string>} */
    private function apply(DomainTld $tld, TldPricing $pricing): array
    {
        $years = max(1, (int) $tld->min_years);
        $updated = 0;
        $created = 0;
        $mismatches = [];

        DB::transaction(function () use ($tld, $pricing, $years, &$updated, &$created, &$mismatches): void {
            foreach ($pricing->costs as $operation => $entry) {
                // Same term in ANOTHER currency: that row (the one the operator
                // likely prices against) keeps a stale cost — flag it on EVERY
                // run, not just the one that creates the quoted-currency row
                // (a one-shot warning in a long multi-tenant output is a miss).
                $otherCurrency = $tld->prices()
                    ->where('operation', $operation)
                    ->where('years', $years)
                    ->where('currency', '!=', $entry['currency'])
                    ->exists();
                if ($otherCurrency) {
                    $mismatches[] = $operation.' → '.$entry['currency'];
                }

                $row = $tld->prices()
                    ->where('operation', $operation)
                    ->where('years', $years)
                    ->where('currency', $entry['currency'])
                    ->first();

                if ($row !== null) {
                    // Count only REAL movement — a no-change re-run must not
                    // report «κόστη ενημερώθηκαν» (decimal cast: compare via fill).
                    $row->fill(['cost' => round($entry['cost'], 2)]);
                    if ($row->isDirty('cost')) {
                        $row->save();
                        $updated++;
                    }

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

        return ['updated' => $updated, 'created' => $created, 'currency_mismatches' => $mismatches];
    }
}
