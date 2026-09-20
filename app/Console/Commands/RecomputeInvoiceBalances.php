<?php

namespace App\Console\Commands;

use App\Models\Company;
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
    protected $signature = 'invoices:recompute-balances {--company= : Limit to one company (slug or id)}';

    protected $description = 'Recompute invoices.{paid_total,credited_total,payment_status} from payments + credit notes';

    public function handle(InvoiceBalance $balance): int
    {
        $query = Invoice::query()->withoutGlobalScopes();
        if ($company = $this->option('company')) {
            // Accept a SLUG as well as an id: every other tenant-scoped command in
            // this app takes `--tenant=SLUG`, and CLAUDE.md documents this one as
            // `--company=myip`. Passing a slug used to match no company_id at all,
            // so the documented command reported «Recomputed 0» and silently did
            // nothing — the worst possible outcome for a backfill.
            $resolved = is_numeric($company)
                ? (int) $company
                : Company::query()->where('slug', $company)->value('id');

            if ($resolved === null) {
                $this->error("Δεν βρέθηκε εταιρία «{$company}» (δώσε slug ή id).");

                return self::FAILURE;
            }

            $query->where('company_id', $resolved);
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
