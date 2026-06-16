<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\LedgerBookExporter;
use App\Services\Accounting\LedgerBookResult;
use App\Services\Accounting\LedgerRow;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Exports of the Βιβλίο Εσόδων-Εξόδων. Pure unit — builds a LedgerBookResult
 * straight from LedgerRow objects (no DB) and checks each writer renders the
 * same table + totals.
 */
class LedgerBookExporterTest extends TestCase
{
    private function buildResult(): LedgerBookResult
    {
        $rows = [
            new LedgerRow(
                book: 'income',
                date: Carbon::parse('2026-01-10'),
                docType: 'TPY',
                doc: 'TPY100',
                counterparty: 'Πελάτης ΑΕ',
                afm: '123456789',
                categoryCode: 'category1_3',
                categoryLabel: 'Παροχή Υπηρεσιών',
                net: 100.0,
                vat: 24.0,
                gross: 124.0,
                isCredit: false,
                mydataState: 'VALID',
                mark: '400001',
                recordId: 1,
                accountCode: '73',
                accountName: 'Πωλήσεις υπηρεσιών',
            ),
            new LedgerRow(
                book: 'expense',
                date: Carbon::parse('2026-01-12'),
                docType: '1.1',
                doc: 'A 55',
                counterparty: 'Προμηθευτής ΑΕ',
                afm: '987654321',
                categoryCode: 'category2_3',
                categoryLabel: 'Λήψη Υπηρεσιών',
                net: 50.0,
                vat: 12.0,
                gross: 62.0,
                isCredit: false,
                mydataState: 'VALID',
                mark: '400002',
                recordId: 2,
                accountCode: '61',
                accountName: 'Αμοιβές & έξοδα τρίτων',
            ),
        ];

        return new LedgerBookResult($rows, '01/01/2026 – 31/01/2026');
    }

    public function test_csv_has_bom_headers_rows_and_totals(): void
    {
        $csv = (new LedgerBookExporter)->csv($this->buildResult());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM for Excel');
        $this->assertStringContainsString('Παραστατικό;', $csv);
        $this->assertStringContainsString('Λογαριασμός;', $csv);
        $this->assertStringContainsString('TPY100', $csv);
        $this->assertStringContainsString('Παροχή Υπηρεσιών', $csv);
        // Comma decimal, ';' delimited.
        $this->assertStringContainsString('100,00', $csv);
        $this->assertStringContainsString('Σύνολα', $csv);
        $this->assertStringContainsString('Καθαρό αποτέλεσμα', $csv);
        $this->assertStringContainsString('ΦΠΑ εκροών − εισροών', $csv);

        // Column-count guard: header + a data row must parse to 16 fields, so a
        // future column shift can't silently mis-align the income/expense split.
        // We PARSE each line (fputcsv quotes multibyte labels) after the BOM.
        $body = str_replace("\xEF\xBB\xBF", '', $csv);
        $rows = array_map(
            fn ($l) => str_getcsv($l, ';', '"', '\\'),
            array_filter(explode("\n", trim($body)), fn ($l) => $l !== ''),
        );
        $first = fn (string $v) => collect($rows)->first(fn ($r) => ($r[0] ?? null) === $v);

        $this->assertCount(16, $first('Ημ/νία'), 'header has 16 columns');

        // The income row (TPY100) foots on the Έσοδα side; the Έξοδα side is blank.
        $dataRow = $first('2026-01-10');
        $this->assertCount(16, $dataRow, 'data row has 16 columns');
        $this->assertSame('400001', $dataRow[3], 'ΜΑΡΚ lands in column 4 (idx 3)');
        $this->assertSame('73', $dataRow[10], 'account lands in column 11 (idx 10)');
        $this->assertSame('100,00', $dataRow[12], 'income net lands under Έσοδα — Καθαρό (idx 12)');
        $this->assertSame('', $dataRow[14], 'the Έξοδα — Καθαρό cell is blank for an income row');

        // The «Σύνολα» footing row puts income/expense subtotals under their columns.
        $totals = $first('Σύνολα');
        $this->assertCount(16, $totals, 'totals row has 16 columns');
        $this->assertSame('100,00', $totals[12], 'income net total under Έσοδα — Καθαρό (idx 12)');
        $this->assertSame('50,00', $totals[14], 'expense net total under Έξοδα — Καθαρό (idx 14)');
    }

