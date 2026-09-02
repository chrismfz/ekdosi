<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use App\Services\Delivery\DeliveryNoteSubmitter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * MYD-021 — a δελτίο αποστολής must be filed at most once.
 *
 * A 9.x δελτίο goes through the same AADE channel and is just as legally binding
 * as an invoice, but this path had NONE of the invoice path's protections: no
 * lock, no in-doubt marker, no adopt-or-file recovery, and no service-level guard
 * against a locally cancelled note. Two concurrent requests — a double click, two
 * tabs, an overlapping worker — could each POST and create TWO AADE documents;
 * AADE does not dedup, and the local database would keep only one of them.
 *
 * The MockHandler is the assertion throughout: it is a strict queue, so an
 * attempted POST that should not happen finds no queued response and fails loudly.
 */
class DeliveryNoteExactlyOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private DeliveryNote $note;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'ΔΑ test', 'slug' => 'da-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΔΑ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 2, 'mydata_type' => '9.3',
        ]);
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Παραλήπτης ΑΕ', 'afm' => '123456789',
        ]);

        $this->note = DeliveryNote::create([
            'company_id' => $this->tenant->id, 'delivery_type_id' => $this->type->id,
            'customer_id' => $customer->id, 'invcode' => 'ΔΑ1', 'code' => 1,
            'issued_at' => now(), 'mydata_type' => '9.3', 'move_purpose' => 1,
            'local_status' => 'active', 'dispatch_at' => now()->addHour(),
            'vehicle_number' => 'ΙΑΒ1234', 'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789', 'recipient_country' => 'GR',
            'loading_street' => 'Φόρτωσης', 'loading_number' => '10',
            'loading_postcode' => '11111', 'loading_city' => 'Αθήνα', 'start_shipping_branch' => 0,
            'delivery_street' => 'Παράδοσης', 'delivery_number' => '20',
            'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη', 'complete_shipping_branch' => 0,
        ]);
        $this->note->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Server', 'qty' => 2,
            // §8.13 coded unit — without it the payload build refuses the note
            // BEFORE any POST, which would silently hollow out the tests below.
            'measurement_unit' => 1,
        ]);
        $this->note = $this->note->fresh('lines');
    }

    /** A RequestTransmittedDocs response carrying one live 9.3 doc for (series, ΑΑ). */
    private function transmittedDocsMock(string $series, string $aa, string $mark): GuzzleResponse
    {
        $xml = <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
          <invoicesDoc>
            <invoice>
              <mark>{$mark}</mark>
              <issuer><vatNumber>800561849</vatNumber><country>GR</country><branch>0</branch></issuer>
              <invoiceHeader>
                <series>{$series}</series>
                <aa>{$aa}</aa>
                <issueDate>2026-09-02</issueDate>
                <invoiceType>9.3</invoiceType>
                <currency>EUR</currency>
              </invoiceHeader>
              <invoiceSummary>
                <totalNetValue>0</totalNetValue><totalVatAmount>0</totalVatAmount>
                <totalWithheldAmount>0</totalWithheldAmount><totalFeesAmount>0</totalFeesAmount>
                <totalStampDutyAmount>0</totalStampDutyAmount><totalOtherTaxesAmount>0</totalOtherTaxesAmount>
                <totalDeductionsAmount>0</totalDeductionsAmount><totalGrossValue>0</totalGrossValue>
              </invoiceSummary>
            </invoice>
          </invoicesDoc>
        </RequestedDoc>
        XML;

        return new GuzzleResponse(200, [], $xml);
    }

    private function emptyTransmittedDocsMock(): GuzzleResponse
    {
        return new GuzzleResponse(200, [], '<?xml version="1.0" encoding="utf-8"?>'
            .'<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"></RequestedDoc>');
    }

    // ─────────────────────────── concurrency ──────────────────────────────

    public function test_a_second_concurrent_submit_is_refused_not_queued_behind_the_first(): void
    {
        // Simulate the other worker by holding the same lock. An empty MockHandler
        // is the assertion: any POST attempt would find no queued response.
        $lock = Cache::lock('delivery-submit:'.$this->note->getKey(), 120);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ήδη σε εξέλιξη');

            (new DeliveryNoteSubmitter($this->tenant, new MockHandler([])))->submit($this->note);
        } finally {
            $lock->release();
        }
    }

    public function test_a_note_filed_by_another_worker_is_seen_under_the_lock(): void
    {
        // The in-memory $note is stale — another worker filed it. submit() must
        // re-read under the lock and refuse, not POST a second time.
        DB::table('delivery_notes')->where('id', $this->note->id)->update([
            'mydata_state' => 'VALID', 'mydata_mark' => '400001965177931',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already filed');

        (new DeliveryNoteSubmitter($this->tenant, new MockHandler([])))->submit($this->note);
    }

    // ──────────────────────── the in-doubt window ─────────────────────────

    public function test_the_marker_is_armed_before_the_post(): void
    {
        // The core of MYD-021. Before this, a hard kill (OOM, deploy, host failure)
        // between AADE accepting the request and our catch left NO trace at all —
        // the lock expired and the next attempt POSTed blindly into a filing that
        // already existed. Assert the marker is committed at the moment the
        // transport is entered, read the way a DIFFERENT process would see it.
        $seenAtPostTime = null;
        $noteId = $this->note->id;

        $mock = new MockHandler([
            function () use (&$seenAtPostTime, $noteId) {
                $seenAtPostTime = DB::table('delivery_notes')->where('id', $noteId)->value('mydata_pending_since');

                throw new RuntimeException('killed mid-flight');
            },
        ]);

        try {
            (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($this->note);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotNull(
            $seenAtPostTime,
            'the in-doubt marker must be committed BEFORE the POST (a null here also means the '
            .'transport was never entered — check the payload builds)',
        );
        $this->assertNotNull($this->note->fresh()->mydata_pending_since, 'and it must survive the failure');
    }

    public function test_an_in_doubt_note_adopts_the_existing_mark_instead_of_refiling(): void
    {
        $adopted = '400001965177931';
        $this->note->forceFill(['mydata_pending_since' => now()->subMinutes(1)])->save();

        // ONLY the reconcile response is queued. A second SendInvoices POST would
        // find an empty queue and blow up — which is exactly the guarantee.
        $mock = new MockHandler([$this->transmittedDocsMock('ΔΑ', '1', $adopted)]);

        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($this->note->fresh('lines'));

        $this->assertSame($adopted, (string) $mark->mark);
        $this->assertSame('INSERT', $mark->mydata_action);

        $fresh = $this->note->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame($adopted, (string) $fresh->mydata_mark);
        $this->assertSame('registered', $fresh->delivery_state);
        $this->assertNull($fresh->mydata_pending_since, 'adoption clears the marker');

        $this->assertSame(1, DeliveryMark::where('delivery_note_id', $this->note->id)
            ->where('mark', $adopted)->count());
        $this->assertSame(0, $mock->count(), 'no second filing may be attempted');

        // The ΑΑ counter was NOT burned — nothing was allocated.
        $this->assertSame(2, $this->type->fresh()->invcount);
    }

    public function test_inside_the_grace_window_an_empty_aade_does_not_licence_a_blind_retry(): void
    {
        // AADE's feed lags a fresh filing by a minute or two. "Not found" therefore
        // does NOT mean "never filed" — resubmitting here is how a second MARK gets
        // created. Only the reconcile response is queued.
        $this->note->forceFill(['mydata_pending_since' => now()->subMinutes(1)])->save();

        $mock = new MockHandler([$this->emptyTransmittedDocsMock()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ΔΕΝ ξαναϋποβάλλουμε τυφλά');

        (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($this->note->fresh('lines'));
    }

    public function test_past_the_grace_window_an_empty_aade_does_licence_a_normal_filing(): void
    {
        // The other direction: the gate must not strand a note forever when the
        // earlier POST genuinely never landed.
        $this->note->forceFill(['mydata_pending_since' => now()->subMinutes(30)])->save();

        $sendXml = file_get_contents(base_path('vendor/firebed/aade-mydata/stubs/send-invoices-single-response.xml'));
        $mock = new MockHandler([$this->emptyTransmittedDocsMock(), new GuzzleResponse(200, [], $sendXml)]);

        (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($this->note->fresh('lines'));

        $fresh = $this->note->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNull($fresh->mydata_pending_since, 'a successful filing clears the marker');
        $this->assertSame(0, $mock->count(), 'both the lookup and the filing ran');
    }

    public function test_an_unreachable_aade_never_licences_a_blind_retry(): void
    {
        // If we cannot verify, we do not gamble — the note stays in-doubt.
        $this->note->forceFill(['mydata_pending_since' => now()->subMinutes(30)])->save();

        $mock = new MockHandler([new RuntimeException('AADE down')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Δεν ξαναϋποβάλλουμε');

        (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($this->note->fresh('lines'));
    }

    // ───────────────────────── locally cancelled ──────────────────────────

    public function test_a_locally_cancelled_note_is_refused_at_service_level(): void
    {
        // The UI hides the button, but that is not protection for a CLI, API or
        // automation caller — which is exactly where it would go unnoticed.
        $this->note->forceFill(['local_status' => 'cancelled'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ακυρωμένο τοπικά');

        (new DeliveryNoteSubmitter($this->tenant, new MockHandler([])))->submit($this->note->fresh('lines'));
    }

    // ───────────────────── outcomes that prove no MARK ────────────────────

    public function test_a_rejection_clears_the_marker_so_a_fix_can_be_retried(): void
    {
        // AADE processed the δελτίο and refused it → no MARK. An operator who fixes
        // the data must be able to retry immediately, not sit out the grace window.
        $rejectXml = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <ResponseDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
          <response>
            <index>1</index>
            <statusCode>ValidationError</statusCode>
            <errors><error><message>Λάθος στοιχεία</message><code>102</code></error></errors>
          </response>
        </ResponseDoc>
        XML;

        $mock = new MockHandler([new GuzzleResponse(200, [], $rejectXml)]);

        try {
            (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($this->note);
            $this->fail('Expected the rejection to throw.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertNull($this->note->fresh()->mydata_pending_since);
        $this->assertNull($this->note->fresh()->mydata_state, 'a rejection is not a filing');
    }
}
