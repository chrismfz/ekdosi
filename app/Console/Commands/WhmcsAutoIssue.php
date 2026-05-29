<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * G8 phase 2 — γκρινιάρης auto-issue.
 *
 *   php artisan whmcs:auto-issue [--tenant=SLUG] [--dry-run]
 *
 * Files paid inbox rows (already staged by whmcs:fetch-pending) at AADE
 * WITHOUT operator review — but ONLY for the narrow, unambiguous case the
 * operator asked for:
 *
 *   • the tenant armed it (companies.whmcs_auto_issue_immediate), AND
 *   • the matched customer is flagged needs_immediate_invoice (γκρινιάρης), AND
 *   • the row is single-party and cleanly resolvable.
 *
 * This is a TWO-KEY arming: the per-tenant toggle above PLUS the
 * scheduler-side flag EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE (config/ekdosi.php),
 * both default OFF. Unlike whmcs:fetch-pending (which only STAGES), this
 * command FILES legally-significant documents, so the bar is high.
 *
 * Safety design (anything ambiguous is LEFT in the inbox for a human):
 *   - Only status='pending_review' rows with invoice_id still null.
 *   - Only third_party_state in {null, none, single} — 'multi' is ingested
 *     as 'held' and never picked up; a 'single' that couldn't resolve a
 *     customer is also 'held'.
 *   - Requires a configured default invoice type per tenant — never guesses.
 *   - Tenant-safe: every query is explicitly scoped by company_id (the
 *     models here deliberately do NOT use Filament's BelongsToTenant — this
 *     runs in CLI with no panel tenant context), and the customer/type are
 *     re-loaded scoped + asserted to belong to the tenant before filing.
 *   - Reuses WhmcsInvoiceFiler::file() unchanged, so every existing guard
 *     (0%-exempt refusal, lockForUpdate, assertCanBeFiled, the outside-tx
 *     AADE submit) still applies. A guard that throws is caught per-row,
 *     logged loudly, and the row is left for the operator.
 *   - Every auto-filed row is logged (Log::info) and tagged in its notes
 *     ('Αυτόματη έκδοση (γκρινιάρης)') for the audit trail.
 *
 * Inert until the scheduler + a queue worker are live (see CLAUDE.md
 * Env-prep) — same as the rest of routes/console.php.
 *
 * Exit codes:
 *   0 success (incl. nothing to do)
 *   2 invalid usage (--tenant slug unknown)
 */
class WhmcsAutoIssue extends Command
{
    protected $signature = 'whmcs:auto-issue
        {--tenant= : Limit to one Company slug (default: every armed tenant).}
        {--dry-run : List what WOULD be auto-issued; file nothing.}';

    protected $description = 'WHMCS bridge: auto-file paid inbox rows for γκρινιάρης (immediate-invoice) customers on tenants that armed it. Files at AADE — gated by the per-tenant toggle + the scheduler flag.';

    private const AUDIT_NOTE = 'Αυτόματη έκδοση (γκρινιάρης) — whmcs:auto-issue.';

    public function handle(WhmcsInvoiceFiler $filer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slug = (string) $this->option('tenant');

        $tenants = $this->resolveTenants($slug);
        if ($tenants === null) {
            return self::INVALID;
        }

        if ($tenants->isEmpty()) {
            $this->info('No tenants have γκρινιάρης auto-issue armed (companies.whmcs_auto_issue_immediate). Nothing to do.');

            return self::SUCCESS;
        }

        $totalFiled = 0;
        $totalFailed = 0;
        $totalCandidates = 0;

        foreach ($tenants as $tenant) {
            [$filed, $failed, $candidates] = $this->processTenant($tenant, $filer, $dryRun);
            $totalFiled += $filed;
            $totalFailed += $failed;
            $totalCandidates += $candidates;
        }

        $verb = $dryRun ? 'would auto-issue' : 'auto-issued';
        $this->newLine();
        $this->info("Done. {$verb} {$totalFiled}/{$totalCandidates} γκρινιάρης row(s)".
            ($totalFailed > 0 ? "; {$totalFailed} failed (left in inbox)." : '.'));

        return self::SUCCESS;
    }

    /**
     * Resolve the armed tenants. Returns null on an unknown --tenant slug
     * (caller maps that to exit 2).
     *
     * @return Collection<int, Company>|null
     */
    private function resolveTenants(string $slug): ?Collection
    {
        if ($slug !== '') {
            $tenant = Company::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $this->error("No tenant with slug='{$slug}'.");

                return null;
            }
            if (! $tenant->hasWhmcsIntegration() || ! $tenant->whmcs_auto_issue_immediate) {
                $this->warn("Tenant '{$slug}' has not armed γκρινιάρης auto-issue (or WHMCS isn't configured). Nothing to do.");

                return collect();
            }

            return collect([$tenant]);
        }

