<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Afm;
use App\Support\InvoiceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visibility (AFM-keyed): the per-client "Παραστατικά ekdosi" card the
 * WHMCS-side ekdosi_bridge plugin renders on a client's admin profile —
 * keyed by ΑΦΜ, the ONLY link that actually survives in the data.
 *
 *   POST /webhooks/whmcs/{slug}/invoices-by-afm
 *   X-Webhook-Signature: sha256=<hex hmac of raw body>
 *   {"afms": ["123456789", "998482379", ...]}
 *
 * WHY ΑΦΜ and not a stored WHMCS→ekdosi id:
 * The legacy Firebird app never persisted the WHMCS client id (CUSTOMER has
 * no CS/WHMCS column) nor a per-invoice WHMCS link (AUTO_INVOICE_LOG carries
 * only LOG_ID/CS_INVID/LOG_MESSAGE — no ekdosi invoice id). Verified against
 * the restored production DB: customers.whmcs_client_id is NULL for 100% of
 * rows and whmcs_invoice_log is empty. So the historical link is
 * unrecoverable structurally — but BOTH sides carry the ΑΦΜ. ekdosi
 * customers.afm is populated; matching on it lights up every imported
 * VALID invoice with no re-import.
 *
 * WHY an AFM *set* (not one AFM):
 * Third-party invoicing ("Παραστατικά σε τρίτους" / per-product→other-VAT,
 * ported from legacy/whmcs/timologia into ekdosi_bridge) means one WHMCS
 * client routes different services to different ΑΦΜ — their own plus N
 * third-party contacts (mod_ekdosi_contacts.gr_vatno). The plugin gathers
 * the whole set (client tax_id + routed contacts) and we return invoices
 * for any of them, grouped under each ΑΦΜ key so the plugin can render
 * «Δικά του» vs «Τρίτοι».
 *
 * Auth mirrors WhmcsInvoicePaidController: HMAC-SHA256 over the RAW request
 * body, same whmcs_webhook_secret. POST (not GET) because the AFM set is a
 * list — and signing the raw body is the established scheme for our
 * body-carrying webhooks.
 *
 * Read-only: never writes. Tenant-scoped by construction (Customer/Invoice
 * filtered on company_id). Caps both the requested AFM count and the
 * returned invoice count so a crafted request can't ask for the world.
 *
 * Response (200 OK):
 *   {
 *     "found": true,
 *     "afms": {
 *       "998482379": {
 *         "customer_id": 412,
 *         "customer_name": "ACME ΕΠΕ",
 *         "invoices": [
 *           {"ekdosi_invoice_id": 130, "ekdosi_invcode": "ΤΠΥ130",
 *            "local_status": "active", "mydata_state": "VALID",
 *            "mydata_mark": "400013690089505", "issued_at": "2026-05-12"},
 *           ...
 *         ]
 *       },
 *       "123456789": null    // no ekdosi customer with this ΑΦΜ
 *     }
 *   }
 */
class WhmcsInvoicesByAfmController
{
    /** Max ΑΦΜ accepted per request (a client + their third-party contacts). */
    private const MAX_AFMS = 200;

    /**
     * Global cap on invoices returned across ALL requested ΑΦΜ (newest first).
     * Bounds the whole response in one query — not per-ΑΦΜ — so a large ΑΦΜ
     * set can't balloon the payload. 2000 comfortably covers a client + their
     * third-party contacts' visible history.
     */
    private const MAX_INVOICES_TOTAL = 2000;

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->logRejection($request, $slug, 'tenant_not_found');

