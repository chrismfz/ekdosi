<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Portability\CsvEntityExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Portability Phase 3 — selective per-entity CSV export: tenant-scoped CSVs,
 * unknown entities skipped, and the command zips them.
 */
class CsvEntityExporterTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug, 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_exports_entity_to_csv_with_header_and_row(): void
    {
        $c = $this->company('csv1');
        Customer::create(['company_id' => $c->id, 'name' => 'Πελάτης Α', 'afm' => '123456789']);

        $files = app(CsvEntityExporter::class)->export($c, ['customers']);

        $this->assertArrayHasKey('customers.csv', $files);
        $csv = $files['customers.csv'];
        $this->assertStringContainsString('name', $csv);          // header column
        $this->assertStringContainsString('Πελάτης Α', $csv);     // the row
        $this->assertStringContainsString('123456789', $csv);
    }

    public function test_export_is_tenant_scoped(): void
    {
        $a = $this->company('csvA');
        $b = $this->company('csvB');
        Customer::create(['company_id' => $a->id, 'name' => 'ΜΟΝΟ Α', 'afm' => '111']);
        Customer::create(['company_id' => $b->id, 'name' => 'ΜΟΝΟ Β', 'afm' => '222']);

        $csv = app(CsvEntityExporter::class)->export($a, ['customers'])['customers.csv'];

        $this->assertStringContainsString('ΜΟΝΟ Α', $csv);
        $this->assertStringNotContainsString('ΜΟΝΟ Β', $csv);   // other tenant excluded
    }

    public function test_neutralises_csv_formula_injection(): void
    {
        $c = $this->company('csvinj');
        Customer::create(['company_id' => $c->id, 'name' => '=HYPERLINK("http://evil")', 'afm' => '444']);

        $csv = app(CsvEntityExporter::class)->export($c, ['customers'])['customers.csv'];

        // The dangerous lead «=» is prefixed with a quote → Excel treats it as text.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_unknown_entity_is_skipped(): void
    {
        $c = $this->company('csv2');

        $files = app(CsvEntityExporter::class)->export($c, ['customers', 'nonsense_table']);

        $this->assertArrayHasKey('customers.csv', $files);
        $this->assertArrayNotHasKey('nonsense_table.csv', $files);
    }

    public function test_command_writes_zip_with_csvs(): void
    {
        $this->company('csvcmd');
        Customer::create(['company_id' => Company::where('slug', 'csvcmd')->value('id'), 'name' => 'Ζήτα', 'afm' => '333']);
        $out = sys_get_temp_dir().'/ekdosi-csv-'.uniqid().'.zip';

        $this->artisan('company:export-csv', ['--tenant' => 'csvcmd', '--only' => 'customers', '--out' => $out])
            ->assertSuccessful();

        $this->assertFileExists($out);
        $zip = new ZipArchive;
        $zip->open($out);
        $this->assertNotFalse($zip->locateName('customers.csv'));
        $this->assertStringContainsString('Ζήτα', (string) $zip->getFromName('customers.csv'));
        $zip->close();
        @unlink($out);
    }

    public function test_command_lists_available_entities(): void
    {
        $this->artisan('company:export-csv', ['--list' => true])
            ->expectsOutputToContain('customers')
            ->assertSuccessful();
    }
}
