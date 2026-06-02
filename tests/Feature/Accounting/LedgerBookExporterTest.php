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
            ),
        ];

        return new LedgerBookResult($rows, '01/01/2026 – 31/01/2026');
    }

    public function test_csv_has_bom_headers_rows_and_totals(): void
    {
        $csv = (new LedgerBookExporter)->csv($this->buildResult());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM for Excel');
        $this->assertStringContainsString('Παραστατικό;', $csv);
        $this->assertStringContainsString('TPY100', $csv);
        $this->assertStringContainsString('Παροχή Υπηρεσιών', $csv);
        // Comma decimal, ';' delimited.
        $this->assertStringContainsString('100,00', $csv);
        $this->assertStringContainsString('Σύνολο εσόδων', $csv);
        $this->assertStringContainsString('ΦΠΑ εκροών − εισροών', $csv);
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
