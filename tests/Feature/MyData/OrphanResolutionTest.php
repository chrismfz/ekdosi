<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataMarkDetail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Note;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\MyData\Orphans\OrphanImporter;
use App\Services\MyData\Orphans\OrphanLinker;
use App\Services\MyData\Orphans\OrphanMatcher;
use App\Services\MyData\TransmittedDocReader;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Reminders\ReminderPlanner;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Item 4: a myDATA orphan (filed under our ΑΦΜ, no local invoice with its MARK)
 * is either LINKED to the local invoice it really is (suggested by series/ΑΑ,
 * amount, ΑΦΜ, date) or IMPORTED as filed.
 */
class OrphanResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $apy;

    private PaymentMethod $cash;

    private PaymentMethod $credit;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Company::create([
            'name' => 'Orph ΑΕ', 'slug' => 'orph-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $this->apy = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΑΠΥ', 'name' => 'Απόδειξη', 'invcount' => 92, 'mydata_type' => '11.2']);
        $this->cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '094014201', 'email' => 'p@example.gr']);
    }

    /** ΑΠΥ 90 as myDATA returns it (MarkDetail::fromAadeDoc shape): €16,50 + 24% = €20,46. */
    private function doc(array $over = []): array
    {
        return array_replace([
            'mark' => '400012824290573', 'uid' => 'UID1', 'invoiceType' => '11.2', 'invoiceTypeLabel' => 'Απόδειξη Παροχής Υπηρεσιών',
            'series' => 'ΑΠΥ', 'aa' => '90', 'invcode' => 'ΑΠΥ 90', 'issueDate' => '2026-03-09', 'issuedAtHuman' => '09/03/2026',
            'currency' => 'EUR', 'counterpartName' => null, 'counterpartVat' => null,
            'issuerName' => 'Orph ΑΕ', 'issuerVat' => '800561849', 'localStatus' => null, 'requestXml' => null, 'source' => 'aade',
            'netTotal' => 16.5, 'vatTotal' => 3.96, 'grossTotal' => 20.46,
            'state' => 'VALID', 'cancelledByMark' => null, 'direction' => 'outbound',
            'qrCodeUrl' => 'https://mydataapi.aade.gr/qr/abc', 'responseXml' => '<Invoice/>',
            'lines' => [[
                'lineNumber' => 1, 'itemCode' => null, 'itemDescr' => null, 'quantity' => null, 'unit' => null,
                'netValue' => 16.5, 'vatPercent' => null, 'vatCategory' => 1, 'vatExemptionCategory' => null, 'vatAmount' => 3.96,
                'classifications' => [['type' => 'E3_561_003', 'typeLabel' => 'Πωλήσεις υπηρεσιών', 'category' => 'category1_3', 'categoryLabel' => 'Έσοδα από παροχή υπηρεσιών', 'amount' => 16.5]],
            ]],
        ], $over);
    }

    private function local(array $attrs, float $price = 16.5): Invoice
    {
        static $n = 0;
        $n++;
        $inv = (new Invoice)->forceFill($attrs + [   // forceFill: mydata_mark is not mass-assignable
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->apy->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->cash->id, 'code' => 500 + $n, 'invcode' => 'ΑΠΥ'.(500 + $n),
            'issued_at' => '2026-03-09 10:00', 'local_status' => 'active',
        ]);
        $inv->save();
        InvoiceLine::create(['company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'qty' => 1, 'price_per_item' => $price, 'vat_percent' => 24]);
        app(RecomputeInvoiceTotals::class)($inv);

        return $inv->fresh();
    }

    /* ───────────── suggestions ───────────── */

    public function test_the_same_series_and_number_is_the_strongest_suggestion_and_marked_invoices_never_are(): void
    {
        $twin = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90', 'issued_at' => '2026-03-12 10:00'], 99);   // other date & amount
        $sameAmount = $this->local([]);                                                                     // same date + amount
        $marked = $this->local(['mydata_mark' => '400000000000001']);
        $unrelated = $this->local(['issued_at' => '2025-01-01 10:00'], 3);

        $ids = collect(app(OrphanMatcher::class)->candidates($this->tenant, $this->doc()))->pluck('invoice.id')->all();

        $this->assertSame($twin->id, $ids[0], 'ίδια σειρά & ΑΑ first, whatever the date/amount');
        $this->assertContains($sameAmount->id, $ids);
        $this->assertNotContains($marked->id, $ids, 'an invoice that already has a MARK is not a twin');
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_a_foreign_series_orphan_is_found_by_amount_date_and_afm(): void
    {
        $tpy = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 200, 'mydata_type' => '2.2']);
        $mine = $this->local(['invoice_type_id' => $tpy->id, 'invcode' => 'ΤΠΥ150', 'code' => 150, 'issued_at' => '2026-01-28 09:00'], 2300 / 1.24);
        $mine->forceFill(['gross_total' => 2300])->save();

        $c = app(OrphanMatcher::class)->candidates($this->tenant, $this->doc([
            'series' => '0', 'aa' => '62', 'invcode' => '0 62', 'invoiceType' => '2.2', 'issueDate' => '2026-01-28',
            'grossTotal' => 2300, 'netTotal' => 2300, 'vatTotal' => 0, 'counterpartVat' => '094014201',
        ]));

        $this->assertSame($mine->id, $c[0]['invoice']->id);
        $this->assertContains('ίδιο σύνολο', $c[0]['reasons']);
        $this->assertContains('ίδιο ΑΦΜ αντισυμβαλλόμενου', $c[0]['reasons']);
    }

    public function test_the_same_number_already_filed_under_another_mark_is_flagged(): void
    {
        $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90', 'mydata_mark' => '400012823255880']);

        $same = app(OrphanMatcher::class)->sameNumberWithOtherMark($this->tenant, $this->doc());

        $this->assertSame('ΑΠΥ90', $same?->invcode);
    }

    /* ───────────── «Σύνδεση» ───────────── */

    public function test_linking_records_the_mark_as_a_filing_without_resending_or_emailing(): void
    {
        $issued = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90']);   // issued here, MARK lost

        app(OrphanLinker::class)->link($this->tenant, $issued, $this->doc(), null);

        $issued->refresh();
        $this->assertSame('400012824290573', $issued->mydata_mark);
        $this->assertSame(['VALID', 'active'], [$issued->mydata_state, $issued->local_status]);
        $this->assertSame('https://mydataapi.aade.gr/qr/abc', $issued->mydata_url);
        $this->assertSame('11.2', $issued->mydata_type);
        $row = MyDataMark::where('invoice_id', $issued->id)->sole();
        $this->assertSame(['INSERT', '400012824290573'], [$row->mydata_action, $row->mark]);
        $this->assertStringContainsString('<Invoice/>', (string) $row->response, 'the AADE document is the evidence');
        $this->assertSame(1, Note::where('notable_id', $issued->id)->where('notable_type', Invoice::class)->count());
        Mail::assertNothingSent();
    }

    public function test_only_the_same_document_can_be_linked(): void
    {
        $linker = app(OrphanLinker::class);
        $twin = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90']);
        $this->assertNull($linker->blocker($this->tenant, $twin, $this->doc()));

        $why = fn (array $attrs, array $doc = []): ?string => $linker->blocker($this->tenant, $this->local($attrs + ['code' => 90, 'invcode' => 'ΑΠΥ9'.uniqid()]), $this->doc($doc));
        $this->assertStringContainsString('πρόχειρο', $why(['local_status' => 'draft']), 'a draft was never issued (and linking it would fire first-issue side effects)');
        $this->assertStringContainsString('ακυρωμένο', $why(['local_status' => 'cancelled']));
        $this->assertStringContainsString('ήδη ΜΑΡΚ', $why(['mydata_mark' => '4001']));
        $this->assertStringContainsString('Άλλη σειρά/ΑΑ', $linker->blocker($this->tenant, $this->local([]), $this->doc()), 'a lookalike under another number is another document');
        $this->assertStringContainsString('Άλλο ποσό', $linker->blocker($this->tenant, $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90b'], 99), $this->doc()));
        $this->assertStringContainsString('Άλλο ποσό', $linker->blocker($this->tenant, $twin, $this->doc(['netTotal' => 18.1, 'vatTotal' => 2.36])), 'same gross, another VAT split = another document');
        $frozen = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90c', 'vat_no' => '090000045']);   // issued to 090000045, customer edited since
        $this->assertStringContainsString('Άλλος αντισυμβαλλόμενος', $linker->blocker($this->tenant, $frozen, $this->doc(['counterpartVat' => '094014201'])), 'the frozen counterpart decides');
        $this->assertStringContainsString('Άλλος αντισυμβαλλόμενος', $linker->blocker($this->tenant, $twin, $this->doc(['counterpartVat' => '090000045'])));
        $this->assertStringContainsString('ακυρωμένο στο myDATA', $linker->blocker($this->tenant, $twin, $this->doc(['state' => 'CANCELLED'])));
        $this->assertStringContainsString('πιστωτικό', $linker->blocker($this->tenant, $twin, $this->doc(['invoiceType' => '5.1'])));
        $this->assertStringContainsString('ΑΦΜ μας', $linker->blocker($this->tenant, $twin, $this->doc(['issuerVat' => '099999999', 'direction' => 'unknown'])));
        $this->assertStringContainsString('ημερομηνία', $linker->blocker($this->tenant, $twin, $this->doc(['issueDate' => '2025-03-09'])), 'the same number a year apart is another document');
        $this->assertStringContainsString('τύπος', $linker->blocker($this->tenant, $twin, $this->doc(['invoiceType' => '11.1'])));
        $this->assertNull($linker->blocker($this->tenant, $twin, $this->doc(['aa' => '090'])), 'a zero-padded ΑΑ is the same number');
        $pending = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90p', 'mydata_pending_since' => now()]);
        $this->assertStringContainsString('Εκκρεμεί υποβολή', $linker->blocker($this->tenant, $pending, $this->doc()));
        $this->local(['mydata_mark' => '400012824290573']);
        $this->assertStringContainsString('άλλο τοπικό', $linker->blocker($this->tenant, $twin, $this->doc()));

        $this->expectException(RuntimeException::class);
        $linker->link($this->tenant, $twin, $this->doc(), null);
    }

    public function test_linking_waits_for_a_submission_of_the_same_invoice_in_flight(): void
    {
        $twin = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90']);
        $lock = Cache::lock('mydata-submit:'.$twin->id, 120);
        $this->assertTrue($lock->get());   // MyDataSubmitter is sending it right now

        try {
            app(OrphanLinker::class)->link($this->tenant, $twin, $this->doc(), null);
            $this->fail('must not race a real submission');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('υποβάλλεται', $e->getMessage());
        } finally {
            $lock->release();
        }
        $this->assertNull($twin->fresh()->mydata_mark);
    }

    /* ───────────── «Καταχώριση τοπικά» ───────────── */

    public function test_importing_recreates_the_document_as_filed_under_its_own_series(): void
    {
        $invoice = app(OrphanImporter::class)->import($this->tenant, $this->doc(), $this->apy, null, $this->cash, null);

        $this->assertSame(['ΑΠΥ90', 'ΑΠΥ', 90], [$invoice->invcode, $invoice->series, (int) $invoice->code]);
        $this->assertSame('2026-03-09', $invoice->issued_at->toDateString());
        $this->assertSame(['active', 'VALID', '400012824290573'], [$invoice->local_status, $invoice->mydata_state, $invoice->mydata_mark]);
        $this->assertSame('20.46', number_format((float) $invoice->gross_total, 2, '.', ''));
        $line = $invoice->lines()->sole();
        $this->assertSame(['Γραμμή 1 (από myDATA)', '16.50', '24.00'], [$line->product_descr, (string) $line->price_per_item, (string) $line->vat_percent]);
        $this->assertSame(['E3_561_003', 'category1_3'], [$line->mydata_income_class, $line->mydata_income_class_category], 'filed classification kept');
        $this->assertSame('INSERT', MyDataMark::where('invoice_id', $invoice->id)->sole()->mydata_action);
        $this->assertSame(92, (int) $this->apy->fresh()->invcount, 'the counter was already past 90');
        Mail::assertNothingSent();
    }

    public function test_a_document_filed_under_our_series_goes_under_that_series_type_only(): void
    {
        $other = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 5, 'mydata_type' => '11.2']);

        try {
            app(OrphanImporter::class)->import($this->tenant, $this->doc(), $other, null, $this->cash, null);
            $this->fail('another type would leave the ΑΠΥ counter behind');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ανήκει στον τύπο', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());
    }

    public function test_import_needs_the_filed_kind_of_document_and_exact_cents(): void
    {
        $b2b = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 5, 'mydata_type' => '2.1']);
        $foreign = ['series' => 'Α', 'aa' => '7', 'invcode' => 'Α 7'];

        try {
            app(OrphanImporter::class)->import($this->tenant, $this->doc($foreign), $b2b, null, $this->cash, null);
            $this->fail('a retail 11.2 must not become a local 2.1');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('δεν είναι ο τύπος', $e->getMessage());
        }

        $retail = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΛΙΑ', 'name' => 'Λιανική', 'invcount' => 5, 'mydata_type' => '11.2']);
        try {
            // Priced by gross elsewhere: 8,06 + 1,94 = 10,00 — our line math gives 1,93 VAT.
            app(OrphanImporter::class)->import($this->tenant, $this->doc($foreign + [
                'netTotal' => 8.06, 'vatTotal' => 1.94, 'grossTotal' => 10.0,
                'lines' => [['lineNumber' => 1, 'netValue' => 8.06, 'vatCategory' => 1, 'vatAmount' => 1.94, 'classifications' => []]],
            ]), $retail, null, $this->cash, null);
            $this->fail('one cent off the filed document is not the filed document');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('«ΦΠΑ»', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_imported_number_ahead_of_our_counter_moves_the_counter_past_it(): void
    {
        $this->apy->forceFill(['invcount' => 50])->save();

        app(OrphanImporter::class)->import($this->tenant, $this->doc(), $this->apy, null, $this->cash, null);

        $this->assertSame(91, (int) $this->apy->fresh()->invcount, 'ΑΠΥ90 can never be handed out again');
    }

    public function test_a_foreign_series_b2b_orphan_needs_the_counterpart_as_customer(): void
    {
        $tpy = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 7, 'mydata_type' => '2.2']);
        $nixpal = Customer::create(['company_id' => $this->tenant->id, 'name' => 'NIXPAL OU', 'afm' => 'EE102019025', 'country' => 'EE']);
        $doc = $this->doc([
            'series' => '0', 'aa' => '62', 'invcode' => '0 62', 'invoiceType' => '2.2',
            'counterpartVat' => '102019025', 'counterpartCountry' => 'EE',   // AADE: foreign VAT without its prefix
            'netTotal' => 2300, 'vatTotal' => 0, 'grossTotal' => 2300,
            'lines' => [['lineNumber' => 1, 'netValue' => 2300, 'vatCategory' => 7, 'vatExemptionCategory' => 4, 'vatAmount' => 0, 'classifications' => []]],
        ]);

        try {
            app(OrphanImporter::class)->import($this->tenant, $doc, $tpy, $this->customer, $this->credit, null);
            $this->fail('a customer with another ΑΦΜ must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('102019025', $e->getMessage());
        }

        $invoice = app(OrphanImporter::class)->import($this->tenant, $doc, $tpy, $nixpal, $this->credit, null);

        $this->assertSame('0 62', $invoice->invcode, 'kept as filed — not dressed up as our ΤΠΥ series');
        $this->assertSame(7, (int) $tpy->fresh()->invcount, 'another series never moves our counter');
        $this->assertSame(4, (int) $invoice->lines()->sole()->vat_exemption_category);
        $this->assertSame('unpaid', (string) ($invoice->payment_status instanceof \BackedEnum ? $invoice->payment_status->value : $invoice->payment_status), 'credit terms → a receivable, like any issued invoice');
        $this->assertSame(Invoice::ORIGIN_MYDATA_ORPHAN, $invoice->origin);
        $this->assertNull($invoice->customer_balance_snapshot, 'history: today\'s balance was never its «Νέο υπόλοιπο»');
        $this->assertSame([], app(ReminderPlanner::class)->candidates($this->tenant)->modelKeys(), 'imported history is never chased by the automatic reminders');
    }

    public function test_a_cancelled_orphan_is_imported_as_cancelled(): void
    {
        $invoice = app(OrphanImporter::class)->import($this->tenant, $this->doc(['state' => 'CANCELLED', 'cancelledByMark' => '400099']), $this->apy, null, $this->cash, null);

        $this->assertSame(['cancelled', 'CANCELLED'], [$invoice->local_status, $invoice->mydata_state]);
        $this->assertSame(['INSERT', 'CANCEL'], MyDataMark::where('invoice_id', $invoice->id)->orderBy('id')->pluck('mydata_action')->all());
    }

    public function test_import_refuses_what_it_cannot_reproduce_faithfully_and_writes_nothing(): void
    {
        $importer = app(OrphanImporter::class);

        $this->assertStringContainsString('πιστωτικά', $importer->blocker($this->tenant, $this->doc(['invoiceType' => '5.1'])));
        $this->assertStringContainsString('παρακρατήσεις', $importer->blocker($this->tenant, $this->doc(['grossTotal' => 18.0])));
        $this->assertStringContainsString('αριθμός', $importer->blocker($this->tenant, $this->doc(['aa' => 'A-90'])));
        $this->assertStringContainsString('εξόδου', $importer->blocker($this->tenant, $this->doc(['direction' => 'inbound'])));
        $this->assertStringContainsString('ΑΦΜ μας', $importer->blocker($this->tenant, $this->doc(['issuerVat' => '099999999', 'direction' => 'unknown'])), 'only OUR issue becomes our sale');
        $this->assertStringContainsString('πώλησης', $importer->blocker($this->tenant, $this->doc(['invoiceType' => '17.1'])));
        $this->assertStringContainsString('κατηγορία ΦΠΑ', $importer->blocker($this->tenant, $this->doc(['lines' => [['netValue' => 16.5, 'vatCategory' => 8, 'vatAmount' => 0]]])));

        // A deleted invoice still holding the MARK blocks it too (no duplicate MARK).
        $holder = $this->local(['mydata_mark' => '400012824290573']);
        $holder->delete();
        $this->assertStringContainsString('ΜΑΡΚ', $importer->blocker($this->tenant, $this->doc()));
        $holder->forceDelete();

        // A zero-padded ΑΑ is the same number as a local one.
        $sixtyTwo = $this->local(['code' => 62, 'invcode' => 'ΑΠΥ62', 'issued_at' => '2025-06-01 10:00']);
        $this->assertStringContainsString('ΑΠΥ62', $importer->blocker($this->tenant, $this->doc(['aa' => '062'])));
        $sixtyTwo->forceDelete();

        // Same local number already there (even deleted) → «use Σύνδεση».
        $existing = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90']);
        $existing->delete();
        try {
            $importer->import($this->tenant, $this->doc(), $this->apy, null, $this->cash, null);
            $this->fail('duplicate number must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Σύνδεση', $e->getMessage());
        }

        // Totals that don't agree with AADE → rolled back, nothing left behind.
        $before = Invoice::withTrashed()->count();
        try {
            $importer->import($this->tenant, $this->doc(['aa' => '95', 'vatTotal' => 3.0, 'grossTotal' => 19.5]), $this->apy, null, $this->cash, null);
            $this->fail('a totals mismatch must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('«ΦΠΑ»', $e->getMessage(), 'net, VAT and gross each to the cent');
        }
        $this->assertSame($before, Invoice::withTrashed()->count());
        $this->assertSame(92, (int) $this->apy->fresh()->invcount);
    }

    /* ───────────── the page ───────────── */

    private function page(array $doc)
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
        // The page reads the orphan from AADE itself — the fake stands in for the network.
        $this->app->bind(TransmittedDocReader::class, fn ($app, array $params) => new class($params['tenant'], $doc) extends TransmittedDocReader
        {
            public function __construct(Company $tenant, private array $fake)
            {
                parent::__construct($tenant);
            }

            public function fetchDetailByMark(string $mark, Carbon $from, Carbon $to): ?array
            {
                return $this->fake;
            }
        });

        return Livewire::withQueryParams(['mark' => $doc['mark']])->test(MyDataMarkDetail::class);
    }

    public function test_the_page_imports_an_orphan_and_opens_the_new_invoice(): void
    {
        $this->page($this->doc())
            ->assertSet('isOrphan', true)
            ->callAction('import_local', data: ['invoice_type_id' => $this->apy->id, 'payment_method_id' => $this->cash->id])
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertSame('400012824290573', Invoice::where('invcode', 'ΑΠΥ90')->sole()->mydata_mark);
    }

    public function test_the_import_button_is_disabled_with_the_reason_when_it_cannot_import(): void
    {
        $this->page($this->doc(['invoiceType' => '5.1']))
            ->assertActionDisabled('import_local');
    }

    public function test_the_page_suggests_and_links_only_the_same_document(): void
    {
        $twin = $this->local(['code' => 90, 'invcode' => 'ΑΠΥ90']);
        $lookalike = $this->local([]);   // same date & amount, another number

        $page = $this->page($this->doc());
        $candidates = collect($page->get('candidates'))->keyBy('id');
        $this->assertTrue($candidates[$twin->id]['linkable']);
        $this->assertFalse($candidates[$lookalike->id]['linkable'], 'shown as a possible double issue, not linkable');

        $page->mountAction('link', ['invoice' => $lookalike->id])->callMountedAction();
        $this->assertNull($lookalike->fresh()->mydata_mark);

        $page->mountAction('link', ['invoice' => $twin->id])->callMountedAction();
        $this->assertSame('400012824290573', $twin->fresh()->mydata_mark);
    }

    public function test_enrich_reads_the_invoices_own_mark_never_the_urls(): void
    {
        $filed = $this->local(['code' => 77, 'invcode' => 'ΑΠΥ77', 'mydata_mark' => '400000000000077', 'mydata_url' => 'https://qr/own']);
        // Whatever the reader returns for it, a document with ANOTHER mark is refused.
        $page = $this->page($this->doc(['mark' => '400000000000077']));   // resolves locally
        $this->app->bind(TransmittedDocReader::class, fn ($app, array $params) => new class($params['tenant']) extends TransmittedDocReader
        {
            public function fetchDetailByMark(string $mark, Carbon $from, Carbon $to): ?array
            {
                return ['mark' => '400012824290573', 'qrCodeUrl' => 'https://qr/someone-else', 'state' => 'VALID'];
            }
        });

        $page->callAction('enrich_from_aade');

        $this->assertSame('https://qr/own', $filed->fresh()->mydata_url, 'another document\'s QR never lands on this invoice');
    }

    public function test_after_enrich_the_page_stays_on_the_same_invoice_whatever_the_url_says(): void
    {
        $a = $this->local(['code' => 77, 'invcode' => 'ΑΠΥ77', 'mydata_mark' => '400000000000077', 'mydata_state' => 'VALID']);
        $b = $this->local(['code' => 78, 'invcode' => 'ΑΠΥ78', 'mydata_mark' => '400000000000078', 'mydata_state' => 'VALID']);
        $page = $this->page($this->doc(['mark' => '400000000000077', 'state' => 'CANCELLED']));   // A is cancelled at AADE

        $page->set('mark', '400000000000078')   // the URL now names B…
            ->callAction('enrich_from_aade');      // …while A is enriched

        $this->assertSame($a->id, $page->get('invoiceId'), 'reloaded by A\'s own MARK');
        $this->assertSame('VALID', $b->fresh()->mydata_state, 'A\'s AADE state can never be synced onto B');
    }

    public function test_the_document_the_actions_trust_cannot_be_rewritten_from_the_browser(): void
    {
        $page = $this->page($this->doc());

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('doc.mark', '400099999999999');
    }
}
