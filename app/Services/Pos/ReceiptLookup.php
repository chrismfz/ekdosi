<?php

namespace App\Services\Pos;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;

/**
 * Find the till receipt a customer brings back — by whatever the cashier has: the
 * barcode printed on the receipt (Invoice::receiptCode — ΜΑΡΚ or «R<id>»), the ΜΑΡΚ
 * typed by hand, the receipt number («ΑΛΠ36»), or the QR's URL (it carries the ΜΑΡΚ).
 * Only this company's issued retail sales (11.x, not a credit note) are returned.
 */
class ReceiptLookup
{
    public function find(Company $company, string $code): ?Invoice
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $query = fn () => Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereNull('credited_invoice_id')
            ->whereHas('invoiceType', fn ($q) => $q->where('mydata_type', 'like', '11.%')->where('is_credit', false));

        if (preg_match('/^R(\d+)$/i', $code, $m) === 1) {
            return $query()->whereKey((int) $m[1])->first();
        }
        // A ΜΑΡΚ typed or scanned — or found inside a scanned QR URL.
        if (preg_match('/(?<!\d)(\d{15})(?!\d)/', $code, $m) === 1) {
            $byMark = $query()->where('mydata_mark', $m[1])->first();
            if ($byMark !== null) {
                return $byMark;
            }
        }

        // The printed receipt number («ΑΛΠ36»), case/space-insensitive.
        $normalized = mb_strtoupper(preg_replace('/\s+/u', '', $code));

        return $query()->whereRaw('UPPER(REPLACE(invcode, \' \', \'\')) = ?', [$normalized])->first();
    }
}
