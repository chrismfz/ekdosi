<?php

namespace App\Services\Accounting;

use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exports a LedgerBookResult to the formats an accountant wants: CSV and JSON
 * (dependency-free) and a styled .xlsx (via openspout, already a dependency —
 * no PhpSpreadsheet / maatwebsite needed). All three render the SAME normalised
 * table (one row per document) + a totals trailer, so the file matches the
 * on-screen book exactly.
 *
 * CSV targets el-GR Excel (UTF-8 BOM + ';' delimiter + comma decimal) so it
 * opens with correct columns and Greek text; the .xlsx writes raw numbers so
 * Excel treats amounts as numbers (sortable, summable) with a bold header.
 */
class LedgerBookExporter
{
    /** @return list<string> */
    public function headers(): array
    {
        return [
            'Ημ/νία', 'Βιβλίο', 'Παραστατικό', 'ΜΑΡΚ', 'Κατάσταση myDATA', 'Είδος', 'Αντισυμβαλλόμενος', 'ΑΦΜ',
            'Κωδ. κατηγορίας', 'Κατηγορία', 'Λογαριασμός', 'Πιστωτικό',
            'Έσοδα — Καθαρό', 'Έσοδα — ΦΠΑ', 'Έξοδα — Καθαρό', 'Έξοδα — ΦΠΑ',
        ];
    }

    /**
     * One associative record per LedgerRow, raw (un-formatted) values.
     *
     * @return list<array<string, mixed>>
     */
    public function records(LedgerBookResult $result): array
    {
        $out = [];
        foreach ($result->rows as $row) {
            $out[] = [
                'date' => $row->date->format('Y-m-d'),
                'book' => $row->book === 'income' ? 'Έσοδο' : 'Έξοδο',
                'isIncome' => $row->book === 'income',
                'doc' => $row->doc,
                'mark' => $row->mark ?? '',
                'mydataState' => $row->mydataState ?? '',
                'docType' => $row->docType,
                'counterparty' => $row->counterparty ?? '',
                'afm' => $row->afm ?? '',
                'categoryCode' => $row->categoryCode ?? '',
                'categoryLabel' => $row->categoryLabel ?? '',
                'account' => $row->accountCode ?? '',
                'accountName' => $row->accountName ?? '',
                'isCredit' => $row->isCredit ? 'Ναι' : '',
                'net' => $row->net,
                'vat' => $row->vat,
                'gross' => $row->gross,
            ];
        }

        return $out;
    }

