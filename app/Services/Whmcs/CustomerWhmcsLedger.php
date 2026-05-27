<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Support\Facades\DB;

/**
 * PR #34 followup: per-customer WHMCS comparison view.
 *
 * Fetches a single customer's invoices from WHMCS and cross-references
 * each against TWO ekdosi-side sources:
 *   - pending_whmcs_invoices  (Stage B-1 staging, new path)
 *   - whmcs_invoice_log       (historic ETL-imported links from legacy
 *                              ekdosi's AUTO_INVOICE_LOG)
 *
 * Returns enriched rows so the operator can see at a glance:
 *   - which WHMCS invoices are already filed historically (no action
 *     needed)
 *   - which are staged for review (operator click in the inbox to
 *     file - Stage B-2 territory)
 *   - which exist in WHMCS but ekdosi has no record of (need to fetch
 *     them into the inbox, OR they're deliberately excluded - test
 *     invoices etc.)
 *
 * NOT a service for actually filing or staging. It's read-only - the
 * operator looks at the comparison, makes a decision, then uses the
 * tenant-wide Fetch button or the inbox to act.
 *
 * Tenant scoping: pulls $customer->company_id everywhere explicitly
 * (no global tenant scope on the WHMCS-bridge models per CLAUDE.md).
 */
class CustomerWhmcsLedger
{
    public function __construct(private WhmcsClientFactory $factory)
    {
    }

    public function fetchFor(Customer $customer): CustomerWhmcsLedgerResult
    {
        if ($customer->whmcs_client_id === null) {
            return new CustomerWhmcsLedgerResult(
                rows: [],
                error: 'Customer is not linked to a WHMCS client. Use the "Link to WHMCS" action first.',
            );
        }

        $tenant = $customer->company;
        if ($tenant === null) {
            return new CustomerWhmcsLedgerResult(
                rows: [],
                error: 'Customer has no associated tenant.',
            );
        }

        try {
            $client = $this->factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            return new CustomerWhmcsLedgerResult(rows: [], error: $e->getMessage());
        }

        $minDate = $tenant->whmcs_invoice_min_date?->format('Y-m-d');

        try {
            $whmcsRows = $client->getInvoicesForClient(
                whmcsUserId: $customer->whmcs_client_id,
                minDate: $minDate,
            );
        } catch (WhmcsAuthenticationFailed $e) {
            return new CustomerWhmcsLedgerResult(rows: [], error: 'WHMCS authentication failed: '.$e->getMessage());
        } catch (WhmcsUnreachable $e) {
            return new CustomerWhmcsLedgerResult(rows: [], error: 'WHMCS unreachable: '.$e->getMessage());
        } catch (WhmcsApiException $e) {
            return new CustomerWhmcsLedgerResult(rows: [], error: 'WHMCS error: '.$e->getMessage());
        }

        if ($whmcsRows === []) {
            return new CustomerWhmcsLedgerResult(rows: []);
        }

        $whmcsIds = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $whmcsRows);
        $whmcsIds = array_filter($whmcsIds, static fn ($id) => $id > 0);

        // Cross-reference: staged rows (pending_whmcs_invoices).
        $staged = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->whereIn('whmcs_invoice_id', $whmcsIds)
            ->get()
            ->keyBy('whmcs_invoice_id');

        // Cross-reference: historic links (whmcs_invoice_log + invoices
        // join for the legacy_id). Single query to avoid N+1.
        // Excludes soft-deleted invoices: if the operator (or ETL)
        // trashed the historic invoice, surfacing it here as kind=historic
        // would render a "filed historically" badge with a link that
        // 404s in InvoiceResource (which respects SoftDeletes). Let it
        // degrade to kind=absent instead.
        $historic = DB::table('whmcs_invoice_log')
            ->leftJoin('invoices', 'invoices.id', '=', 'whmcs_invoice_log.invoice_id')
            ->where('whmcs_invoice_log.company_id', $tenant->id)
            ->whereIn('whmcs_invoice_log.whmcs_invoice_id', $whmcsIds)
            ->whereNotNull('whmcs_invoice_log.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->select(
                'whmcs_invoice_log.whmcs_invoice_id',
                'whmcs_invoice_log.invoice_id',
                'invoices.legacy_id as invoice_legacy_id',
                'invoices.invcode as invoice_invcode',
            )
            ->get()
            ->keyBy('whmcs_invoice_id');

        $enriched = [];
        $stagedCount = 0;
        $historicCount = 0;
        $absentCount = 0;

        foreach ($whmcsRows as $row) {
            $whmcsId = (int) ($row['id'] ?? 0);
            if ($whmcsId <= 0) {
                continue;
            }

            $ekdosiState = $this->resolveState($whmcsId, $staged, $historic);
            match ($ekdosiState['kind']) {
                'staged'   => $stagedCount++,
                'historic' => $historicCount++,
                'absent'   => $absentCount++,
                default    => null,
            };

            $enriched[] = [
                'whmcs_id'      => $whmcsId,
                'date'          => (string) ($row['date'] ?? ''),
                'datepaid'      => $this->normaliseEmptyDate($row['datepaid'] ?? null),
                'total'         => (string) ($row['total'] ?? '0.00'),
                'currency'      => (string) ($row['currencycode'] ?? ''),
                'status'        => (string) ($row['status'] ?? ''),
                'invoiced_flag' => array_key_exists('invoiced', $row) ? (int) $row['invoiced'] : null,
                'ekdosi_state'  => $ekdosiState,
            ];
        }

        return new CustomerWhmcsLedgerResult(
            rows: $enriched,
            stagedCount: $stagedCount,
            historicCount: $historicCount,
            absentCount: $absentCount,
        );
    }

    /**
     * @return array{kind: string, ...}
     */
    private function resolveState(int $whmcsId, $staged, $historic): array
    {
        if ($staged->has($whmcsId)) {
            $row = $staged->get($whmcsId);
            return [
                'kind'       => 'staged',
                'pending_id' => $row->id,
                'status'     => $row->status,
                'mark'       => $row->mydata_mark,
            ];
        }
        if ($historic->has($whmcsId)) {
            $row = $historic->get($whmcsId);
            return [
                'kind'              => 'historic',
                'invoice_id'        => (int) $row->invoice_id,
                'invoice_legacy_id' => $row->invoice_legacy_id,
                'invoice_invcode'   => $row->invoice_invcode,
            ];
        }
        return ['kind' => 'absent'];
    }

    /**
     * WHMCS returns the sentinel '0000-00-00 00:00:00' for "no value"
     * on date columns. Treat it as null so the view doesn't render
     * a fake date.
     */
    private function normaliseEmptyDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }
        return $raw;
    }
}
