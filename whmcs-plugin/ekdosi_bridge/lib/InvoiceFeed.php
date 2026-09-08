<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use Illuminate\Support\Collection;
use WHMCS\Database\Capsule;

require_once __DIR__.'/ThirdPartyStore.php';
require_once __DIR__.'/InvoiceMarkStore.php';

/**
 * Slice 1 of the "bridge as the source of truth" design
 * (docs/bridges-connectors.md): build, INSIDE WHMCS, the same rich invoice
 * payload ekdosi's ingestor expects — so ekdosi can fetch the inbox feed from
 * us instead of WHMCS's native API.
 *
 * Each payload is shape-compatible with WhmcsClient::getInvoiceWithClient
 * (invoice fields + merged client identity + customfields + line items), so the
 * ekdosi side (WhmcsInvoiceIngestor / WhmcsCustomerMatcher / WhmcsInvoiceMapper)
 * consumes it UNCHANGED. Built from tblinvoices/tblinvoiceitems/tblclients/
 * tblcustomfields(values)/tblcurrencies via Capsule — one paginated, server-side
 * filtered query set instead of the native API's 1+2N round-trips + the
 * limit/offset pagination quirk.
 *
 * READ-ONLY. The third-party routing + ΑΠΥ/ΤΠΥ kind (which we already compute in
 * ThirdPartyStore) fold into this feed in Slice 2; today the ekdosi ingestor
 * still resolves routing via the existing resolve op, so the staged result is
 * identical to the native path — only the SOURCE of the invoice payload changes.
 */
class InvoiceFeed
{
    /**
     * One page of normalized invoice payloads.
     *
     * @param  string  $status  'paid_unfiled' (default) | Paid|Unpaid|Cancelled|Refunded|All
     * @param  bool  $withRouting  also resolve + embed the third-party routing per
     *                             invoice (so the ekdosi ingestor needs no separate
     *                             resolve call). Ekdosi requests this only when its
     *                             whmcs_third_party_enabled is on — non-third-party
     *                             tenants pay nothing.
     * @return array{invoices: array<int, array<string, mixed>>, offset: int, count: int}
     */
    public static function fetch(string $status, ?string $since, int $offset, int $limit, bool $withRouting = false): array
    {
        $offset = max(0, $offset);
        $limit = ($limit < 1) ? 100 : min($limit, 200);

        $q = Capsule::table('tblinvoices')->orderBy('id', 'desc');
        if ($status === 'paid_unfiled' || $status === '') {
            // The inbox set: paid AND not yet filed in the legacy app (invoiced=0).
            $q->where('status', 'Paid')->where('invoiced', 0)
                // WH-9: also exclude anything ekdosi has ALREADY filed (a MARK row
                // exists). Post-cutover `invoiced` is no longer maintained, so
                // without this the feed re-walks every already-filed paid invoice
                // forever. `mod_ekdosi_invoice_marks.invoiceid` = tblinvoices.id
                // (one WHMCS DB per tenant → no tenant predicate needed).
                ->whereNotExists(function ($sub) {
                    $sub->from(InvoiceMarkStore::TABLE)
                        ->whereColumn(InvoiceMarkStore::TABLE.'.invoiceid', 'tblinvoices.id');
                });
        } elseif (in_array($status, ['Paid', 'Unpaid', 'Cancelled', 'Refunded'], true)) {
            $q->where('status', $status);
        }
        // 'All' → no status filter.
        if ($since !== null && $since !== '') {
            $q->where('date', '>=', $since);
        }

        $invoices = $q->offset($offset)->limit($limit)->get([
            'id', 'userid', 'date', 'duedate', 'datepaid', 'subtotal',
            'tax', 'taxrate', 'total', 'status', 'invoiced', 'paymentmethod',
        ]);

        $payloads = self::buildPayloads($invoices, $withRouting);

        return ['invoices' => $payloads, 'offset' => $offset, 'count' => count($payloads)];
    }

