<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Support\Afm;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * G8 phase 2 — άμεση τιμολόγηση auto-issue.
 *
 *   php artisan whmcs:auto-issue [--tenant=SLUG] [--dry-run]
 *
 * Files paid inbox rows (already staged by whmcs:fetch-pending) at AADE
 * WITHOUT operator review — but ONLY for the narrow, unambiguous case the
 * operator asked for:
 *
 *   • the tenant armed it (companies.whmcs_auto_issue_immediate), AND
 *   • the matched customer is flagged needs_immediate_invoice (άμεση τιμολόγηση), AND
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
 *   - Calls WhmcsInvoiceFiler::file(unattended: true), so every existing guard
 *     (0%-exempt refusal, lockForUpdate, assertCanBeFiled, the outside-tx
 *     AADE submit) still applies PLUS the unattended-only hold: any 0%-VAT line
 *     (WHMCS Apply-Tax-off — may owe 24% or be a genuine exemption) is NEVER
 *     auto-filed; the row is held for a human. A guard that throws is caught
 *     per-row, logged loudly, and the row is left for the operator.
 *   - Every auto-filed row is logged (Log::info) and tagged in its notes
 *     ('Αυτόματη έκδοση (άμεση τιμολόγηση)') for the audit trail.
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

    protected $description = 'WHMCS bridge: auto-file paid inbox rows for άμεση τιμολόγηση (immediate-invoice) customers on tenants that armed it. Files at AADE — gated by the per-tenant toggle + the scheduler flag.';

    private const AUDIT_NOTE = 'Αυτόματη έκδοση (άμεση τιμολόγηση) — whmcs:auto-issue.';

    public function handle(WhmcsInvoiceFiler $filer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slug = (string) $this->option('tenant');

        $tenants = $this->resolveTenants($slug);
        if ($tenants === null) {
            return self::INVALID;
        }

        if ($tenants->isEmpty()) {
            $this->info('No tenants have άμεση τιμολόγηση auto-issue armed (companies.whmcs_auto_issue_immediate). Nothing to do.');

            return self::SUCCESS;
        }

        $totalFiled = 0;
        $totalFailed = 0;
        $totalHeld = 0;
        $totalCandidates = 0;

        foreach ($tenants as $tenant) {
            [$filed, $failed, $candidates, $held] = $this->processTenant($tenant, $filer, $dryRun);
            $totalFiled += $filed;
            $totalFailed += $failed;
            $totalHeld += $held;
            $totalCandidates += $candidates;
        }

        $verb = $dryRun ? 'would auto-issue' : 'auto-issued';
        $this->newLine();
        $this->info("Done. {$verb} {$totalFiled}/{$totalCandidates} άμεση τιμολόγηση row(s)".
            ($totalFailed > 0 ? "; {$totalFailed} failed (left in inbox)." : '.'));
        // Held rows are CORRECT behaviour (type intent the unattended path can't
        // safely resolve — e.g. receipt-intent with no default receipt type), but
        // surface the count + a warn so an armed tenant doesn't silently pile up
        // un-issued rows after deploy (configure whmcs_default_receipt_type_id).
        if ($totalHeld > 0) {
            $this->warn("  {$totalHeld} row(s) held for type intent — set a default receipt type or fill ΑΦΜ. See the inbox.");
        }

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
                $this->warn("Tenant '{$slug}' has not armed άμεση τιμολόγηση auto-issue (or WHMCS isn't configured). Nothing to do.");

                return collect();
            }

            return collect([$tenant]);
        }

        // All armed tenants. Require full WHMCS integration (url + creds),
        // mirroring the slug path's check — the rows only exist for
        // WHMCS-configured tenants anyway. hasWhmcsIntegration() is a
        // method (checks encrypted cols), so filter in PHP.
        return Company::query()
            ->where('whmcs_auto_issue_immediate', true)
            ->get()
            ->filter(fn (Company $c) => $c->hasWhmcsIntegration())
            ->values();
    }

    /**
     * @return array{0:int,1:int,2:int,3:int} [filed, failed, candidates, held]
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

            return [0, 0, 0, 0];
        }

        // Optional «Απόδειξη» type for receipt-intent rows (customer didn't ask
        // for a τιμολόγιο, or a single third-party route is flagged is_receipt).
        // When unset, receipt-intent rows are HELD for the operator rather than
        // mis-issued as invoices.
        $receiptType = $tenant->whmcs_default_receipt_type_id
            ? InvoiceType::query()
                ->where('company_id', $tenant->id)
                ->whereKey($tenant->whmcs_default_receipt_type_id)
                ->first()
            : null;

        $candidates = $this->candidates($tenant);

        if ($candidates->isEmpty()) {
            $this->line('  No άμεση τιμολόγηση rows awaiting issue.');

            return [0, 0, 0, 0];
        }

        $filed = 0;
        $failed = 0;
        $held = 0;

        foreach ($candidates as $row) {
            $customer = $row->customer;   // eager-loaded, tenant-scoped, non-trashed

            // Defense in depth: the relation already guarantees this, but
            // assert before handing anything to the filer / AADE.
            if ($customer === null || $customer->company_id !== $tenant->id) {
                $this->line("  · #{$row->whmcs_invoice_id}: customer missing/cross-tenant — skipped.");

                continue;
            }

            $label = "#{$row->whmcs_invoice_id} → {$customer->name}";

            // Pick receipt vs invoice from the row's intent — never the legacy
            // «primary VAT decides everything». A receipt-intent row with no
            // default receipt type (or an ambiguous third-party) is HELD, not
            // mis-issued.
            [$chosenType, $holdReason] = $this->chooseType($row, $invoiceType, $receiptType);
            if ($chosenType === null) {
                $held++;
                $this->line("  · {$label}: {$holdReason} — left in inbox.");
                Log::info('whmcs:auto-issue held a row (type intent needs operator)', [
                    'company_id' => $tenant->id, 'slug' => $tenant->slug,
                    'whmcs_invoice_id' => $row->whmcs_invoice_id, 'pending_id' => $row->id,
                    'reason' => $holdReason,
                ]);

                continue;
            }

            if ($dryRun) {
                $this->line("  · would issue {$label} as {$chosenType->code}");
                $filed++;

                continue;
            }

            try {
                $result = $filer->file(
                    tenant: $tenant,
                    pending: $row,
                    customer: $customer,
                    invoiceType: $chosenType,
                    filedByUserId: null,            // system-issued (no operator)
                    auditNote: self::AUDIT_NOTE,
                    unattended: true,               // → holds 0%-VAT rows for a human
                );
                $filed++;
                $this->line("  ✓ {$label} → {$result->invoice->invcode}".
                    ($result->mark ? " (MARK {$result->mark})" : ' (off-mode)'));
                Log::info('whmcs:auto-issue filed a άμεση τιμολόγηση row', [
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
                Log::error('whmcs:auto-issue failed to file a άμεση τιμολόγηση row (left for operator)', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'whmcs_invoice_id' => $row->whmcs_invoice_id,
                    'pending_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$filed, $failed, $candidates->count(), $held];
    }

    /**
     * Choose the document type for a row from its receipt-vs-invoice INTENT, or
     * signal a HOLD. Returns [InvoiceType, null] to file, or [null, reason] to
     * leave the row for the operator.
     *
     * Intent:
     *   - SINGLE third-party: the route's explicit `is_receipt` is authoritative
     *     (decided by the third party, not the WHMCS client's VAT). Mixed flags
     *     are ambiguous → hold.
     *   - own billing: an EXPLICIT wantsinvoice=false → «Απόδειξη»; true or
     *     unknown(null) → «Τιμολόγιο» (backward compatible — tenants that never
     *     mapped `wantsinvoice` keep issuing the invoice type).
     * A receipt intent with no default receipt type configured → hold.
     *
     * @return array{0: ?InvoiceType, 1: ?string}
     */
    private function chooseType(PendingWhmcsInvoice $row, InvoiceType $invoiceType, ?InvoiceType $receiptType): array
    {
        // Auto-issue is PAID-ONLY (this enforces it — the staging feed alone
        // doesn't, since a manual plugin push / webhook can stage an UNPAID row).
        // An unpaid WHMCS invoice must be issued επί πιστώσει (open receivable),
        // which the cash-term auto-issue defaults can't express — filing it here
        // would silently record a real receivable as SETTLED. Hold it for the
        // operator, who gets the unpaid credit-term type pre-selected in the inbox.
        if ($row->whmcsIsUnpaid()) {
            return [null, 'WHMCS ΑΠΛΗΡΩΤΟ — χρειάζεται χειριστή (έκδοση επί πιστώσει, όχι αυτόματη)'];
        }

        // A WHMCS consolidated/mass-pay invoice (lines reference other invoices,
        // no VAT of its own) is never auto-issued — it's a payment-grouping
        // artefact, not a sale. New rows are already ingested as 'held'; this
        // guards any pre-existing 'pending_review' row (staged before the
        // detector landed) from auto-filing 0% gross to AADE.
        if ($row->isConsolidatedPayment()) {
            return [null, PendingWhmcsInvoice::consolidatedPaymentReason($row->consolidatedPaymentRefs())];
        }

        // A container the operator manually CONSOLIDATED reads as a normal sale to
        // isConsolidatedPayment() above (its merged payload carries real child lines,
        // not references), so that guard misses it. But it's the product of a
        // deliberate «Ενοποίηση» expecting manual review («Δημιουργία Παραστατικού»),
        // so never auto-file it — hold for the operator. (Was BACKLOG P2-5.)
        if ($row->hasBeenConsolidated()) {
            return [null, 'ενοποιημένο συγκεντρωτικό — έκδοση με χειριστή («Δημιουργία Παραστατικού»)'];
        }

        if ($row->third_party_state === PendingWhmcsInvoice::TP_SINGLE) {
            $isReceipt = $row->singleThirdPartyReceipt();
            if ($isReceipt === null) {
                return [null, 'τρίτος με ασαφή/μικτή σήμανση απόδειξης-τιμολογίου'];
            }
            if ($isReceipt) {
                return $receiptType !== null
                    ? [$receiptType, null]
                    : [null, 'τρίτος ζητά απόδειξη αλλά δεν έχει οριστεί προεπιλεγμένος τύπος απόδειξης'];
            }

            return [$invoiceType, null];
        }

        // Own billing. EXPLICIT invoice intent but no ekdosi ΑΦΜ on the customer
        // → can't file a valid τιμολόγιο (the counterpart ΑΦΜ comes from
        // customer.afm); HOLD for the operator to fill it rather than downgrade to
        // a receipt or file an empty-ΑΦΜ invoice. A WHMCS-typed vatno that isn't on
        // the ekdosi record does NOT count, and neither does a placeholder
        // («000000000» — no identity) — same rule as ownLinesAreReceipt()/needsAfm().
        if ($row->wantsInvoice() === true && Afm::uniqueKey($row->customer?->afm) === null) {
            return [null, 'ζητά τιμολόγιο αλλά λείπει ΑΦΜ στον πελάτη ekdosi — συμπλήρωσέ το πρώτα'];
        }

        // The primary customer's intent (no ΑΦΜ → απόδειξη; has ΑΦΜ +
        // wantsinvoice≠false → τιμολόγιο). Same predicate the manual splitter uses
        // for the own portion, so auto and manual agree.
        if ($row->ownLinesAreReceipt()) {
            return $receiptType !== null
                ? [$receiptType, null]
                : [null, 'ο πελάτης χρειάζεται απόδειξη (χωρίς ΑΦΜ ή δεν ζήτησε τιμολόγιο) αλλά δεν έχει οριστεί προεπιλεγμένος τύπος απόδειξης'];
        }

        return [$invoiceType, null];
    }

    /**
     * The narrow, unambiguous, immediate-invoice set for one tenant. Explicit
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
            // WH-3: skip rows the legacy ekdosi app already filed
            // (tblinvoices.invoiced != 0). Auto-issuing them would
            // double-declare income at AADE during the dual-run. The filer's
            // assertCanBeFiled() is the belt-and-suspenders backstop; this
            // keeps them out of the candidate set entirely (null/0 = not filed
            // in legacy → eligible).
            ->where(fn ($q) => $q->whereNull('legacy_invoiced')->orWhere('legacy_invoiced', 0))
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
            // afm is needed by ownLinesAreReceipt() (no ΑΦΜ → απόδειξη).
            ->with(['customer:id,company_id,name,afm,needs_immediate_invoice'])
            ->orderBy('id')
            ->get();
    }
}
