<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

/**
 * Backfill `invoices.payable_total` (the collectible: gross_total + the AADE [208]
 * additional-tax adjustment) on existing rows — new rows get it from
 * RecomputeInvoiceTaxes on save, but pre-existing invoices (incl. imported ones)
 * have it NULL until this runs. Idempotent: derives purely from the already-persisted
 * gross_total + tax-amount columns, so it's safe to re-run. Deploy step for the
 * «withholding/fees count toward owed» change.
 */
class BackfillPayableTotal extends Command
{
    protected $signature = 'invoices:backfill-payable-total
        {--company= : Limit to one company id}
        {--only-missing : Only rows where payable_total is NULL}';

    protected $description = 'Backfill invoices.payable_total (= gross_total + additional-tax adjustment) on existing rows';

    public function handle(): int
    {
        $query = Invoice::query()->withoutGlobalScopes();
        if ($companyId = $this->option('company')) {
            $query->where('company_id', $companyId);
        }
        if ($this->option('only-missing')) {
            $query->whereNull('payable_total');
        }

        $count = 0;
        $query->chunkById(500, function ($invoices) use (&$count) {
            foreach ($invoices as $invoice) {
                $payable = round((float) ($invoice->gross_total ?? 0) + $invoice->additionalTaxAdjustment(), 2);
                // Quietly — pure derived cache, no observers / activity-log noise.
                $invoice->forceFill(['payable_total' => $payable])->saveQuietly();
                $count++;
            }
        });

        $this->info("Backfilled payable_total on {$count} invoice(s).");

        return self::SUCCESS;
    }
}
