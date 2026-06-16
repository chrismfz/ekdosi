<?php

namespace App\Services\Accounting;

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
            'Κωδ. κατηγορίας', 'Κατηγορία', 'Λογαριασμός', 'Πιστωτικό', 'Καθαρό', 'ΦΠΑ', 'Σύνολο',
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

        fputcsv($handle, $this->headers(), ';');

        $safe = fn ($v): string => $this->csvSafe((string) $v);

        foreach ($this->records($result) as $rec) {
            fputcsv($handle, [
                $rec['date'], $rec['book'], $safe($rec['doc']), $safe($rec['mark']), $safe($rec['mydataState']),
                $safe($rec['docType']), $safe($rec['counterparty']),
                $safe($rec['afm']), $safe($rec['categoryCode']), $safe($rec['categoryLabel']), $safe($rec['account']), $rec['isCredit'],
                $fmt($rec['net']), $fmt($rec['vat']), $fmt($rec['gross']),
            ], ';');
        }

        // Totals trailer — amounts under Καθαρό/ΦΠΑ/Σύνολο (the last 3 of 15 cols).
        $pad = fn (string $label, array $tail): array => array_merge(array_pad([$label], 12, ''), $tail);
        fputcsv($handle, [], ';');
        fputcsv($handle, $pad('Σύνολο εσόδων', [$fmt($result->incomeNet()), $fmt($result->incomeVat()), $fmt($result->incomeGross())]), ';');
        fputcsv($handle, $pad('Σύνολο εξόδων', [$fmt($result->expenseNet()), $fmt($result->expenseVat()), $fmt($result->expenseGross())]), ';');
        fputcsv($handle, array_merge(array_pad(['ΦΠΑ εκροών − εισροών'], 14, ''), [$fmt($result->vatBalance())]), ';');

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
                $rec['net'], $rec['vat'], $rec['gross'],
            ]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues($this->pad(['Σύνολο εσόδων'], 12, [$result->incomeNet(), $result->incomeVat(), $result->incomeGross()]), $boldStyle));
        $writer->addRow(Row::fromValues($this->pad(['Σύνολο εξόδων'], 12, [$result->expenseNet(), $result->expenseVat(), $result->expenseGross()]), $boldStyle));
        $writer->addRow(Row::fromValues($this->pad(['ΦΠΑ εκροών − εισροών'], 14, [$result->vatBalance()]), $boldStyle));

        $writer->close();

        return $path;
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
