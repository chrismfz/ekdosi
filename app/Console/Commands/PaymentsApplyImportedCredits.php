<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\PaymentAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-off cleanup for Epsilon-imported customers: link each customer's imported
 * «έναντι» on-account credits (transaction_id EPS:…) to their open invoices,
 * FIFO oldest-first, so the historically-paid invoices stop reading as «ανοιχτά».
 *
 * WHY: Epsilon exports payments as customer-account movements, not per-invoice,
 * so the importer lands them on-account (invoice_id null). Every imported invoice
 * then shows «χωρίς πληρωμή» individually even though the customer nets to zero —
 * and the auto-FIFO «Είσπραξη» targets those oldest phantom-open invoices, dumping
 * a new receipt on 2021 docs instead of the one it was meant for. This re-points
 * the existing έναντι credit onto those invoices (net-zero to the customer
 * balance), closing them so a future receipt can only land on a genuinely-open
 * invoice.
 *
 * Safe: net-zero (no money created/removed), idempotent (a re-run finds nothing
 * open), scoped to EPS: rows only (never a genuine advance), and --dry-run shows
 * the full plan without writing. Take a db-snapshot before the real run anyway.
 */
class PaymentsApplyImportedCredits extends Command
{
    protected $signature = 'payments:apply-imported-credits
        {--company= : Company slug or id (required)}
        {--customer= : Limit to one customer (AFM or id)}
        {--dry-run : Show what would be linked, write nothing}';

    protected $description = 'Link imported «έναντι» (EPS:) on-account credits to open invoices FIFO (net-zero cleanup)';

    public function handle(PaymentAllocator $allocator): int
    {
        $companyOpt = trim((string) $this->option('company'));
        if ($companyOpt === '') {
            $this->error('Το --company (slug ή id) είναι υποχρεωτικό.');

            return self::FAILURE;
        }

        // Slug first, then (only if numeric) id — never an ungrouped slug-OR-id
        // that could resolve an unintended tenant for a money-mutating command.
        $company = Company::query()->where('slug', $companyOpt)->first();
        if ($company === null && ctype_digit($companyOpt)) {
            $company = Company::query()->whereKey((int) $companyOpt)->first();
        }
        if ($company === null) {
            $this->error("Δεν βρέθηκε εταιρεία «{$companyOpt}».");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $txPrefix = 'EPS:';

        $customers = $this->resolveCustomers($company, trim((string) $this->option('customer')), $txPrefix);
        if ($customers->isEmpty()) {
            $this->info('Καμία εγγραφή προς αντιστοίχιση (καμία εισαγόμενη «έναντι» πίστωση).');

            return self::SUCCESS;
        }

        $this->line(($dryRun ? '[DRY-RUN] ' : '').'Εταιρεία: '.$company->slug.' — '
            .$customers->count().' πελάτης/ες με εισαγόμενη πίστωση');

        $rows = [];
        $grandApplied = 0.0;
        $grandInvoices = 0;

        $failed = 0;
        foreach ($customers as $customer) {
            // Isolate per customer: a failure on one (its whole sweep rolls back
            // atomically) is reported and the rest still run. A re-run is idempotent.
            try {
                $result = $dryRun
                    ? $allocator->simulateImportedCreditsFifo($customer, $txPrefix)
                    : $allocator->applyImportedCreditsFifo($customer, $txPrefix);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Σφάλμα στον πελάτη «{$customer->name}»: ".$e->getMessage().' — παραλείφθηκε.');

                continue;
            }

            $count = count($result['allocations']);
            if ($count === 0) {
                continue; // credit exists but no open invoice to absorb it
            }
            $rows[] = [
                $customer->name,
                $count,
                number_format($result['total_applied'], 2, ',', '.'),
                number_format($result['credit_left'], 2, ',', '.'),
            ];
            $grandApplied = round($grandApplied + $result['total_applied'], 2);
            $grandInvoices += $count;
        }

        if ($failed > 0) {
            $this->warn("{$failed} πελάτης/ες απέτυχαν & παραλείφθηκαν (δες σφάλματα πιο πάνω· ασφαλές να ξανατρέξεις).");
        }

        if ($rows === []) {
            $this->info('Δεν υπήρχαν ανοιχτά τιμολόγια να δεθούν.');

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Πελάτης', 'Τιμολόγια', 'Δέθηκαν €', 'Υπόλοιπη πίστωση €'], $rows);
        $verb = $dryRun ? 'ΘΑ έδενε' : 'Δέθηκαν';
        $this->info("{$verb}: {$grandInvoices} τιμολόγια, σύνολο ".number_format($grandApplied, 2, ',', '.').' €.');

        if ($dryRun) {
            $this->comment('Dry-run — καμία εγγραφή. Ξανατρέξε χωρίς --dry-run για να εφαρμοστεί (πάρε πρώτα db-snapshot).');
        }

        // Non-zero exit on any per-customer failure, so a cron/deploy wrapper
        // can tell a partial run from a clean one (a re-run is idempotent).
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Customers of this company that have imported «έναντι» on-account credit,
     * optionally narrowed to one (AFM or id). Explicit company_id + no ambient
     * CompanyScope (a CLI request has none anyway) — the tenant-safe CLI rule.
     *
     * @return Collection<int, Customer>
     */
    private function resolveCustomers(Company $company, string $customerOpt, string $txPrefix): Collection
    {
        $customerIds = Payment::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereNull('invoice_id')
            ->where('kind', 'payment')
            ->where('transaction_id', 'like', $txPrefix.'%')
            ->distinct()
            ->pluck('customer_id')
            ->filter()
            ->all();

        if ($customerIds === []) {
            return collect();
        }

        $query = Customer::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('id', $customerIds);

        if ($customerOpt !== '') {
            $query->where(fn ($q) => $q->where('afm', $customerOpt)
                ->when(ctype_digit($customerOpt), fn ($qq) => $qq->orWhere('id', (int) $customerOpt)));
        }

        return $query->orderBy('id')->get();
    }
}
