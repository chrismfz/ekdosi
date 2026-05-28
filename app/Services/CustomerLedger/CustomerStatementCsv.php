<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * Builds a CSV export of a customer's Καρτέλα κινήσεων. Dependency-free
 * (no maatwebsite/excel) — the movements set is small (bounded per
 * customer) so a streamed string is plenty.
 *
 * Excel-on-Greek-Windows friendly: UTF-8 BOM + semicolon delimiter so
 * the file opens with correct columns and Greek text in a default el-GR
 * Excel locale.
 */
class CustomerStatementCsv
{
    /**
     * @param  array{year?: ?int, invoice_type_id?: ?int, paid_status?: ?string}  $filters
     */
    public function build(Customer $customer, array $filters = []): string
    {
        $result = app(CustomerLedgerBuilder::class)->build($customer, $filters);

        $fmt = fn ($v): string => number_format((float) ($v ?? 0), 2, '.', '');

        $rows = [];
        $rows[] = ['Ημερομηνία', 'Τύπος', 'Αναφορά', 'Χρέωση', 'Πίστωση', 'Υπόλοιπο', 'myDATA'];

        foreach ($result->ledger as $row) {
            $rows[] = [
                Carbon::parse($row['date'])->format('Y-m-d'),
                $row['type'] === 'invoice' ? ($row['invoice_type_code'] ?? 'Τιμολόγιο') : 'Πληρωμή',
                (string) $row['reference'],
                $row['debit'] > 0 ? $fmt($row['debit']) : '',
                $row['credit'] > 0 ? $fmt($row['credit']) : '',
                $fmt($row['running_balance']),
                (string) ($row['mydata_state'] ?? ''),
            ];
        }

        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel.
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function filename(Customer $customer): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '', \Illuminate\Support\Str::ascii((string) $customer->name)) ?: 'customer';

        return 'kartela-'.$slug.'-'.now()->format('Ymd').'.csv';
    }
}
