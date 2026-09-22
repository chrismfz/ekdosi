<?php

namespace Tests\Feature\Import;

use App\Enums\SupplierSource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MetricUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\VatCategory;
use App\Services\Import\CsvTable;
use App\Services\Import\CustomerCsvImporter;
use App\Services\Import\PlannedRow;
use App\Services\Import\ProductCsvImporter;
use App\Services\Import\SupplierCsvImporter;
use App\Support\Afm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::factory()->create(['country_code' => 'GR']);
    }

    private function csv(string $content): CsvTable
    {
        return CsvTable::fromString($content);
    }

    /* ───────────── reading the file ───────────── */

    public function test_reads_greek_excel_output_semicolons_bom_and_blank_lines(): void
    {
        $table = $this->csv("\xEF\xBB\xBFΕπωνυμία;ΑΦΜ\nΑλφα ΑΕ;094019245\n\n;\n");

        $this->assertSame(['Επωνυμία', 'ΑΦΜ'], $table->headers);
        $this->assertCount(1, $table->rows);
        $this->assertSame(['Αλφα ΑΕ', '094019245'], $table->rows[0]['cells']);
        $this->assertSame(2, $table->rows[0]['line']);
    }

    public function test_reads_windows_1253_and_the_excel_sep_hint(): void
    {
        $content = iconv('UTF-8', 'Windows-1253', "sep=,\nΕπωνυμία,Πόλη\nΒήτα,Αθήνα\n");

        $table = $this->csv($content);

        $this->assertSame(['Επωνυμία', 'Πόλη'], $table->headers);
        $this->assertSame(['Βήτα', 'Αθήνα'], $table->rows[0]['cells']);
    }

    public function test_an_empty_or_oversized_file_is_refused(): void
    {
        try {
            $this->csv("  \n");
            $this->fail('empty file accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('κενό', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->csv("name\n".str_repeat("x\n", CsvTable::MAX_ROWS + 1));
    }

    public function test_greek_afm_check_digit(): void
    {
        $this->assertTrue(Afm::greekChecksumOk('094019245'));
        $this->assertTrue(Afm::greekChecksumOk('EL 090000045'));
        $this->assertFalse(Afm::greekChecksumOk('123456789'));
        $this->assertNull(Afm::greekChecksumOk('CY10259033P'));   // foreign — not judged
        $this->assertNull(Afm::greekChecksumOk('000000000'));     // placeholder
    }

    /* ───────────── customers ───────────── */

    public function test_creates_customers_recognising_greek_headers_and_country_names(): void
    {
        $plan = (new CustomerCsvImporter)->import($this->tenant, $this->csv(
            "Α.Φ.Μ.;Επωνυμία;Δ.Ο.Υ.;Τ.Κ.;Χώρα;E-mail\n"
            ."EL 094019245;Αλφα ΑΕ;ΦΑΕ ΑΘΗΝΩΝ;10564;Ελλάδα;info@alfa.gr\n"
        ));

        $this->assertSame(1, $plan->count(PlannedRow::CREATE));
        $c = Customer::where('company_id', $this->tenant->id)->sole();
        $this->assertSame('094019245', $c->afm);
        $this->assertSame('ΦΑΕ ΑΘΗΝΩΝ', $c->tax_office);
        $this->assertSame('10564', $c->postcode);
        $this->assertSame('GR', $c->country);
        $this->assertSame('info@alfa.gr', $c->email);
        $this->assertTrue($c->is_active);
    }

    public function test_an_existing_customer_only_gets_its_blank_fields_filled(): void
    {
        $existing = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Αλφα (δικό μας)', 'afm' => '094019245', 'phone1' => '2101111111',
        ]);

        $csv = "ΑΦΜ;Επωνυμία;Τηλέφωνο;Email\n094019245;Άλλο όνομα;2109999999;new@alfa.gr\n";
        $plan = (new CustomerCsvImporter)->import($this->tenant, $this->csv($csv));

        $this->assertSame(1, $plan->count(PlannedRow::FILL));
        $existing->refresh();
        $this->assertSame('Αλφα (δικό μας)', $existing->name, 'a filled field is never overwritten');
        $this->assertSame('2101111111', $existing->phone1);
        $this->assertSame('new@alfa.gr', $existing->email, 'a blank field is filled');

        // Re-running the same file changes nothing.
        $again = (new CustomerCsvImporter)->plan($this->tenant, $this->csv($csv));
        $this->assertSame(1, $again->count(PlannedRow::UNCHANGED));
        $this->assertSame(1, Customer::where('company_id', $this->tenant->id)->count());
    }

    public function test_customer_row_problems_are_reported_per_row(): void
    {
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Στον κάδο', 'afm' => '090000045'])->delete();

        $plan = (new CustomerCsvImporter)->plan($this->tenant, $this->csv(
            "Επωνυμία;ΑΦΜ;Email\n"
            ."Λάθος ΑΦΜ;123456789;\n"          // bad check digit → error
            .";094019245;\n"                    // new customer without a name → error
            ."Στον κάδο;090000045;\n"           // soft-deleted → error
            ."Λιανική;000000000;κακό-email\n"   // placeholder ΑΦΜ + bad email → created with warnings
            ."Λιανική;;\n"                      // same retail name twice → duplicate error
        ));

        $rows = $plan->rows;
        $this->assertSame(PlannedRow::ERROR, $rows[0]->action);
        $this->assertStringContainsString('έλεγχο εγκυρότητας', $rows[0]->errors[0]);
        $this->assertStringContainsString('Λείπει η επωνυμία', $rows[1]->errors[0]);
        $this->assertStringContainsString('κάδο', $rows[2]->errors[0]);
        $this->assertSame(PlannedRow::CREATE, $rows[3]->action);
        $this->assertStringContainsString('Μη έγκυρο email', $rows[3]->warnings[0]);
        $this->assertArrayNotHasKey('afm', $rows[3]->values);
        $this->assertStringContainsString('Διπλή εγγραφή', $rows[4]->errors[0]);
    }

    public function test_a_retail_customer_is_matched_by_name_and_another_tenant_is_never_touched(): void
    {
        $other = Company::factory()->create();
        Customer::create(['company_id' => $other->id, 'name' => 'Ξένος', 'afm' => '094019245']);
        $retail = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Γιάννης Παπαδόπουλος']);

        $plan = (new CustomerCsvImporter)->import($this->tenant, $this->csv(
            "Επωνυμία,ΑΦΜ,Πόλη\nΓιάννης Παπαδόπουλος,,Πάτρα\nΝέος πελάτης,094019245,\n"
        ));

        $this->assertSame('Πάτρα', $retail->fresh()->city);
        $this->assertSame(1, $plan->count(PlannedRow::FILL));
        $this->assertSame(1, $plan->count(PlannedRow::CREATE), 'the other tenant’s ΑΦΜ is not a match here');
        $this->assertSame('Ξένος', Customer::where('company_id', $other->id)->sole()->name);
    }

    public function test_our_own_export_columns_are_recognised_and_ids_ignored(): void
    {
        $plan = (new CustomerCsvImporter)->plan($this->tenant, $this->csv(
            "id,company_id,name,afm,city\n99,77,Round Trip ΑΕ,094019245,Βόλος\n"
        ));

        $this->assertSame(['name' => 'name', 'afm' => 'afm', 'city' => 'city'], $plan->mapping);
        $this->assertSame(['id', 'company_id'], $plan->ignored);
        $this->assertSame(PlannedRow::CREATE, $plan->rows[0]->action);
    }

    /* ───────────── suppliers ───────────── */

    public function test_suppliers_are_keyed_on_the_afm_identity_and_marked_as_imported(): void
    {
        $synced = Supplier::create(['company_id' => $this->tenant->id, 'afm' => '090000045', 'source' => SupplierSource::Sync]);

        $plan = (new SupplierCsvImporter)->import($this->tenant, $this->csv(
            "Επωνυμία;ΑΦΜ;Χώρα\nΠρομηθευτής;EL 090000045;\nAWS EMEA;LU26375245;LU\n"
        ));

        $this->assertSame(1, $plan->count(PlannedRow::FILL));
        $this->assertSame('Προμηθευτής', $synced->fresh()->name, 'the ΑΦΜ-only synced row gets its name');
        $aws = Supplier::where('company_id', $this->tenant->id)->where('afm', 'LU26375245')->sole();
        $this->assertSame(SupplierSource::Import, $aws->source);
        $this->assertSame('LU', $aws->country);
    }

    /* ───────────── products ───────────── */

    private function vat(float $rate, bool $default = false): VatCategory
    {
        return VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => "ΦΠΑ {$rate}%", 'rate' => $rate,
            'is_default' => $default, 'mydata_vat_category' => 1,
        ]);
    }

    public function test_creates_products_resolving_vat_by_rate_and_deriving_prices(): void
    {
        $vat24 = $this->vat(24, true);
        $vat13 = $this->vat(13);

        $plan = (new ProductCsvImporter)->import($this->tenant, $this->csv(
            "Περιγραφή;Κωδικός;Τιμή χωρίς ΦΠΑ;Τιμή με ΦΠΑ;ΦΠΑ %;Κατηγορία;Μονάδα\n"
            ."Φιλοξενία;HOST-1;1.234,50;;24;Υπηρεσίες;Τεμάχια\n"
            ."Βιβλίο;BOOK-1;;11,30;13%;;Τεμάχια\n"
        ));

        $this->assertSame(2, $plan->count(PlannedRow::CREATE));
        $host = Product::where('company_id', $this->tenant->id)->where('sku', 'HOST-1')->sole();
        $this->assertSame($vat24->id, $host->vat_category_id);
        $this->assertSame('1234.50', (string) $host->sell_price);
        $this->assertSame('1530.78', (string) $host->price_wvat);
        $this->assertSame('Υπηρεσίες', ProductCategory::find($host->product_category_id)->description_short);

        $book = Product::where('company_id', $this->tenant->id)->where('sku', 'BOOK-1')->sole();
        $this->assertSame($vat13->id, $book->vat_category_id);
        $this->assertSame('10.00', (string) $book->sell_price, 'net derived from the gross');
        $this->assertSame($host->metric_unit_id, $book->metric_unit_id, 'one unit created, then reused');
        $this->assertSame(1, MetricUnit::where('company_id', $this->tenant->id)->count());
    }

    public function test_product_tax_is_never_guessed(): void
    {
        $this->vat(24, true);

        $plan = (new ProductCsvImporter)->plan($this->tenant, $this->csv(
            "Περιγραφή;ΦΠΑ\nΜε 17%;17\nΧωρίς στήλη ΦΠΑ;\n"
        ));

        $this->assertSame(PlannedRow::ERROR, $plan->rows[0]->action);
        $this->assertStringContainsString('Δεν υπάρχει κατηγορία ΦΠΑ 17%', $plan->rows[0]->errors[0]);
        $this->assertSame(PlannedRow::CREATE, $plan->rows[1]->action);
        $this->assertStringContainsString('προεπιλεγμένη κατηγορία (24%)', $plan->rows[1]->warnings[0]);
    }

    public function test_an_existing_product_matched_by_code_only_fills_blanks(): void
    {
        $vat = $this->vat(24, true);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γενικά', 'markup' => 0]);
        $mine = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Δικό μου όνομα', 'sku' => 'A-1',
            'vat_category_id' => $vat->id, 'product_category_id' => $cat->id, 'sell_price' => 0,
        ]);
        Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Άλλο', 'barcode' => '5200000000001',
            'vat_category_id' => $vat->id, 'product_category_id' => $cat->id,
        ]);

        $plan = (new ProductCsvImporter)->import($this->tenant, $this->csv(
            "Κωδικός;Περιγραφή;Τιμή;Barcode\nA-1;Νέο όνομα;10;5200000000001\n"
        ));

        $mine->refresh();
        $this->assertSame(PlannedRow::FILL, $plan->rows[0]->action);
        $this->assertSame('Δικό μου όνομα', $mine->description_short);
        $this->assertSame('10.00', (string) $mine->sell_price, 'a zero price counts as blank');
        $this->assertSame('12.40', (string) $mine->price_wvat);
        $this->assertNull($mine->barcode, 'a barcode another product holds is not copied over');
        $this->assertStringContainsString('ανήκει σε άλλο προϊόν', $plan->rows[0]->warnings[0]);
    }

    public function test_the_template_lists_the_labels_semicolon_separated_with_a_bom(): void
    {
        $template = (new ProductCsvImporter)->template();

        $this->assertStringStartsWith("\xEF\xBB\xBFΠεριγραφή;Κωδικός;", $template);
        $this->assertStringNotContainsString('vat_category_id', $template);

        // And it imports as-is.
        $this->vat(24, true);
        $plan = (new ProductCsvImporter)->plan($this->tenant, $this->csv($template));
        $this->assertSame(PlannedRow::CREATE, $plan->rows[0]->action);
    }
    /* ───────────── review round 1 ───────────── */

    public function test_an_afm_that_lost_its_leading_zero_in_excel_is_restored(): void
    {
        $existing = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Αλφα', 'afm' => '094019245']);

        $plan = (new CustomerCsvImporter)->import($this->tenant, $this->csv("ΑΦΜ;Πόλη\n94019245;Αθήνα\n"));

        $this->assertSame(PlannedRow::FILL, $plan->rows[0]->action, 'matched, not a twin');
        $this->assertSame('Αθήνα', $existing->fresh()->city);
        $this->assertSame(1, Customer::where('company_id', $this->tenant->id)->count());
    }

    public function test_a_code_claimed_twice_in_the_file_fails_the_later_row_not_the_import(): void
    {
        $this->vat(24, true);

        $plan = (new ProductCsvImporter)->import($this->tenant, $this->csv(
            "Περιγραφή;Κωδικός;Barcode\nΑ;A-1;5200000000001\nΒ;B-1;5200000000001\nΓ;;\n"
        ));

        $this->assertSame(PlannedRow::CREATE, $plan->rows[0]->action);
        $this->assertSame(PlannedRow::ERROR, $plan->rows[1]->action);
        $this->assertStringContainsString('γραμμή 2', $plan->rows[1]->errors[0]);
        $this->assertSame(2, Product::where('company_id', $this->tenant->id)->count(), 'the valid rows were written');
    }

    public function test_an_ambiguous_thousands_value_is_refused(): void
    {
        $this->vat(24, true);

        $plan = (new ProductCsvImporter)->plan($this->tenant, $this->csv("Περιγραφή;Τιμή\nΑ;1.200\nΒ;1.200,00\nΓ;12.50\n"));

        $this->assertSame(PlannedRow::ERROR, $plan->rows[0]->action);
        $this->assertStringContainsString('διφορούμενη', $plan->rows[0]->errors[0]);
        $this->assertSame(1200.0, $plan->rows[1]->values['sell_price']);
        $this->assertSame(12.5, $plan->rows[2]->values['sell_price']);
    }

    public function test_a_supplier_typed_with_a_prefix_is_still_the_same_supplier(): void
    {
        $typed = Supplier::create(['company_id' => $this->tenant->id, 'afm' => 'EL 090000045', 'name' => 'Χειροκίνητος']);

        $plan = (new SupplierCsvImporter)->import($this->tenant, $this->csv("ΑΦΜ;Πόλη\n090000045;Λάρισα\n"));

        $this->assertSame(PlannedRow::FILL, $plan->rows[0]->action);
        $this->assertSame('Λάρισα', $typed->fresh()->city);
        $this->assertSame(1, Supplier::where('company_id', $this->tenant->id)->count());
    }

    public function test_existing_product_prices_stay_a_coherent_pair_at_the_products_own_rate(): void
    {
        $vat24 = $this->vat(24, true);
        $this->vat(13);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γ', 'markup' => 0]);
        $etl = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'ETL', 'sku' => 'E-1', 'sell_price' => 50,
            'price_wvat' => 0, 'vat_category_id' => $vat24->id, 'product_category_id' => $cat->id,
        ]);

        $plan = (new ProductCsvImporter)->import($this->tenant, $this->csv("Κωδικός;Τιμή;ΦΠΑ\nE-1;60;13\n"));

        $etl->refresh();
        $this->assertSame('50.00', (string) $etl->sell_price, 'the existing net is kept');
        $this->assertSame('62.00', (string) $etl->price_wvat, 'the blank gross follows the existing net at 24%');
        $this->assertStringContainsString('διαφέρει από του προϊόντος (24%)', $plan->rows[0]->warnings[0]);
    }

    public function test_filling_an_existing_record_follows_the_fill_gate(): void
    {
        $existing = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Αλφα', 'afm' => '094019245']);

        $plan = (new CustomerCsvImporter)
            ->withFillGate(fn () => false)
            ->import($this->tenant, $this->csv("ΑΦΜ;Πόλη;Επωνυμία\n094019245;Αθήνα;\n090000045;Πάτρα;Νέος\n"));

        $this->assertSame(PlannedRow::UNCHANGED, $plan->rows[0]->action);
        $this->assertStringContainsString('δικαίωμα επεξεργασίας', $plan->rows[0]->warnings[0]);
        $this->assertNull($existing->fresh()->city);
        $this->assertSame(PlannedRow::CREATE, $plan->rows[1]->action, 'creating is not an edit');
    }

    public function test_row_numbers_are_spreadsheet_rows(): void
    {
        $table = $this->csv("sep=;\nΕπωνυμία;Διεύθυνση\nΑ;\"Οδός 1\nΌροφος 2\"\nΒ;x\n");

        $this->assertSame(3, $table->rows[0]['line']);
        $this->assertSame(5, $table->rows[1]['line'], 'after a two-line quoted cell');
        $this->assertSame(4, $this->csv("\n\nΕπωνυμία\nΑ\n")->rows[0]['line'], 'leading blank lines are skipped, still counted');
    }
    /* ───────────── review round 2 ───────────── */

    public function test_an_estonian_tenant_is_not_judged_by_greek_rules(): void
    {
        $ee = Company::factory()->create(['country_code' => 'EE']);

        $plan = (new CustomerCsvImporter)->import($ee, $this->csv("Name;VAT\nTallinn OÜ;10137319\n"));

        $this->assertSame(PlannedRow::CREATE, $plan->rows[0]->action);
        $c = Customer::where('company_id', $ee->id)->sole();
        $this->assertSame('10137319', $c->afm, 'no Greek zero-padding');
        $this->assertSame('EE', $c->country);
    }

    public function test_thousands_separators_either_way_and_scientific_notation_are_refused(): void
    {
        $this->vat(24, true);

        $plan = (new ProductCsvImporter)->plan($this->tenant, $this->csv(
            "Περιγραφή;Τιμή\nΑ;1,200\nΒ;1.23E+15\nΓ;1,20\n"
        ));

        $this->assertStringContainsString('διφορούμενη', $plan->rows[0]->errors[0]);
        $this->assertStringContainsString('μη έγκυρος αριθμός', $plan->rows[1]->errors[0]);
        $this->assertSame(1.2, $plan->rows[2]->values['sell_price']);
    }

    public function test_an_overlong_vat_identity_fails_the_row_not_the_import(): void
    {
        $plan = (new CustomerCsvImporter)->import($this->tenant, $this->csv(
            "Επωνυμία;ΑΦΜ\nΔύο ΑΦΜ;EL094019245 / EL090000045\nΚανονικός;094019245\n"
        ));

        $this->assertStringContainsString('πολύ μεγάλο', $plan->rows[0]->errors[0]);
        $this->assertSame(1, Customer::where('company_id', $this->tenant->id)->count());
    }

    public function test_several_categories_with_one_rate_are_not_guessed_between(): void
    {
        foreach (['0% ενδοκοινοτική', '0% άρθρο 39α'] as $d) {
            VatCategory::create(['company_id' => $this->tenant->id, 'description' => $d, 'rate' => 0, 'vat_exemption_category' => 1, 'mydata_vat_category' => 7]);
        }

        $plan = (new ProductCsvImporter)->plan($this->tenant, $this->csv("Περιγραφή;ΦΠΑ\nΑ;0\n"));

        $this->assertStringContainsString('πολλές κατηγορίες ΦΠΑ 0%', $plan->rows[0]->errors[0]);
    }

    public function test_a_trashed_vat_category_is_never_picked(): void
    {
        $this->vat(24)->delete();
        $live = $this->vat(24);

        $plan = (new ProductCsvImporter)->plan($this->tenant, $this->csv("Περιγραφή;ΦΠΑ\nΑ;24\n"));

        $this->assertSame($live->id, $plan->rows[0]->values['vat_category_id']);
    }

    public function test_new_lookups_need_the_right_to_create_them(): void
    {
        $this->vat(24, true);
        ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γενικά', 'markup' => 0]);

        $plan = (new ProductCsvImporter(fn (string $model) => false))->plan($this->tenant, $this->csv(
            "Περιγραφή;Κατηγορία;Μονάδα\nΑ;Καινούργια;\nΒ;Γενικά;Κιβώτιο\nΓ;Γενικά;\n"
        ));

        $this->assertStringContainsString('δικαίωμα να τη δημιουργήσεις', $plan->rows[0]->errors[0]);
        $this->assertStringContainsString('μονάδα μέτρησης «Κιβώτιο»', $plan->rows[1]->errors[0]);
        $this->assertSame(PlannedRow::CREATE, $plan->rows[2]->action);
    }

    public function test_a_unit_only_fill_still_follows_the_fill_gate(): void
    {
        $vat = $this->vat(24, true);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γ', 'markup' => 0]);
        Product::create(['company_id' => $this->tenant->id, 'description_short' => 'Α', 'sku' => 'A-1', 'sell_price' => 5, 'price_wvat' => 6.2,
            'vat_category_id' => $vat->id, 'product_category_id' => $cat->id]);

        $plan = (new ProductCsvImporter)->withFillGate(fn () => false)
            ->import($this->tenant, $this->csv("Κωδικός;Μονάδα\nA-1;Κιβώτιο\n"));

        $this->assertSame(PlannedRow::UNCHANGED, $plan->rows[0]->action);
        $this->assertSame(0, MetricUnit::where('company_id', $this->tenant->id)->count(), 'no unit created behind the gate');
    }

    public function test_an_existing_gross_is_never_overwritten(): void
    {
        $vat = $this->vat(24, true);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γ', 'markup' => 0]);
        $p = Product::create(['company_id' => $this->tenant->id, 'description_short' => 'Α', 'sku' => 'A-1', 'sell_price' => 0, 'price_wvat' => 12.40,
            'vat_category_id' => $vat->id, 'product_category_id' => $cat->id]);

        (new ProductCsvImporter)->import($this->tenant, $this->csv("Κωδικός;Τιμή\nA-1;20\n"));

        $p->refresh();
        $this->assertSame('12.40', (string) $p->price_wvat);
        $this->assertSame('10.00', (string) $p->sell_price, 'the blank net follows the gross on file');
    }
}
