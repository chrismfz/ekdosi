<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceEmail;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Batch mail sweep — re-dispatch invoice emails whose LAST send attempt failed,
 * closing the auto-email gap (CLAUDE.md: "re-send failures / bulk"). The
 * per-invoice send pipeline (SendInvoiceEmail) already writes a 'failed' mail-log
 * row on terminal exhaustion; this finds those whose latest log is still 'failed'
 * (i.e. no later 'sent' superseded it) and queues a fresh attempt.
 *
 * Re-sends like the manual "Resend email" action — trigger='batch', the
 * per-customer opt-out is NOT re-checked (the original send already decided to
 * mail this invoice; we're only retrying a transient SMTP failure).
 *
 * Usage:
 *   php artisan invoices:resend-failed-emails                  # all tenants, last 7 days
 *   php artisan invoices:resend-failed-emails --tenant=myip --since=30 --limit=100
 *   php artisan invoices:resend-failed-emails --dry-run
 */
class ResendFailedInvoiceEmails extends Command
{
    protected $signature = 'invoices:resend-failed-emails
        {--tenant= : Company slug or id (default: all)}
        {--since=7 : Only invoices whose failure is within the last N days}
        {--limit=200 : Max invoices to re-queue per tenant (safety cap)}
        {--dry-run : List what would be re-queued without dispatching}';

    protected $description = 'Re-queue invoice emails whose last send attempt failed.';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenant.');

            return self::SUCCESS;
        }

        $since = Carbon::now()->subDays(max(0, (int) $this->option('since')));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($tenants as $tenant) {
            $invoices = $this->failedInvoices($tenant, $since, $limit);

            if ($invoices->isEmpty()) {
                $this->line("• {$tenant->slug}: none");

                continue;
            }

            foreach ($invoices as $invoice) {
                $total++;
                if ($dryRun) {
                    $this->line("  [dry] {$tenant->slug} · {$invoice->invcode} → ".($invoice->mailLog->first()->recipient ?? '—'));

                    continue;
                }
                SendInvoiceEmail::dispatch($invoice, trigger: 'batch');
            }

            $verb = $dryRun ? 'would re-queue' : 're-queued';
            $this->info("✓ {$tenant->slug}: {$verb} {$invoices->count()}");
        }

        $this->info(($dryRun ? 'Dry run — ' : '').'Total: '.$total);

        return self::SUCCESS;
    }

    /**
     * Tenant invoices whose LATEST mail-log row is 'failed' (so a later 'sent'
     * hasn't superseded it) and that failure is within the window.
     *
     * @return Collection<int, Invoice>
     */
    private function failedInvoices(Company $tenant, Carbon $since, int $limit): Collection
    {
        return Invoice::query()
            ->where('company_id', $tenant->getKey())
            // DOC-6: only ISSUED, non-cancelled documents are emailable (the mail
            // body asserts «…που εκδόθηκε…»). Gating the SELECT — not just the job
            // — stops the sweep from re-queuing a cancelled invoice on every run
            // (it would otherwise churn: dispatch → job skips → still 'failed' →
            // re-selected next run). Mirrors Invoice::isPubliclyViewable().
            ->where('local_status', 'active')
            ->where(fn ($q) => $q->whereNull('mydata_state')->orWhere('mydata_state', '!=', 'CANCELLED'))
            // OPS-10: skip invoices whose customer has NO email — resending can
            // never succeed, but the job still writes a fresh 'failed' row every
            // attempt, which renews the --since window and re-selects the row
            // forever (churning the failure counters, hiding real SMTP failures).
            // A customer who GETS an email later is re-included automatically
            // (the filter is on the CURRENT address, not the failure reason).
            ->whereHas('customer', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->whereHas('mailLog', fn ($q) => $q->where('status', 'failed')->where('created_at', '>=', $since))
            ->with('mailLog') // ordered desc by created_at on the relation
            ->latest('id')
            ->limit($limit)
            ->get()
            // Keep only those whose CURRENT (latest) state is still failed.
            ->filter(fn (Invoice $invoice) => $invoice->mailLog->first()?->status === 'failed')
            ->values();
    }

    /**
     * @return Collection<int, Company>
     */
    private function resolveTenants(): Collection
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);

            return $tenant ? collect([$tenant]) : collect();
        }

        return Company::query()->orderBy('id')->get();
    }
}
