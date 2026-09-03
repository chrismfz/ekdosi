<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * «Τιμολόγιο πριν την πληρωμή» — stage the UNPAID WHMCS invoices of customers flagged
 * `needs_invoice_before_payment` into the inbox for MANUAL επί-πιστώσει issuance.
 *
 * The public-sector / Α.Ε. case: they issue a payment order only AFTER they receive a
 * τιμολόγιο. The tenant-wide fetch (`whmcs:fetch-pending`) only pulls PAID invoices, so
 * these never surfaced. Here we walk each flagged customer's WHMCS client id and pull
 * their `Unpaid` invoices, feeding them to the SAME ingestor as the paid path.
 *
 * SAFETY — never auto-issued. This only STAGES (the ingestor never files), and the
 * inbox rows carry `payload['status']='Unpaid'` so:
 *   - `PendingWhmcsInvoice::whmcsIsUnpaid()` badges them «Απλήρωτο» in the inbox;
 *   - the manual «Δημιουργία Παραστατικού» pre-selects `whmcs_default_unpaid_type_id`
 *     (`suggestedInvoiceTypeId()`) so the issued invoice stays an open receivable;
 *   - `WhmcsAutoIssue::chooseType()` HOLDS every unpaid row, and auto-issue candidates
 *     require `needs_immediate_invoice` (a DIFFERENT flag) — so nothing here is ever
 *     filed unattended, even for a customer flagged both.
 *
 * Reuses `WhmcsClient::getInvoicesForClient()` (all statuses, per client, paginated) and
 * `getInvoiceWithClient()` (the rich payload the ingestor's matcher needs) — no new WHMCS
 * transport. Native WHMCS API only (the bridge feed serves the paid-unfiled set); a
 * bridge variant is a later follow-up if needed.
 */
class WhmcsUnpaidFetcher
{
    public function __construct(
        private WhmcsClientFactory $factory,
        private WhmcsInvoiceIngestor $ingestor,
    ) {}

    /**
     * @throws WhmcsNotConfigured when the tenant has no WHMCS integration
     * @throws WhmcsAuthenticationFailed|WhmcsUnreachable tenant-fatal transport errors bubble to the caller
     */
    public function fetch(Company $tenant): UnpaidFetchSummary
    {
        $client = $this->factory->for($tenant);
        $minDate = $tenant->whmcs_invoice_min_date?->format('Y-m-d');

        // Flagged customers WITHOUT a WHMCS link can't be fetched — count them so the
        // operator knows to link them (they'd otherwise be silently invisible).
        $skippedNoLink = Customer::query()
            ->where('company_id', $tenant->id)
            ->where('needs_invoice_before_payment', true)
            ->whereNull('whmcs_client_id')
            ->count();

        $customers = Customer::query()
            ->where('company_id', $tenant->id)
            ->where('needs_invoice_before_payment', true)
            ->whereNotNull('whmcs_client_id')
            ->get(['id', 'name', 'whmcs_client_id']);

        $created = 0;
        $updated = 0;
        $unpaidSeen = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            try {
                $invoices = $client->getInvoicesForClient((int) $customer->whmcs_client_id, $minDate);
            } catch (WhmcsAuthenticationFailed|WhmcsUnreachable $e) {
                // TENANT-FATAL: creds rejected / host down. Every remaining customer would
                // fail the same way — propagate so the command returns the right exit code
                // (4 / 5) instead of grinding through the rest against a dead endpoint.
                throw $e;
            } catch (WhmcsApiException $e) {
                // ISOLATED: one client's listing failed (e.g. a stale whmcs_client_id → WHMCS
                // result=error). Skip this customer and carry on — one bad link must not block
                // the whole tenant's «τιμολόγιο πριν την πληρωμή» inbox.
                $failed++;
                Log::warning('Unpaid-fetch: listing one client failed (skipped).', [
                    'company_id' => $tenant->id,
                    'customer_id' => $customer->id,
                    'whmcs_client_id' => $customer->whmcs_client_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($invoices as $row) {
                // ONLY unpaid — a paid/cancelled/refunded invoice of a flagged customer is
                // not this feature's concern (paid ones arrive via whmcs:fetch-pending).
                if (strcasecmp((string) ($row['status'] ?? ''), 'Unpaid') !== 0) {
                    continue;
                }

                $unpaidSeen++;
                $invoiceId = (int) ($row['id'] ?? 0);
                if ($invoiceId <= 0) {
                    continue;
                }

                try {
                    $payload = $client->getInvoiceWithClient($invoiceId);
                    if ($payload === null) {
                        // Vanished between the list and the detail call — skip quietly.
                        continue;
                    }

                    $result = $this->ingestor->ingest($tenant, $payload);
                    $result->created ? $created++ : $updated++;
                } catch (WhmcsAuthenticationFailed|WhmcsUnreachable $e) {
                    // TENANT-FATAL (getInvoiceWithClient hit the same dead endpoint) →
                    // propagate for the right exit code, don't swallow as a per-row failure.
                    throw $e;
                } catch (\Throwable $e) {
                    // Per-invoice non-fatal (a malformed row, an ingest constraint): log +
                    // count, keep going.
                    $failed++;
                    Log::warning('Unpaid-fetch: staging one invoice failed (skipped).', [
                        'company_id' => $tenant->id,
                        'customer_id' => $customer->id,
                        'whmcs_invoice_id' => $invoiceId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return new UnpaidFetchSummary(
            customers: $customers->count(),
            skippedNoLink: $skippedNoLink,
            unpaidSeen: $unpaidSeen,
            created: $created,
            updated: $updated,
            failed: $failed,
        );
    }
}
