<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use WHMCS\Database\Capsule;

/**
 * Per-line relid inspector for an admin invoice.
 *
 * WHMCS re-runs the renewal/activation flow for every invoice item that still
 * carries a `relid` (> 0) when the invoice is marked PAID. For a partner who
 * renews domains by hand and only later marks one accumulated invoice paid,
 * that means a DOUBLE renewal. This read-only helper surfaces, per line:
 *   - whether a relid is present (the thing WHMCS acts on),
 *   - the linked domain/service name + its current nextduedate (a date already
 *     in the FUTURE is the tell-tale "already renewed" — paying would renew
 *     it a second time).
 *
 * Reuses ThirdPartyStore::serviceType() for the WHMCS-type → domain/hosting
 * mapping, and the same tblinvoiceitems read the bridge already does for
 * third-party routing. No writes here — the «Μηδενισμός relid» action lives in
 * the admin Controller.
 */
class RelidInspector
{
    /**
     * @return array<int, array{
     *     item_id:int, type:string, service_type:?string, relid:int,
     *     description:string, linked:?string, next_due:?string, expiry:?string,
     *     active:bool, renewable:bool, already_renewed:bool
     * }>
     */
    public static function items(int $invoiceId): array
    {
        $rows = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->orderBy('id')
            ->get(['id', 'type', 'relid', 'description']);

        if ($rows->isEmpty()) {
            return [];
        }

        // Batch-resolve the linked domain / service (name + next due date) so a
        // many-line invoice costs two extra queries, not N.
        $domainIds = [];
        $hostingIds = [];
        foreach ($rows as $r) {
            $relid = (int) ($r->relid ?? 0);
            if ($relid <= 0) {
                continue;
            }
            $st = ThirdPartyStore::serviceType((string) ($r->type ?? ''));
            if ($st === 'domain') {
                $domainIds[$relid] = true;
            } elseif ($st === 'hosting') {
                $hostingIds[$relid] = true;
            }
        }

        // Domains carry BOTH a billing date (nextduedate) and the registry
        // expiry (expirydate) — showing the latter lets the operator see when it
        // really expires vs what WHMCS will bill. Hosting has no registry expiry.
        $domains = $domainIds !== []
            ? Capsule::table('tbldomains')->whereIn('id', array_keys($domainIds))->get(['id', 'domain', 'nextduedate', 'expirydate'])->keyBy('id')
            : collect();
        $hostings = $hostingIds !== []
            ? Capsule::table('tblhosting')->whereIn('id', array_keys($hostingIds))->get(['id', 'domain', 'nextduedate'])->keyBy('id')
            : collect();

        $today = date('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $relid = (int) ($r->relid ?? 0);
            $type = (string) ($r->type ?? '');
            $st = ThirdPartyStore::serviceType($type);

            $linked = null;
            $nextDue = null;
            $expiry = null;
            if ($relid > 0) {
                if ($st === 'domain' && isset($domains[$relid])) {
                    $linked = (string) $domains[$relid]->domain;
                    $nextDue = (string) $domains[$relid]->nextduedate;
                    $expiry = (string) ($domains[$relid]->expirydate ?? '');
                } elseif ($st === 'hosting' && isset($hostings[$relid])) {
                    $linked = (string) $hostings[$relid]->domain;
                    $nextDue = (string) $hostings[$relid]->nextduedate;
                }
            }
            $nextDue = ($nextDue && $nextDue !== '0000-00-00') ? $nextDue : null;
            $expiry = ($expiry && $expiry !== '0000-00-00') ? $expiry : null;

            $out[] = [
                'item_id' => (int) $r->id,
                'type' => $type,
                'service_type' => $st,
                'relid' => $relid,
                'description' => (string) ($r->description ?? ''),
                'linked' => $linked,
                'next_due' => $nextDue,
                'expiry' => $expiry,
                'active' => $relid > 0,
                'renewable' => $relid > 0 && $st !== null,
                'already_renewed' => $nextDue !== null && $nextDue > $today,
            ];
        }

        return $out;
    }

    /** Lines WHMCS would act on at mark-paid (relid > 0). @param array<int,array<string,mixed>> $items */
    public static function activeCount(array $items): int
    {
        return count(array_filter($items, static fn ($i) => $i['active']));
    }

    /** Active lines whose linked item is ALREADY renewed (next due in the future). */
    public static function alreadyRenewedCount(array $items): int
    {
        return count(array_filter($items, static fn ($i) => $i['active'] && $i['already_renewed']));
    }

    /**
     * Cheap page-wide signal for the invoice list: how many lines each invoice
     * would (re)renew at mark-paid (relid > 0), in ONE query. The richer
     * "already renewed?" detection needs the per-line domain/hosting date join —
     * that stays in items() / the relidCheck manager (one invoice at a time).
     *
     * @param  int[]  $invoiceIds
     * @return array<int,int> invoiceId => active-relid line count (only > 0 entries)
     */
    public static function activeCountsForInvoices(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $invoiceIds),
            static fn ($i) => $i > 0
        )));
        if ($ids === []) {
            return [];
        }

        $rows = Capsule::table('tblinvoiceitems')
            ->whereIn('invoiceid', $ids)
            ->where('relid', '>', 0)
            ->groupBy('invoiceid')
            ->selectRaw('invoiceid, COUNT(*) as c')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->invoiceid] = (int) $r->c;
        }

        return $out;
    }

    /**
     * Best-effort re-resolution of the relid for a line whose relid was zeroed
     * (by us, by mistake, or by WHMCS after processing): pull the domain-like
     * token from the line description and match it to EXACTLY ONE of the
     * client's domains/services. Returns the candidate only when the match is
     * UNAMBIGUOUS (exactly one row) — callers MUST skip null (0 or >1 matches)
     * so a wrong relid is never restored (a wrong relid = WHMCS renews the wrong
     * thing at Mark Paid). Heuristic by design; the UI previews the target and
     * the operator confirms, and the restore handler re-resolves server-side.
     *
     * @param  array{type?:string, service_type?:?string, description?:string}  $item
     * @return array{relid:int, service_type:string, label:string}|null
     */
    public static function restoreCandidate(int $userId, array $item): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $st = $item['service_type'] ?? ThirdPartyStore::serviceType((string) ($item['type'] ?? ''));
        if ($st !== 'domain' && $st !== 'hosting') {
            return null;
        }
        // A domain-like token (foo.example.gr) from the line description.
        if (! preg_match('/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)+)/i', (string) ($item['description'] ?? ''), $m)) {
            return null;
        }
        $domain = strtolower($m[1]);

        $table = $st === 'domain' ? 'tbldomains' : 'tblhosting';
        $matches = Capsule::table($table)
            ->where('userid', $userId)
            ->whereRaw('LOWER(domain) = ?', [$domain])
            ->limit(2)
            ->get(['id', 'domain']);

        if ($matches->count() !== 1) {
            return null;   // none or ambiguous → operator handles manually
        }
        $row = $matches->first();

        return ['relid' => (int) $row->id, 'service_type' => $st, 'label' => (string) $row->domain];
    }
}