    /**
     * Plugin-API op=invoice: the SAME rich payload for ONE invoice id, with NO
     * status filter — the push path («Αποστολή στο Ekdosi») targets a specific
     * invoice the operator chose, and the ekdosi ingestor decides what to do
     * (incl. audit-preserving an already-filed one). Returns null when the id
     * doesn't exist. Mirrors the native getInvoiceWithClient(id) it replaces.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchOne(int $invoiceId, bool $withRouting = false): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $invoices = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->get([
                'id', 'userid', 'date', 'duedate', 'datepaid', 'subtotal',
                'tax', 'taxrate', 'total', 'status', 'invoiced', 'paymentmethod',
            ]);

        return self::buildPayloads($invoices, $withRouting)[0] ?? null;
    }

    /**
     * Build the normalized payloads for a set of tblinvoices rows (invoice
     * fields + merged client identity + customfields + line items + optional
     * third-party routing). Shared by the paginated feed (fetch) and the
     * single-invoice lookup (fetchOne) so BOTH emit an identical shape — the one
     * the ekdosi ingestor consumes unchanged.
     *
     * @param  Collection  $invoices
     * @return array<int, array<string, mixed>>
     */
    private static function buildPayloads($invoices, bool $withRouting): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $invoiceIds = $invoices->pluck('id')->map(fn ($v) => (int) $v)->all();
        $userIds = $invoices->pluck('userid')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();

        $itemsByInvoice = self::itemsByInvoice($invoiceIds);
        $clients = $userIds === []
            ? collect()
            : Capsule::table('tblclients')->whereIn('id', $userIds)->get([
                'id', 'firstname', 'lastname', 'companyname', 'email',
                'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'currency',
            ])->keyBy('id');
        $customFieldsByClient = self::customFieldsByClient($userIds);
        $currencyCodes = self::currencyCodes($clients);

        $payloads = [];
        foreach ($invoices as $inv) {
            $id = (int) $inv->id;
            $userId = (int) $inv->userid;
            $client = $clients->get($userId);
            $currencyId = $client ? (int) ($client->currency ?? 0) : 0;

            $entry = [
                // Invoice fields (mirror GetInvoice).
                'invoiceid' => $id,
                'id' => $id,
                'userid' => $userId,
                'date' => (string) ($inv->date ?? ''),
                'duedate' => (string) ($inv->duedate ?? ''),
                'datepaid' => (string) ($inv->datepaid ?? ''),
                'subtotal' => (string) ($inv->subtotal ?? '0'),
                'tax' => (string) ($inv->tax ?? '0'),
                'taxrate' => (string) ($inv->taxrate ?? '0'),
                'total' => (string) ($inv->total ?? '0'),
                'status' => (string) ($inv->status ?? ''),
                'invoiced' => (int) ($inv->invoiced ?? 0),
                // Payment gateway system name (banktransfer / stripe / paypal …) —
                // ekdosi maps it to a §8.12 payment method (WhmcsPaymentMap).
                'paymentmethod' => (string) ($inv->paymentmethod ?? ''),
                'currencycode' => $currencyCodes[$currencyId] ?? '',
                // Client identity (mirror the getInvoiceWithClient merge).
                'companyname' => $client->companyname ?? '',
                'firstname' => $client->firstname ?? '',
                'lastname' => $client->lastname ?? '',
                'email' => $client->email ?? '',
                'address1' => $client->address1 ?? '',
                'address2' => $client->address2 ?? '',
                'city' => $client->city ?? '',
                'state' => $client->state ?? '',
                'postcode' => $client->postcode ?? '',
                'country' => $client->country ?? '',
                'phonenumber' => $client->phonenumber ?? '',
                // Client custom fields as [{id, value}] — the matcher reads ΑΦΜ
                // (+ intent) by field id via the tenant's whmcs_custom_field_map.
                'customfields' => $customFieldsByClient[$userId] ?? [],
                // Line items in the GetInvoice nested shape.
                'items' => ['item' => $itemsByInvoice[$id] ?? []],
            ];

            // Slice 2: embed the third-party routing (same shape as resolve.php
            // op=resolve) so the ekdosi ingestor builds its ThirdPartyResolution
            // from the payload — no separate HTTP resolve call per invoice.
            // NOTE: resolveInvoice() re-fetches tblinvoiceitems per invoice (it
            // needs the full per-line contact[] shape, which the batch
            // bucketsForInvoices doesn't produce) — N extra single-invoice item
            // queries per page. Only paid by tenants with whmcs_third_party_enabled;
            // a batch variant emitting full lines is a future optimisation.
            if ($withRouting) {
                $entry['third_party'] = ThirdPartyStore::resolveInvoice($inv);
            }

            $payloads[] = $entry;
        }