        // All armed tenants. WHMCS-config check is belt-and-suspenders:
        // the filer's AADE submit doesn't need WHMCS, but the rows only
        // exist for WHMCS-configured tenants anyway.
        return Company::query()
            ->where('whmcs_auto_issue_immediate', true)
            ->whereNotNull('whmcs_api_url')
            ->where('whmcs_api_url', '!=', '')
            ->get();
    }

    /**
     * @return array{0:int,1:int,2:int} [filed, failed, candidates]
     */
    private function processTenant(Company $tenant, WhmcsInvoiceFiler $filer, bool $dryRun): array
    {
        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        // Never guess the invoice type — refuse the tenant without a default.
        $invoiceType = $tenant->whmcs_default_invoice_type_id
            ? InvoiceType::query()
                ->where('company_id', $tenant->id)
                ->whereKey($tenant->whmcs_default_invoice_type_id)
                ->first()
            : null;

        if ($invoiceType === null) {
            $this->warn('  No default invoice type configured (Company → WHMCS bridge → Auto-issue). Skipping tenant.');
            Log::warning('whmcs:auto-issue skipped tenant — no default invoice type', [
                'company_id' => $tenant->id,
                'slug' => $tenant->slug,
            ]);

            return [0, 0, 0];
        }

        $candidates = $this->candidates($tenant);

        if ($candidates->isEmpty()) {
            $this->line('  No γκρινιάρης rows awaiting issue.');

            return [0, 0, 0];
        }

        $filed = 0;
        $failed = 0;

        foreach ($candidates as $row) {
            $customer = $row->customer;   // eager-loaded, tenant-scoped, non-trashed

            // Defense in depth: the relation already guarantees this, but
            // assert before handing anything to the filer / AADE.
            if ($customer === null || $customer->company_id !== $tenant->id) {
                $this->line("  · #{$row->whmcs_invoice_id}: customer missing/cross-tenant — skipped.");

                continue;
            }

            $label = "#{$row->whmcs_invoice_id} → {$customer->name}";

            if ($dryRun) {
                $this->line("  · would issue {$label} as {$invoiceType->code}");
                $filed++;

                continue;
            }

            try {
                $result = $filer->file(
                    tenant: $tenant,
                    pending: $row,
                    customer: $customer,
                    invoiceType: $invoiceType,
                    filedByUserId: null,            // system-issued (no operator)
                    auditNote: self::AUDIT_NOTE,
                );
                $filed++;
                $this->line("  ✓ {$label} → {$result->invoice->invcode}".
                    ($result->mark ? " (MARK {$result->mark})" : ' (off-mode)'));
                Log::info('whmcs:auto-issue filed a γκρινιάρης row', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'whmcs_invoice_id' => $row->whmcs_invoice_id,
                    'pending_id' => $row->id,
                    'invoice_id' => $result->invoice->id,
                    'invcode' => $result->invoice->invcode,
                    'mark' => $result->mark,
                    'customer_id' => $customer->id,
                    'reason' => 'needs_immediate_invoice',
                ]);
            } catch (Throwable $e) {
                $failed++;
                $this->warn("  ✗ {$label}: {$e->getMessage()} — left in inbox.");
                Log::error('whmcs:auto-issue failed to file a γκρινιάρης row (left for operator)', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'whmcs_invoice_id' => $row->whmcs_invoice_id,
                    'pending_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$filed, $failed, $candidates->count()];
    }

    /**
     * The narrow, unambiguous, γκρινιάρης set for one tenant. Explicit
     * company_id scope (no BelongsToTenant here). Soft-deleted customers
     * are excluded by the relation's default scope → such rows yield a
     * null customer and are skipped in the loop.
     *
     * @return Collection<int, PendingWhmcsInvoice>
     */
    private function candidates(Company $tenant): Collection
    {
        return PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('status', PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->whereNull('invoice_id')
            ->whereNotNull('customer_id')
            // third_party_state: null (feature off / noop), 'none' (bills
            // the WHMCS client), or 'single' (resolved single third party).
            // 'multi' / unresolved 'single' are ingested as 'held' and so
            // never have status='pending_review' — but be explicit anyway.
            ->where(fn ($q) => $q
                ->whereNull('third_party_state')
                ->orWhereIn('third_party_state', [
                    PendingWhmcsInvoice::TP_NONE,
                    PendingWhmcsInvoice::TP_SINGLE,
                ]))
            ->whereHas('customer', fn ($q) => $q->where('needs_immediate_invoice', true))
            ->with(['customer:id,company_id,name,needs_immediate_invoice'])
            ->orderBy('id')
            ->get();
    }
}
