<?php

namespace App\Support\Whmcs;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Per-tenant cache of the WHMCS payment-sync WORKLIST — the ids of invoices
 * the operator can act on, computed by the scheduled/manual reconciler so the
 * dashboard widget + console page read a cheap cache instead of hammering the
 * WHMCS API on every render (same idiom as VatPictureCache).
 *
 * Phase 1 stores the INBOUND list only (open επί-πιστώσει invoices that WHMCS
 * now reports Paid → «κλείσε την οφειλή»). The shape leaves room for the
 * OUTBOUND list (settled locally, still Unpaid at WHMCS) that Phase 2 adds.
 *
 * The cache holds only invoice IDS + the compute timestamp — never money
 * figures. The UI re-reads the live balance and re-checks WHMCS at action
 * time, so a stale cache can never drive a wrong payment (worst case: a row
 * shows that no longer qualifies, and the action simply records nothing).
 */
class WhmcsPaymentSyncCache
{
    private const TTL_SECONDS = 24 * 60 * 60;

    public static function key(int|string $companyId): string
    {
        return "whmcs-payment-sync:{$companyId}";
    }

    /**
     * @param  array<int, int>  $inboundInvoiceIds
     * @param  array<int, int>  $outboundInvoiceIds
     */
    public static function put(Company $company, array $inboundInvoiceIds, array $outboundInvoiceIds = []): void
    {
        Cache::put(self::key($company->getKey()), [
            'inbound' => array_values(array_unique(array_map('intval', $inboundInvoiceIds))),
            'outbound' => array_values(array_unique(array_map('intval', $outboundInvoiceIds))),
            'at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);
    }

    /** @return array{inbound: int[], outbound: int[], at: ?string} */
    public static function get(Company $company): array
    {
        $data = Cache::get(self::key($company->getKey()));
        if (! is_array($data)) {
            return ['inbound' => [], 'outbound' => [], 'at' => null];
        }

        return [
            'inbound' => array_map('intval', $data['inbound'] ?? []),
            'outbound' => array_map('intval', $data['outbound'] ?? []),
            'at' => $data['at'] ?? null,
        ];
    }

    /** @return int[] */
    public static function inboundIds(Company $company): array
    {
        return self::get($company)['inbound'];
    }

    /** @return int[] */
    public static function outboundIds(Company $company): array
    {
        return self::get($company)['outbound'];
    }

    public static function computedAt(Company $company): ?Carbon
    {
        $at = self::get($company)['at'];

        return $at ? Carbon::parse($at) : null;
    }
}
