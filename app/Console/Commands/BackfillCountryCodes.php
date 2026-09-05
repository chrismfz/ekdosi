<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Supplier;
use App\Support\IsoCountry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * MYD-011: backfill the normalised `country_code` cache on existing customers and
 * suppliers from their free-text `country`. New rows get it from the model's
 * saving() hook and imports set it in the ETL; this one-shot lights up rows that
 * predate the column (imported before the migration, or created earlier).
 *
 * Idempotent and safe to re-run: only touches rows where `country_code` is still
 * NULL, so an operator's explicit picker choice is never clobbered. Runs quietly
 * (no observers / activity-log noise) — it derives purely from the stored `country`.
 * Cross-tenant by design (global scopes dropped); the derived value never leaves the
 * row it came from, so there is no tenant-leak surface.
 */
class BackfillCountryCodes extends Command
{
    protected $signature = 'ekdosi:backfill-country-codes
        {--company= : Limit to one company id}';

    protected $description = 'Backfill customers/suppliers country_code (normalised ISO-3166-1 alpha-2) from the free-text country';

    public function handle(): int
    {
        $companyId = $this->option('company');

        $customers = $this->backfill(Customer::query(), $companyId);
        $suppliers = $this->backfill(Supplier::query(), $companyId);

        $this->info("Backfilled country_code on {$customers} customer(s) and {$suppliers} supplier(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Customer>|Builder<Supplier>  $query
     */
    private function backfill($query, ?string $companyId): int
    {
        // withoutGlobalScopes() also drops the SoftDeletingScope, so trashed rows are
        // included — they need a code too (a restored party must resolve correctly).
        $query->withoutGlobalScopes()->whereNull('country_code');
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $count = 0;
        $query->chunkById(500, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                $code = IsoCountry::tryNormalise($row->country);
                if ($code === null) {
                    continue; // blank or unrecognised → leave NULL, don't guess
                }
                $row->forceFill(['country_code' => $code])->saveQuietly();
                $count++;
            }
        });

        return $count;
    }
}
