<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Support\Facades\Log;

/**
 * Dual-run helper ("test new, keep invoicing from old"): refresh the
 * `legacy_invoiced` flag on still-actionable inbox rows by asking the bridge
 * for the live `tblinvoices.invoiced` value of each WHMCS invoice.
 *
 * Why a refresh (vs reading it at ingest): getPendingInvoices EXCLUDES already
 * invoiced=0 invoices, so at stage time every row reads "not invoiced". The
 * case that matters — the partner invoices it from the LEGACY app AFTER it was
 * staged in ekdosi's inbox — only shows up by re-checking the live flag. This
 * runs after each fetch and on demand from the inbox so the operator sees a
 * "already invoiced in legacy" warning before issuing a duplicate.
 *
 * Targets ONLY actionable rows (pending_review / held, not yet linked to an
 * ekdosi invoice). Filed / drafted / split rows already produced a παραστατικό
 * (double-invoicing risk is moot) and a filed row is audit-frozen anyway.
 *
 * Degrades to a no-op (returns 0) when the bridge isn't configured / not
 * deployed / unreachable — same "can't break the inbox" guarantee as the
 * third-party resolution path.
 */
class LegacyInvoicedRefresher
{
    public function __construct(
        private readonly WhmcsBridgeClientFactory $bridgeFactory,
    ) {}

    /**
     * Refresh legacy_invoiced for the tenant's actionable inbox rows.
     *
     * @return int the number of rows whose legacy_invoiced value changed
     */
    public function refresh(Company $tenant): int
    {
        $rows = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->whereNull('invoice_id')
            ->whereIn('status', [
                PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
                PendingWhmcsInvoice::STATUS_HELD,
            ])
            ->get(['id', 'whmcs_invoice_id', 'legacy_invoiced']);

        if ($rows->isEmpty()) {
            return 0;
        }

        try {
            $client = $this->bridgeFactory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            return 0;   // bridge not configured for this tenant — nothing to do
        }

        $ids = $rows->pluck('whmcs_invoice_id')->map(fn ($v) => (int) $v)->all();

        try {
            $flags = $client->getInvoicedFlags($ids);
        } catch (WhmcsUnreachable|WhmcsApiException $e) {
            // Bridge unreachable / resolve.php not deployed yet — non-fatal,
            // leave the existing values untouched.
            Log::info('Legacy-invoiced refresh skipped — bridge unavailable.', [
                'company_id' => $tenant->id,
                'reason' => $e->getMessage(),
            ]);

            return 0;
        }

        $changed = 0;
        foreach ($rows as $row) {
            // Missing from the response → unknown; don't clobber a known value.
            if (! array_key_exists($row->whmcs_invoice_id, $flags)) {
                continue;
            }
            $flag = $flags[$row->whmcs_invoice_id];
            if ((int) $row->legacy_invoiced === $flag && $row->legacy_invoiced !== null) {
                continue;
            }
            // update() is safe here: these rows are pending_review/held, so the
            // audit-freeze observer (which only blocks status=filed) permits it.
            $row->update(['legacy_invoiced' => $flag]);
            $changed++;
        }

        return $changed;
    }
}