    public function test_pdf_renders_landscape_bytes(): void
    {
        $pdf = (new LedgerBookExporter)->pdf($this->buildResult(), null);

        $this->assertStringStartsWith('%PDF', $pdf, 'valid PDF stream');
        $this->assertGreaterThan(1000, strlen($pdf), 'non-trivial PDF');
    }

    public function test_pdf_renders_company_header_and_credit_row(): void
    {
        // Exercise the two branches the shared buildResult() doesn't: a non-null
        // Company header and a credit (πιστωτικό) row. Company is unsaved — the
        // view only reads name/afm/tax_office (no DB needed).
        $company = new \App\Models\Company(['name' => 'Δοκιμή ΑΕ', 'afm' => '123456789', 'tax_office' => 'ΦΑΕ ΑΘΗΝΩΝ']);
        $rows = [
            new LedgerRow(
                book: 'income', date: Carbon::parse('2026-01-10'), docType: 'ΤΠΥ', doc: 'ΤΠΥ1',
                counterparty: 'Πελάτης', afm: '1', categoryCode: 'category1_3', categoryLabel: 'Υπηρεσίες',
                net: 100.0, vat: 24.0, gross: 124.0, isCredit: false, mydataState: 'VALID', mark: '400001',
            ),
            new LedgerRow(
                book: 'income', date: Carbon::parse('2026-01-12'), docType: 'ΠΙΣ', doc: 'ΠΙΣ1',
                counterparty: 'Πελάτης', afm: '1', categoryCode: 'category1_3', categoryLabel: 'Υπηρεσίες',
                net: -50.0, vat: -12.0, gross: -62.0, isCredit: true, mydataState: 'VALID', mark: '400002',
            ),
        ];

        $pdf = (new LedgerBookExporter)->pdf(new LedgerBookResult($rows, '01/01/2026 – 31/01/2026'), $company);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_json_structure_and_totals(): void
    {
        $json = (new LedgerBookExporter)->json($this->buildResult());
        $data = json_decode($json, true);

        $this->assertSame('01/01/2026 – 31/01/2026', $data['period']);
        $this->assertCount(2, $data['rows']);
        $this->assertSame('TPY100', $data['rows'][0]['doc']);
        $this->assertEquals(100.0, $data['totals']['incomeNet']);
        $this->assertEquals(50.0, $data['totals']['expenseNet']);
        $this->assertEquals(12.0, $data['totals']['vatBalance']); // 24 − 12
    }

    public function test_csv_neutralises_formula_injection_in_free_text(): void
    {
        $rows = [
            new LedgerRow(
                book: 'income',
                date: Carbon::parse('2026-01-10'),
                docType: 'TPY',
                doc: 'TPY1',
                counterparty: '=HYPERLINK("http://evil")',  // would run as a formula in Excel
                afm: '123456789',
                categoryCode: 'category1_3',
                categoryLabel: 'Παροχή Υπηρεσιών',
                net: 10.0,
                vat: 2.4,
                gross: 12.4,
                isCredit: false,
                mydataState: 'VALID',
                mark: null,
                recordId: 1,
            ),
        ];
        $csv = (new LedgerBookExporter)->csv(new LedgerBookResult($rows, 'x'));

        $this->assertStringContainsString("'=HYPERLINK", $csv, 'leading = must be quote-prefixed');
        $this->assertStringNotContainsString(';=HYPERLINK', $csv, 'must not appear unprefixed in a cell');
    }

    public function test_xlsx_file_is_a_real_workbook(): void
    {
        $path = (new LedgerBookExporter)->xlsxFile($this->buildResult());

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        // .xlsx is a zip — magic bytes "PK".
        $this->assertSame('PK', file_get_contents($path, false, null, 0, 2));

        @unlink($path);
    }
}