    public function csv(LedgerBookResult $result): string
    {
        // Comma decimal, NO thousands separator: parses as a number in an el-GR
        // Excel locale (paired with the ';' delimiter + BOM below).
        $fmt = fn ($v): string => number_format((float) $v, 2, ',', '');

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        fputcsv($handle, $this->headers(), ';', escape: '');

        $safe = fn ($v): string => $this->csvSafe((string) $v);

        foreach ($this->records($result) as $rec) {
            fputcsv($handle, [
                $rec['date'], $rec['book'], $safe($rec['doc']), $safe($rec['mark']), $safe($rec['mydataState']),
                $safe($rec['docType']), $safe($rec['counterparty']),
                $safe($rec['afm']), $safe($rec['categoryCode']), $safe($rec['categoryLabel']), $safe($rec['account']), $rec['isCredit'],
                // Each row foots on ITS side (Έσοδα / Έξοδα); the other side blank.
                $rec['isIncome'] ? $fmt($rec['net']) : '', $rec['isIncome'] ? $fmt($rec['vat']) : '',
                $rec['isIncome'] ? '' : $fmt($rec['net']), $rec['isIncome'] ? '' : $fmt($rec['vat']),
            ], ';', escape: '');
        }

        // Totals trailer — the 4 money columns are the last 4 of 16; foot each side.
        fputcsv($handle, [], ';', escape: '');
        fputcsv($handle, array_merge(array_pad(['Σύνολα'], 12, ''), [
            $fmt($result->incomeNet()), $fmt($result->incomeVat()), $fmt($result->expenseNet()), $fmt($result->expenseVat()),
        ]), ';', escape: '');
        fputcsv($handle, ['Καθαρό αποτέλεσμα (έσοδα − έξοδα)', $fmt($result->incomeNet() - $result->expenseNet())], ';', escape: '');
        fputcsv($handle, ['ΦΠΑ εκροών − εισροών', $fmt($result->vatBalance())], ';', escape: '');

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function json(LedgerBookResult $result): string
    {
        return json_encode([
            'period' => $result->periodLabel,
            'rows' => $this->records($result),
            'totals' => [
                'incomeNet' => $result->incomeNet(),
                'incomeVat' => $result->incomeVat(),
                'incomeGross' => $result->incomeGross(),
                'incomeCount' => $result->incomeCount(),
                'expenseNet' => $result->expenseNet(),
                'expenseVat' => $result->expenseVat(),
                'expenseGross' => $result->expenseGross(),
                'expenseCount' => $result->expenseCount(),
                'vatBalance' => $result->vatBalance(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Writes a styled .xlsx to a temp file and returns its path (caller streams
     * + deletes it). Raw numeric amounts so Excel can sum/sort them.
     */
    public function xlsxFile(LedgerBookResult $result): string
    {
        // tempnam() creates a real (empty) stub file; openspout writes to the
        // ".xlsx"-suffixed sibling path. Drop the stub now so it isn't orphaned
        // in /tmp (the caller only ever sees/deletes the .xlsx).
        $base = tempnam(sys_get_temp_dir(), 'ledger');
        $path = $base.'.xlsx';
        @unlink($base);

        $writer = new XlsxWriter();
        $writer->openToFile($path);

        $headerStyle = (new Style())->setFontBold()->setBackgroundColor('E5E7EB');
        $boldStyle = (new Style())->setFontBold();

        $writer->addRow(Row::fromValues($this->headers(), $headerStyle));

        foreach ($this->records($result) as $rec) {
            $writer->addRow(Row::fromValues([
                $rec['date'], $rec['book'], $rec['doc'], $rec['mark'], $rec['mydataState'],
                $rec['docType'], $rec['counterparty'],
                $rec['afm'], $rec['categoryCode'], $rec['categoryLabel'], $rec['account'], $rec['isCredit'],
                // Raw numeric on the row's side; '' on the other (Excel sums each column).
                $rec['isIncome'] ? $rec['net'] : '', $rec['isIncome'] ? $rec['vat'] : '',
                $rec['isIncome'] ? '' : $rec['net'], $rec['isIncome'] ? '' : $rec['vat'],
            ]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues($this->pad(['Σύνολα'], 12, [
            $result->incomeNet(), $result->incomeVat(), $result->expenseNet(), $result->expenseVat(),
        ]), $boldStyle));
        $writer->addRow(Row::fromValues(['Καθαρό αποτέλεσμα (έσοδα − έξοδα)', $result->incomeNet() - $result->expenseNet()], $boldStyle));
        $writer->addRow(Row::fromValues(['ΦΠΑ εκροών − εισροών', $result->vatBalance()], $boldStyle));

        $writer->close();

        return $path;
    }

    /**
     * Render the book to a LANDSCAPE A4 PDF (so all the Έσοδα/Έξοδα columns fit —
     * the "full sheet, sideways" view the accountant reads). Self-contained inline
     * CSS + DejaVu Sans (Greek), like the other PDFs; returns the raw bytes.
     */
    public function pdf(LedgerBookResult $result, ?Company $company): string
    {
        $originalMemory = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');
        $restoreTimeLimit = (int) ini_get('max_execution_time');
        @set_time_limit(60);

        try {
            return Pdf::loadView('accounting.ledger-book-pdf', [
                'result' => $result,
                'company' => $company,
                'generatedAt' => now(),
            ])
                ->setPaper('a4', 'landscape')
                ->output();
        } finally {
            @ini_set('memory_limit', $originalMemory);
            @set_time_limit($restoreTimeLimit);
        }
    }

    public function filename(string $ext, ?string $from = null, ?string $to = null): string
    {
        $period = ($from && $to) ? $from.'_'.$to : now()->format('Y-m-d');

        return 'vivlio-esodon-exodon-'.$period.'.'.$ext;
    }

    /**
     * Neutralise CSV/Excel formula injection: a free-text field (a counterparty
     * name from operators/GSIS) that starts with = + - @ would execute as a
     * formula when the CSV is opened in Excel. Prefix a single quote so Excel
     * treats it as literal text. (Not needed for the .xlsx — openspout writes
     * inline strings, which Excel never evaluates.)
     */
    private function csvSafe(string $v): string
    {
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
            return "'".$v;
        }

        return $v;
    }

    /**
     * Left-pad a label up to $padTo columns, then append trailing values — so
     * the amounts land under the Καθαρό/ΦΠΑ/Σύνολο columns.
     *
     * @param  list<string>  $head
     * @param  list<float>  $tail
     * @return list<mixed>
     */
    private function pad(array $head, int $padTo, array $tail): array
    {
        $row = array_pad($head, $padTo, '');

        return array_merge($row, $tail);
    }
}