        return $payloads;
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, list<array<string, mixed>>> invoiceid => [line, ...]
     */
    private static function itemsByInvoice(array $invoiceIds): array
    {
        $out = [];
        $rows = Capsule::table('tblinvoiceitems')
            ->whereIn('invoiceid', $invoiceIds)
            ->orderBy('id')
            ->get(['id', 'invoiceid', 'type', 'relid', 'description', 'amount', 'taxed']);

        // MYD-006 bridge: enrich each HOSTING line with its WHMCS product id
        // (tblhosting.packageid) + product group id (tblproducts.gid), so ekdosi can
        // classify by group («Web Hosting → υπηρεσία») with new packages inheriting.
        // Batched: hosting relids → packageids → gids, two queries per feed page.
        $productByLine = self::productKeysByLine($rows);

        foreach ($rows as $r) {
            $keys = $productByLine[(int) $r->id] ?? ['pid' => 0, 'gid' => 0];
            $out[(int) $r->invoiceid][] = [
                'id' => (int) $r->id,
                'type' => (string) ($r->type ?? ''),
                'relid' => (int) ($r->relid ?? 0),
                'description' => (string) ($r->description ?? ''),
                'amount' => (string) ($r->amount ?? '0'),
                'taxed' => (int) ($r->taxed ?? 0),
                // 0 when not a hosting line / unresolved (domains, addons, ad-hoc).
                // ekdosi maps by GROUP (gid); the group NAME comes from GetProducts
                // on the ekdosi side, so it's deliberately not fetched here.
                'whmcs_product_id' => $keys['pid'],
                'whmcs_group_id' => $keys['gid'],
            ];
        }

        return $out;
    }

    /**
     * Resolve, per invoice-item id, the WHMCS product id (packageid) + product
     * group id for HOSTING lines. Domains (tbldomains, no product) and other types
     * resolve to zeros. Batched: relid→packageid (tblhosting), packageid→gid
     * (tblproducts). The group NAME is not resolved here — ekdosi reads it from
     * GetProducts when building the mapping page.
     *
     * @param  Collection  $rows  tblinvoiceitems rows (id,type,relid)
     * @return array<int, array{pid:int, gid:int}> item id => keys
     */
    private static function productKeysByLine($rows): array
    {
        // Hosting relids per line.
        $hostingRelidByLine = [];
        foreach ($rows as $r) {
            $relid = (int) ($r->relid ?? 0);
            if ($relid > 0 && ThirdPartyStore::serviceType((string) ($r->type ?? '')) === 'hosting') {
                $hostingRelidByLine[(int) $r->id] = $relid;
            }
        }
        if ($hostingRelidByLine === []) {
            return [];
        }

        // relid → packageid (the WHMCS product id).
        $packageByHosting = Capsule::table('tblhosting')
            ->whereIn('id', array_values(array_unique($hostingRelidByLine)))
            ->pluck('packageid', 'id'); // hostingId => packageid

        // packageid → group id.
        $packageIds = array_values(array_unique(array_map('intval', $packageByHosting->all())));
        $packageIds = array_values(array_filter($packageIds, static fn ($p) => $p > 0));
        $products = $packageIds !== []
            ? Capsule::table('tblproducts')->whereIn('id', $packageIds)->get(['id', 'gid'])->keyBy('id')
            : collect();

        $out = [];
        foreach ($hostingRelidByLine as $itemId => $relid) {
            $pid = (int) ($packageByHosting[$relid] ?? 0);
            $gid = $pid > 0 && isset($products[$pid]) ? (int) $products[$pid]->gid : 0;
            $out[$itemId] = ['pid' => $pid, 'gid' => $gid];
        }

        return $out;
    }

    /**
     * Client custom-field values, [{id (fieldid), value}], keyed by client id.
     * Only `type='client'` fields (matches what GetClientsDetails returns).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<array{id: int, value: string}>>
     */
    private static function customFieldsByClient(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        $rows = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.type', 'client')
            ->whereIn('v.relid', $userIds)
            ->get(['v.fieldid as id', 'v.relid as clientid', 'v.value']);
        foreach ($rows as $r) {
            $out[(int) $r->clientid][] = [
                'id' => (int) $r->id,
                'value' => (string) ($r->value ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Resolve the distinct client currencies to their ISO codes.
     *
     * @param  Collection  $clients
     * @return array<int, string> currencyId => code
     */
    private static function currencyCodes($clients): array
    {
        $ids = $clients->pluck('currency')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Capsule::table('tblcurrencies')->whereIn('id', $ids)->get(['id', 'code']) as $c) {
            $out[(int) $c->id] = (string) $c->code;
        }

        return $out;
    }
}
