<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute the denormalised money-status cache (paid_total /
 * credited_total / payment_status) on invoices. Idempotent — derives
 * everything from durable payment + credit-note rows, so it's safe to
 * run repeatedly. Intended to run after an ETL import (which leaves the
 * cache null) so legacy data gets correct badges immediately.
 */
class RecomputeInvoiceBalances extends Command
{
    protected $signature = 'invoices:recompute-balances {--company= : Limit to one company id}';

    protected $description = 'Recompute invoices.{paid_total,credited_total,payment_status} from payments + credit notes';

    public function handle(InvoiceBalance $balance): int
    {
        $query = Invoice::query()->withoutGlobalScopes();
        if ($companyId = $this->option('company')) {
            $query->where('company_id', $companyId);
        }

        $count = 0;
        $query->with('paymentMethod')->chunkById(500, function ($invoices) use ($balance, &$count) {
            foreach ($invoices as $invoice) {
                DB::transaction(fn () => $balance->recompute($invoice));
                $count++;
            }
        });

        $this->info("Recomputed {$count} invoice balance(s).");

        return self::SUCCESS;
    }
}
