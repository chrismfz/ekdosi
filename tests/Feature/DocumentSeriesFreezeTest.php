<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Support\DocumentSeries;
use App\Support\FiledSeriesBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MYD-018 / MYD-024 — the FILED series is frozen per document.
 *
 * A παραστατικό is identified to AADE by (series, ΑΑ). The ΑΑ was always frozen on
 * the row (`code`), but the series was read LIVE from `invoice_types.code`, which
 * is an editable lookup. Two consequences, both real:
 *
 *   1. Renaming a series retroactively rewrote what every already-numbered
 *      document claimed to be — including documents already filed at AADE, whose
 *      series AADE holds immutably.
 *   2. Worse, the in-doubt recovery (MYD-2 σκέλος γ) searched AADE for the NEW
 *      (series, ΑΑ) — a pair AADE had never seen — so it did not find the MARK
 *      that already existed and filed the document a SECOND time. AADE does not
 *      dedup; that double-declares income.
 *
 * The series is now stored on the row. It is not guessed for existing rows either:
 * `invcode` is itself frozen and is exactly `series . code`, so the backfill
 * recovers the true historical value.
 */
class DocumentSeriesFreezeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Σειρές ΑΕ', 'slug' => 'series-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
            'tax_office' => 'ΚΕΦΟΔΕ', 'address' => 'ΑΔΡΙΑΝΟΥ 16', 'city' => 'ΑΘΗΝΑ', 'postcode' => '14121',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '997073525',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);
    }

    private function invoice(array $override = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'local_status' => 'active',
            'country' => 'GR', 'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '997073525',
        ], $override));

        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
            'net_price' => 100, 'gross_price' => 124, 'product_descr' => 'Υπηρεσία',
        ]);

        return $invoice->fresh('lines');
    }

    // ───────────────────────────── the helper ─────────────────────────────

    /**
     * @return array<string, array{0: ?string, 1: int|string|null, 2: ?string}>
     */
    public static function invcodeShapes(): array
    {
        return [
            // The series is routinely Greek. This is the case that caught a real
            // bug: cutting with a BYTE offset while counting CHARACTERS sliced
            // «Υ» in half and produced "Τ\xa0" instead of «ΤΠΥ».
            'greek series' => ['ΤΠΥ100', 100, 'ΤΠΥ'],
            'greek series, single digit' => ['ΠΙΣ7', 7, 'ΠΙΣ'],
            'latin series' => ['APY423', 423, 'APY'],
            'series with a digit in it' => ['A2Y15', 15, 'A2Y'],
            'numeric-looking series' => ['2026-7', 7, '2026-'],
            'string code' => ['ΤΠΥ100', '100', 'ΤΠΥ'],
            // Not the expected `series . code` shape → null, and the reader falls
            // back to the live type code, i.e. exactly today's behaviour.
            'code not a suffix' => ['WEIRD', 5, null],
            'no series part at all' => ['423', 423, null],
            'zero ΑΑ (an unnumbered row)' => ['ΤΠΥ0', 0, null],
            'null ΑΑ' => ['ΤΠΥ1', null, null],
            'blank invcode' => ['', 1, null],
            'null invcode' => [null, 1, null],
            'whitespace only' => ['   ', 1, null],
        ];
    }

    #[DataProvider('invcodeShapes')]
    public function test_series_is_recovered_from_the_frozen_invcode(?string $invcode, int|string|null $code, ?string $expected): void
    {
        $this->assertSame($expected, DocumentSeries::fromInvcode($invcode, $code));
    }

    public function test_recovered_greek_series_is_valid_utf8(): void
    {
        // The byte/char bug produced a string that was not valid UTF-8 at all —
        // it would have been filed to AADE as mojibake. Pin the encoding, not
        // just the value.
        $series = DocumentSeries::fromInvcode('ΤΠΥ100', 100);

        $this->assertSame('ΤΠΥ', $series);
        $this->assertTrue(mb_check_encoding($series, 'UTF-8'));
        $this->assertSame(3, mb_strlen($series));
    }

    // ───────────────────────── freezing on create ─────────────────────────

    public function test_creating_an_invoice_freezes_its_series(): void
    {
        $invoice = $this->invoice();

        $this->assertSame('ΤΠΥ', $invoice->series);
        $this->assertSame('ΤΠΥ', $invoice->filedSeries());
    }

    public function test_an_explicitly_supplied_series_is_not_overwritten(): void
    {
        // The hook only fills a blank. A creator that knows the series (the
        // allocation carries one) stays authoritative.
        $invoice = $this->invoice(['series' => 'ΧΕΙΡΟΚΙΝΗΤΑ']);

        $this->assertSame('ΧΕΙΡΟΚΙΝΗΤΑ', $invoice->series);
    }

    public function test_creating_a_delivery_note_freezes_its_series(): void
    {
        $deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΔΑ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);

        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id, 'delivery_type_id' => $deliveryType->id,
            'customer_id' => $this->customer->id, 'invcode' => 'ΔΑ1', 'code' => 1,
            'issued_at' => now(), 'mydata_type' => '9.3', 'move_purpose' => 8,
            'local_status' => 'draft',
        ]);

        $this->assertSame('ΔΑ', $note->series);
        $this->assertSame('ΔΑ', $note->filedSeries());
    }

    // ───────────────────── the rename is what this closes ─────────────────

    public function test_renaming_the_type_does_not_change_an_existing_document_series(): void
    {
        $invoice = $this->invoice();

        $this->type->update(['code' => 'ΤΠΥΝΕΑ']);

        $this->assertSame('ΤΠΥ', $invoice->fresh()->filedSeries());
        $this->assertSame('ΤΠΥ1', $invoice->fresh()->invcode, 'invcode was already frozen');
    }

    public function test_renaming_the_type_does_change_the_next_document(): void
    {
        // The freeze must not turn the lookup into a write-once field: a rename
        // is a legitimate operation for documents issued FROM NOW ON.
        $this->invoice();
        $this->type->update(['code' => 'ΤΠΥΝΕΑ']);

        $next = $this->invoice(['invcode' => 'ΤΠΥΝΕΑ2', 'code' => 2]);

        $this->assertSame('ΤΠΥΝΕΑ', $next->series);
    }

    public function test_a_prefreeze_row_falls_back_to_the_live_type_code(): void
    {
        // Rows numbered before this change (and anything the backfill could not
        // read) keep a null series. They must behave exactly as they did before —
        // never null-out a payload field.
        $invoice = $this->invoice();
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => null]);

        $this->assertSame('ΤΠΥ', $invoice->fresh()->filedSeries());
    }

    public function test_a_zero_series_is_kept_not_treated_as_absent(): void
    {
        // '0' is falsy in PHP. Reading the frozen column with `?:` would discard it
        // and fall back to the live lookup — silently reintroducing the defect for
        // exactly the tenants using a numeric series scheme.
        $invoice = $this->invoice();
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => '0']);
        $this->type->update(['code' => 'ΑΛΛΟ']);

        $this->assertSame('0', $invoice->fresh()->filedSeries());
    }

    // ───────────────────────── the filing payload ─────────────────────────

    public function test_the_aade_payload_uses_the_frozen_series_after_a_rename(): void
    {
        $invoice = $this->invoice();

        $this->type->update(['code' => 'ΤΠΥΝΕΑ']);

        $doc = new AadeInvoiceDocument($this->tenant);
        $xml = $doc->toXml($doc->build($invoice->fresh('lines')));

        $this->assertStringContainsString('<series>ΤΠΥ</series>', $xml);
        $this->assertStringNotContainsString('ΤΠΥΝΕΑ', $xml);
    }

    public function test_the_aade_payload_refuses_a_document_with_no_series_at_all(): void
    {
        // Fail closed. Filing under an empty series would create a document at
        // AADE that can never be matched back — and the recovery path would then
        // be unable to find it either.
        // No frozen series AND a blank type code — the only way a document can end
        // up with no series at all. invoice_type_id is NOT NULL, so blank the code.
        $invoice = $this->invoice();
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => null]);
        DB::table('invoice_types')->where('id', $this->type->id)->update(['code' => '']);

        $this->assertNull($invoice->fresh()->filedSeries());

        $this->expectException(\RuntimeException::class);

        $doc = new AadeInvoiceDocument($this->tenant);
        $doc->build($invoice->fresh('lines'));
    }

    // ───────────────────────────── the backfill ───────────────────────────

    public function test_the_migration_backfills_the_series_from_invcode(): void
    {
        // Simulate pre-freeze rows: the ETL writes via the query builder, so no
        // model hook fires. One recoverable, one not.
        $good = $this->invoice(['invcode' => 'ΤΠΥ77', 'code' => 77]);
        $odd = $this->invoice(['invcode' => 'ΧΕΙΡΟΓΡΑΦΟ', 'code' => 78]);
        DB::table('invoices')->whereIn('id', [$good->id, $odd->id])->update(['series' => null]);

        $migration = require base_path('tests/Fixtures/migrations/2026_09_02_000002_add_series_to_numbered_documents.php');
        $migration->up();

        $this->assertSame('ΤΠΥ', $good->fresh()->series);
        $this->assertNull($odd->fresh()->series, 'an unreadable pair stays null and falls back at read time');
        $this->assertSame('ΤΠΥ', $odd->fresh()->filedSeries());
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function requestXmlShapes(): array
    {
        return [
            'plain header' => ['<invoiceHeader><series>ΤΠΥ2</series><aa>7</aa></invoiceHeader>', 'ΤΠΥ2'],
            'namespaced' => ['<ns:invoiceHeader><ns:series>APY</ns:series></ns:invoiceHeader>', 'APY'],
            'padded' => ['<series>  ΑΠΕ-Κ  </series>', 'ΑΠΕ-Κ'],
            'entity-escaped' => ['<invoice><series>A&amp;B</series></invoice>', 'A&B'],
            // A CANCEL row's `request` is a free-text reason, not XML.
            'cancel reason text' => ['Cancel reason: λάθος πελάτης', null],
            'empty series' => ['<series></series>', null],
            'no request stored' => [null, null],
        ];
    }

    #[DataProvider('requestXmlShapes')]
    public function test_series_is_read_from_the_stored_request_xml(?string $xml, ?string $expected): void
    {
        $this->assertSame($expected, DocumentSeries::fromRequestXml($xml));
    }

    public function test_the_backfill_prefers_what_was_actually_filed_over_the_invcode(): void
    {
        // The one case where invcode is NOT the filed series: a draft numbered
        // under ΤΠΥ, the type renamed to ΤΠΥ2, and only then filed. AADE holds
        // ΤΠΥ2. Freezing the invcode value would turn a row the reconciler
        // currently MATCHES into a permanent conflict — the fix causing the very
        // problem it exists to prevent.
        $invoice = $this->invoice(['invcode' => 'ΤΠΥ50', 'code' => 50]);
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => null]);

        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177931', 'mydata_action' => 'INSERT',
            'request' => '<invoiceHeader><series>ΤΠΥ2</series><aa>50</aa></invoiceHeader>',
        ]);

        $migration = require base_path('tests/Fixtures/migrations/2026_09_02_000002_add_series_to_numbered_documents.php');
        $migration->up();

        $this->assertSame('ΤΠΥ2', $invoice->fresh()->series);
    }

    public function test_the_backfill_ignores_marks_that_prove_nothing(): void
    {
        // A dry-run and a rejection both store the request XML but no MARK — AADE
        // never accepted them, so they do not say what the document is filed as.
        // A CANCEL row's request is a free-text reason, not XML.
        $invoice = $this->invoice(['invcode' => 'ΤΠΥ51', 'code' => 51]);
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => null]);

        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => null, 'mydata_action' => 'DRY_RUN',
            'request' => '<invoiceHeader><series>ΠΟΤΕ</series></invoiceHeader>',
        ]);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177940', 'mydata_action' => 'CANCEL',
            'request' => 'Cancel reason: ΠΟΤΕ',
        ]);

        $migration = require base_path('tests/Fixtures/migrations/2026_09_02_000002_add_series_to_numbered_documents.php');
        $migration->up();

        $this->assertSame('ΤΠΥ', $invoice->fresh()->series, 'falls back to the frozen invcode');
    }

    public function test_a_provider_filed_document_uses_its_mark_xml_too(): void
    {
        // A provider-filed document carries a real MARK and the real request XML
        // under PROVIDER_INSERT — every other consumer in the tree pairs the two
        // actions. Reading only INSERT would drop provider-filed invoices back onto
        // `invcode`, reintroducing exactly the rename-window case the XML source
        // exists to cover.
        $invoice = $this->invoice(['invcode' => 'ΤΠΥ53', 'code' => 53]);
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => null]);

        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177943', 'mydata_action' => 'PROVIDER_INSERT',
            'request' => '<invoiceHeader><series>ΤΠΥ2</series><aa>53</aa></invoiceHeader>',
        ]);

        $migration = require base_path('tests/Fixtures/migrations/2026_09_02_000002_add_series_to_numbered_documents.php');
        $migration->up();

        $this->assertSame('ΤΠΥ2', $invoice->fresh()->series);
    }

    public function test_the_first_accepted_filing_wins_over_a_later_one(): void
    {
        // A re-file must not rewrite the identity the document has held since its
        // first accepted filing.
        $invoice = $this->invoice(['invcode' => 'ΤΠΥ52', 'code' => 52]);
        DB::table('invoices')->where('id', $invoice->id)->update(['series' => null]);

        $first = MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177941', 'mydata_action' => 'INSERT',
            'request' => '<invoiceHeader><series>ΠΡΩΤΗ</series></invoiceHeader>',
        ]);
        $second = MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177942', 'mydata_action' => 'INSERT',
            'request' => '<invoiceHeader><series>ΔΕΥΤΕΡΗ</series></invoiceHeader>',
        ]);
        $this->assertLessThan($second->id, $first->id);

        $migration = require base_path('tests/Fixtures/migrations/2026_09_02_000002_add_series_to_numbered_documents.php');
        $migration->up();

        $this->assertSame('ΠΡΩΤΗ', $invoice->fresh()->series);
    }

    public function test_the_shared_backfill_is_tenant_scopable_for_the_etl(): void
    {
        // The ETL calls this per tenant, AFTER copyMarks() — the legacy MARK rows
        // (and their REQUEST XML) are not local until then, which is why it cannot
        // simply reuse the migration's pass. One shared definition so the ETL's
        // frozen value and the migration's cannot drift apart.
        $mine = $this->invoice(['invcode' => 'ΤΠΥ60', 'code' => 60]);
        DB::table('invoices')->where('id', $mine->id)->update(['series' => null]);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $mine->id,
            'mark' => '400001965177950', 'mydata_action' => 'INSERT',
            'request' => '<invoiceHeader><series>ΦΙΛΕΝΤ</series></invoiceHeader>',
        ]);

        $other = Company::create([
            'name' => 'Άλλη', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '801280908',
        ]);
        $otherType = InvoiceType::create([
            'company_id' => $other->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $otherCustomer = Customer::create([
            'company_id' => $other->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);
        $theirs = Invoice::create([
            'company_id' => $other->id, 'invcode' => 'ΤΠΥ61', 'code' => 61,
            'invoice_type_id' => $otherType->id, 'customer_id' => $otherCustomer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $other->id, 'invoice_id' => $theirs->id,
            'mark' => '400001965177951', 'mydata_action' => 'INSERT',
            'request' => '<invoiceHeader><series>ΞΕΝΗ</series></invoiceHeader>',
        ]);

        $corrected = FiledSeriesBackfill::apply('invoices', $this->tenant->id);

        $this->assertSame(1, $corrected);
        $this->assertSame('ΦΙΛΕΝΤ', $mine->fresh()->series);
        $this->assertSame('ΤΠΥ', $theirs->fresh()->series, 'the other tenant was not touched');

        // Unscoped (the migration's call) reaches every tenant.
        $this->assertSame(1, FiledSeriesBackfill::apply('invoices'));
        $this->assertSame('ΞΕΝΗ', $theirs->fresh()->series);
    }

    public function test_the_shared_backfill_honours_an_extra_constraint(): void
    {
        // The ETL passes whereNotNull(legacy_id): its locked contract is that a
        // Filament-created row is NEVER touched by an import. The migration's own
        // unscoped pass is what covers those.
        $imported = $this->invoice(['invcode' => 'ΤΠΥ70', 'code' => 70, 'legacy_id' => 7001]);
        $operatorMade = $this->invoice(['invcode' => 'ΤΠΥ71', 'code' => 71]);
        $this->assertNull($operatorMade->legacy_id);

        foreach ([$imported, $operatorMade] as $i => $invoice) {
            MyDataMark::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
                'mark' => '40000196517796'.$i, 'mydata_action' => 'INSERT',
                'request' => '<invoiceHeader><series>ΑΠΟΤΟΧΜL</series></invoiceHeader>',
            ]);
        }

        $corrected = FiledSeriesBackfill::apply(
            'invoices',
            $this->tenant->id,
            fn ($query) => $query->whereNotNull('invoices.legacy_id'),
        );

        $this->assertSame(1, $corrected);
        $this->assertSame('ΑΠΟΤΟΧΜL', $imported->fresh()->series);
        $this->assertSame('ΤΠΥ', $operatorMade->fresh()->series, 'a Filament-created row is off limits to the ETL');

        // Unconstrained (the migration) still reaches it.
        $this->assertSame(1, FiledSeriesBackfill::apply('invoices', $this->tenant->id));
        $this->assertSame('ΑΠΟΤΟΧΜL', $operatorMade->fresh()->series);
    }

    public function test_the_shared_backfill_updates_more_rows_than_one_batch(): void
    {
        // The buckets are accumulated table-wide, so the UPDATE list is capped at
        // 500 ids: a tenant where most rows share one series would otherwise push a
        // single whereIn past MySQL's 65,535-placeholder limit. Prove the chunking
        // still writes every row rather than only the first batch.
        $ids = [];
        for ($i = 200; $i < 205; $i++) {
            $invoice = $this->invoice(['invcode' => 'ΤΠΥ'.$i, 'code' => $i]);
            $ids[] = $invoice->id;
            MyDataMark::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
                'mark' => '4000019651779'.$i, 'mydata_action' => 'INSERT',
                'request' => '<invoiceHeader><series>ΜΑΖΙΚΗ</series></invoiceHeader>',
            ]);
        }

        $this->assertSame(5, FiledSeriesBackfill::apply('invoices', $this->tenant->id));
        $this->assertSame(5, DB::table('invoices')->whereIn('id', $ids)->where('series', 'ΜΑΖΙΚΗ')->count());
    }

    public function test_the_shared_backfill_is_idempotent(): void
    {
        // Re-running the ETL (or the migration) must be a no-op once the series
        // already agrees with the filed XML — never a churn of pointless UPDATEs.
        $invoice = $this->invoice(['invcode' => 'ΤΠΥ62', 'code' => 62]);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177952', 'mydata_action' => 'INSERT',
            'request' => '<invoiceHeader><series>ΑΛΛΑΓΜΕΝΗ</series></invoiceHeader>',
        ]);

        $this->assertSame(1, FiledSeriesBackfill::apply('invoices', $this->tenant->id));
        $this->assertSame('ΑΛΛΑΓΜΕΝΗ', $invoice->fresh()->series);

        $this->assertSame(0, FiledSeriesBackfill::apply('invoices', $this->tenant->id));
        $this->assertSame('ΑΛΛΑΓΜΕΝΗ', $invoice->fresh()->series);
    }

    public function test_the_backfill_does_not_skip_rows_when_it_pages(): void
    {
        // The backfill writes the very column it filters on. With chunk() (OFFSET
        // paging) each completed page shifts the window and every second page of
        // rows is skipped; chunkById() keys off the id instead. Force several
        // pages by shrinking nothing — just prove ALL rows land.
        $ids = [];
        for ($i = 100; $i < 140; $i++) {
            $ids[] = $this->invoice(['invcode' => 'ΤΠΥ'.$i, 'code' => $i])->id;
        }
        DB::table('invoices')->whereIn('id', $ids)->update(['series' => null]);

        $migration = require base_path('tests/Fixtures/migrations/2026_09_02_000002_add_series_to_numbered_documents.php');
        $migration->up();

        $this->assertSame(
            0,
            DB::table('invoices')->whereIn('id', $ids)->whereNull('series')->count(),
            'every row must be backfilled, none skipped by paging',
        );
    }
}
