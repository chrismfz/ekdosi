<?php

namespace App\Services\Pos;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;

/**
 * Find the till receipt a customer brings back — by whatever the cashier has: the
 * barcode printed on the receipt (Invoice::receiptCode — ΜΑΡΚ or «R<id>»), the ΜΑΡΚ
 * typed by hand, the receipt number («ΑΛΠ36»), or the QR's URL (it carries the ΜΑΡΚ).
 * Only this company's receipts of the TILL's series (pos_invoice_type_id) are
 * returned — the till returns its own sales; anything else is done from «Παραστατικά».
 */
class ReceiptLookup
{
    public function find(Company $company, string $code): ?Invoice
    {
        $code = trim($code);
        if ($code === '' || $company->pos_invoice_type_id === null) {
            return null;
        }

        $query = fn () => Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('invoice_type_id', $company->pos_invoice_type_id)
            ->whereNull('credited_invoice_id');

        if (preg_match('/^R(\d+)$/i', $code, $m) === 1) {
            return $query()->whereKey((int) $m[1])->first();
        }
        // A ΜΑΡΚ typed or scanned — or found inside a scanned QR URL.
        // (a QR URL's own «mark=» parameter first — other digit runs may precede it).
        if (preg_match('/[?&]mark=(\d{15})(?!\d)/i', $code, $m) === 1
            || preg_match('/(?<!\d)(\d{15})(?!\d)/', $code, $m) === 1) {
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
