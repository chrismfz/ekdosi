<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\InvoicePdfRenderer;
use App\Services\Portability\DocumentPdfArchive;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * MYD-025 — the handover archive for a tenant that is LEAVING.
 *
 * The export bundle only means something to another ekdosi. A company that has
 * moved to another vendor, or been wound up, needs its παραστατικά in a form a
 * human and an accountant can open once it no longer has this system — and that
 * is what makes deleting the tenant afterwards a defensible act rather than a
 * silent loss.
 */
class DocumentPdfArchiveTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();

        $this->out = storage_path('app/tmp/pdf-archive-test-'.uniqid().'.zip');
    }

    protected function tearDown(): void
    {
        @unlink($this->out);

        parent::tearDown();
    }

    private function company(): Company
    {
        $c = Company::create([
            'name' => 'Φεύγει ΑΕ', 'slug' => 'leaving-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'address' => 'ΑΔΡΙΑΝΟΥ 16', 'city' => 'ΑΘΗΝΑ', 'postcode' => '14121',
        ]);
        VatCategory::create([
            'company_id' => $c->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);

        return $c;
    }

    private function invoice(Company $c, string $invcode, int $code): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $c->id, 'code' => 'ΤΠΥ'],
            ['name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1'],
        );
        $customer = Customer::firstOrCreate(
            ['company_id' => $c->id, 'afm' => '997073525'],
            ['name' => 'Πελάτης ΑΕ'],
        );

        $invoice = Invoice::create([
            'company_id' => $c->id, 'invcode' => $invcode, 'code' => $code,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $c->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'Υπηρεσία',
        ]);

        return $invoice;
    }

    /** @return array<int, string> entry names inside the zip */
    private function entries(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the archive must be a readable zip');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    public function test_it_packs_every_document_with_an_index_a_reader_can_use(): void
    {
        $c = $this->company();
        $this->invoice($c, 'ΤΠΥ1', 1);
        $this->invoice($c, 'ΤΠΥ2', 2);

        $result = app(DocumentPdfArchive::class)->build($c, $this->out);

        $this->assertSame(2, $result['invoices']);
        $this->assertSame(0, $result['failed']);
        $this->assertGreaterThan(0, $result['bytes']);

        $entries = $this->entries($this->out);
        $year = now()->format('Y');

        $this->assertContains("παραστατικά/{$year}/ΤΠΥ1.pdf", $entries);
        $this->assertContains("παραστατικά/{$year}/ΤΠΥ2.pdf", $entries);
        // The archive has to be usable WITHOUT this application — hence a plain
        // index and a README, not just a pile of files.
        $this->assertContains('index.csv', $entries);
        $this->assertContains('README.txt', $entries);
        $this->assertNotContains('errors.txt', $entries);
    }

    public function test_one_unrenderable_document_does_not_cost_the_operator_the_rest(): void
    {
        // A handover archive that aborts on document 4,001 of 10,000 is useless.
        // Failures are listed, not fatal — and an export that silently DROPPED
        // documents would be worse than one that says which are missing.
        $c = $this->company();
        $good = $this->invoice($c, 'ΤΠΥ1', 1);
        $this->invoice($c, 'ΤΠΥ2', 2);

        $this->app->bind(InvoicePdfRenderer::class, function () use ($good) {
            return new class($good->id) extends InvoicePdfRenderer
            {
                public function __construct(private readonly int $failFor) {}

                public function render(Invoice $invoice): string
                {
                    if ($invoice->getKey() === $this->failFor) {
                        throw new RuntimeException('σκόπιμη αποτυχία');
                    }

                    return '%PDF-1.4 fake';
                }
            };
        });

        $result = app(DocumentPdfArchive::class)->build($c, $this->out);

        $this->assertSame(1, $result['invoices'], 'the other document still made it');
        $this->assertSame(1, $result['failed']);

        $entries = $this->entries($this->out);
        $this->assertContains('errors.txt', $entries);
        $this->assertContains('παραστατικά/'.now()->format('Y').'/ΤΠΥ2.pdf', $entries);
    }

    public function test_it_exports_the_named_company_not_the_ambient_tenant(): void
    {
        // Run from a command, a queued job, or a super_admin acting on a company
        // other than the selected tenant. Reading through CompanyScope would have
        // produced an EMPTY archive and handed the departing tenant nothing.
        $leaving = $this->company();
        $this->invoice($leaving, 'ΤΠΥ1', 1);

        $other = $this->company();

        $result = app(CompanyContext::class)->actAs(
            $other,
            fn (): array => app(DocumentPdfArchive::class)->build($leaving, $this->out),
        );

        $this->assertSame(1, $result['invoices']);
    }

    public function test_an_out_of_order_issue_date_does_not_shift_the_paging_window(): void
    {
        // chunkById pages by `id > lastId`. An orderBy('issued_at') on top of it
        // means the LAST ROW OF A PAGE can have a low id, and everything between
        // that id and the true high-water mark is then skipped — absent from the
        // zip AND from errors.txt, the one outcome this class exists to prevent.
        //
        // The shape that actually reproduces it: the row with the LOWEST id sorts
        // LAST by date, so page 1 ends on a low id and page 2's `id >` window
        // jumps straight past it. (Backdating a row instead sorts it FIRST, which
        // is harmless — a test built that way passes with or without the fix.)
        $c = $this->company();

        $first = $this->invoice($c, 'ΤΠΥ1', 1);
        for ($i = 2; $i <= 120; $i++) {
            $this->invoice($c, 'ΤΠΥ'.$i, $i);
        }
        $first->forceFill(['issued_at' => now()->addYear()])->save();

        $result = app(DocumentPdfArchive::class)->build($c, $this->out);

        $this->assertSame(120, $result['invoices'], 'every document must be in the archive');
        $this->assertSame(0, $result['failed'], 'and a dropped document is not even an error');

        $entries = $this->entries($this->out);
        $this->assertContains('παραστατικά/'.now()->addYear()->format('Y').'/ΤΠΥ1.pdf', $entries);
    }

    public function test_the_index_neutralises_a_formula_in_a_customer_name(): void
    {
        // Customer names are operator- and WHMCS-sourced. A cell starting with =
        // is executed by Excel on open, and this archive is built to be opened in
        // Excel by someone outside the organisation.
        $c = $this->company();
        Customer::where('company_id', $c->id)->delete();
        Customer::create([
            'company_id' => $c->id, 'afm' => '997073525',
            'name' => '=HYPERLINK("http://evil","κλικ")',
        ]);
        $this->invoice($c, 'ΤΠΥ1', 1);

        app(DocumentPdfArchive::class)->build($c, $this->out);

        $zip = new ZipArchive;
        $zip->open($this->out);
        $csv = (string) $zip->getFromName('index.csv');
        $zip->close();

        $this->assertStringContainsString("'=HYPERLINK", $csv, 'the formula must be neutralised');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_a_company_with_nothing_still_produces_a_readable_archive(): void
    {
        $result = app(DocumentPdfArchive::class)->build($this->company(), $this->out);

        $this->assertSame(0, $result['invoices']);
        $this->assertContains('index.csv', $this->entries($this->out));
    }
}