            return new JsonResponse(['error' => 'tenant_not_found'], Response::HTTP_NOT_FOUND);
        }

        $secret = (string) ($tenant->whmcs_webhook_secret ?? '');
        if ($secret === '') {
            $this->logRejection($request, $slug, 'webhook_secret_not_configured');

            return new JsonResponse([
                'error' => 'webhook_secret_not_configured',
                'message' => 'Tenant exists but has no whmcs_webhook_secret. Set it in the company config form.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->verifySignature($request, $secret)) {
            $this->logRejection($request, $slug, 'invalid_signature');

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $afms = $this->normaliseAfms($request->json('afms'));
        if ($afms === []) {
            return new JsonResponse([
                'error' => 'missing_or_invalid_afms',
                'message' => 'Body must be {"afms": ["123456789", ...]} with at least one non-empty ΑΦΜ.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Match customers by ΑΦΜ IDENTITY — the key UNIQUE(company_id, afm_key)
        // is built on (Afm::uniqueKey: prefix/spaces/dashes folded, a foreign
        // VAT keeps its letters), so an identity resolves to exactly ONE
        // customer — no «keep the last» guess. The plugin sends digits only, so
        // a foreign VAT («CY10259033P») arrives as «10259033»: resolve those by
        // the digits of a LETTERED key as a second step, and only when the
        // digits are unambiguous (two foreign keys folding to the same digits
        // → null, never a coin toss). A pure-digit key is never shadowed.
        $byKey = Customer::query()
            ->where('company_id', $tenant->id)
            ->whereNotNull('afm_key')
            ->get(['id', 'afm', 'afm_key', 'name'])
            ->keyBy(fn (Customer $c): string => (string) $c->afm_key);

        $byDigits = [];
        foreach ($byKey as $key => $customer) {
            $key = (string) $key;
            if (ctype_digit($key)) {
                continue;
            }
            $digits = Afm::digits($key);
            if ($digits === '') {
                continue;
            }
            $byDigits[$digits] = array_key_exists($digits, $byDigits) ? null : $customer;
        }

        $customers = collect();
        foreach ($afms as $afm) {
            $hit = $byKey->get($afm) ?? (ctype_digit($afm) ? ($byDigits[$afm] ?? null) : null);
            if ($hit !== null) {
                $customers[$afm] = $hit;
            }
        }

        // ONE query for all matched customers' invoices (not one per ΑΦΜ),
        // globally capped, then grouped per customer in PHP. The aggregate
        // cap bounds the response so a 200-ΑΦΜ request can't materialise
        // hundreds of thousands of rows.
        $byCustomer = $this->invoicesByCustomer($tenant->id, $customers->pluck('id')->all());

        $result = [];
        foreach ($afms as $afm) {
            $customer = $customers->get($afm);
            if ($customer === null) {
                $result[$afm] = null;   // no ekdosi customer with this ΑΦΜ

                continue;
            }

            $result[$afm] = [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'invoices' => $byCustomer[$customer->id] ?? [],
            ];
        }

        return new JsonResponse([
            'found' => true,
            'afms' => $result,
        ], Response::HTTP_OK);
    }

    /**
     * Live invoices for the given customer ids, in ONE query, newest first,
     * grouped into customer_id => list<row>. `live()` drops locally- and
     * AADE-cancelled documents so the card doesn't surface withdrawn
     * παραστατικά as if they stand.
     *
     * The single global `limit` (MAX_INVOICES_TOTAL) bounds the whole
     * response regardless of how many ΑΦΜ were requested — the N+1 per-ΑΦΜ
     * loop and the unbounded-aggregate footgun the review flagged. For the
     * realistic case (a client + a handful of third-party contacts) the cap
     * is never hit; a tenant with one customer holding tens of thousands of
     * invoices simply gets the newest MAX_INVOICES_TOTAL of them.
     *
     * @param  list<int>  $customerIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function invoicesByCustomer(int $companyId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $query = Invoice::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds);

        $grouped = [];
        InvoiceScope::live($query)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(self::MAX_INVOICES_TOTAL)
            ->get(['id', 'customer_id', 'invcode', 'local_status', 'mydata_state', 'mydata_mark', 'issued_at'])
            ->each(function (Invoice $i) use (&$grouped): void {
                $grouped[$i->customer_id][] = [
                    'ekdosi_invoice_id' => $i->id,
                    'ekdosi_invcode' => $i->invcode,
                    'local_status' => $i->local_status,
                    'mydata_state' => $i->mydata_state,
                    'mydata_mark' => $i->mydata_mark,
                    'issued_at' => $i->issued_at?->toDateString(),
                ];
            });

        return $grouped;
    }

    /**
     * Clean the inbound ΑΦΜ list: strip non-digits (WHMCS tax_id fields are
     * free-text and pick up spaces / "EL" prefixes / dashes), drop empties,
     * de-dupe, cap. Returns a list<string> of bare numeric ΑΦΜ.
     *
     * @return list<string>
     */
    private function normaliseAfms(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            // The identity key; a placeholder («000000000») has none but stays
            // in the response as an honest null instead of turning into a 400.
            $afm = Afm::uniqueKey((string) $value) ?? Afm::digits($value);
            if ($afm === '') {
                continue;
            }
            $clean[$afm] = true;   // de-dupe via keys
            if (count($clean) >= self::MAX_AFMS) {
                break;
            }
        }

        // Cast keys back to string: PHP coerces all-numeric array keys to int,
        // so array_keys() would otherwise hand back int|string (a typing
        // landmine for any strict === / typed downstream use).
        return array_map('strval', array_keys($clean));
    }

    private function verifySignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Webhook-Signature', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $sent = substr($header, strlen('sha256='));
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $sent);
    }

    private function logRejection(Request $request, string $slug, string $reason): void
    {
        $sig = (string) $request->header('X-Webhook-Signature', '');
        Log::warning('whmcs.invoices-by-afm.rejected', [
            'reason' => $reason,
            'slug' => $slug,
            'ip' => $request->ip(),
            'sig_prefix' => $sig === '' ? '<missing>' : substr($sig, 0, 15).'...',
        ]);
    }
}
