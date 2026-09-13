<?php

namespace Tests\Feature\EInvoice;

use App\Contracts\EInvoiceProviderTransport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\VatCategory;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\MyDataRejected;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderIssueDateGuard;
use App\Support\EInvoice\ProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * P2: GrProviderSubmitter files via an injected transport, persists a
 * PROVIDER_INSERT mark (+ provider audit columns) and syncs the invoice mirror —
 * the same source-of-truth shape as the direct myDATA path — plus the §14.4
 * status-check idempotency on an ambiguous send failure. Uses a fake transport
 * (no network, no creds).
 */
class GrProviderSubmitterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Provider tenant', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'fake',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);
    }

    public function test_successful_filing_persists_provider_mark_and_syncs_mirror(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport);

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('400000000000123', $mark->mark);
        $this->assertSame('fake', $mark->provider_key);
        $this->assertSame('AUTHCODE-XYZ', $mark->authentication_code);
        $this->assertSame('https://prov/qr', $mark->invoice_url);

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('active', $fresh->local_status); // draft promoted on filing
        $this->assertSame('400000000000123', $fresh->mydata_mark);
        $this->assertTrue((bool) $fresh->mydata_sent);
    }

    public function test_provider_filing_of_a_tracking_on_tda_seeds_registered_state(): void
    {
        // Combined ΤΔΑ (3d-b): symmetric with the direct-myDATA path — a provider-filed
        // tracking-ON ΤΔΑ must enter the movement lifecycle (delivery_state='registered')
        // so «Έναρξη διακίνησης» surfaces; the lifecycle itself runs direct to myDATA.
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'is_delivery_note' => true, 'move_purpose' => 1, 'vehicle_number' => 'ΙΑΒ1234',
            'loading_street' => 'Φόρτωση', 'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοση', 'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ])->save();

        (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh('lines'));

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNotEmpty($fresh->mydata_url);
        $this->assertSame('registered', $fresh->delivery_state);
    }

    public function test_provider_filing_of_a_tracking_off_tda_leaves_no_lifecycle_state(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'is_delivery_note' => true, 'without_digital_transport_tracking' => true, 'move_purpose' => 1,
            'loading_street' => 'Φόρτωση', 'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοση', 'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ])->save();

        (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh('lines'));

        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
        $this->assertNull($invoice->fresh()->delivery_state);
    }

    public function test_persist_snapshots_the_provider_identity_in_force(): void
    {
        // PROV-003 (b): freeze the identity IN FORCE at issue on the mark, so a
        // later config/licence rotation can't rewrite this document's evidence.
        config(['ekdosi.einvoice.provider_identity.fake' => [
            'commercial_name' => 'Fake Provider',
            'legal_name' => 'Fake LLC',
            'site' => 'fake.example',
            'aade_code' => '999',
            'licence_no' => 'LIC_AT_ISSUE_V1',
        ]]);

        $invoice = $this->makeInvoice();
        $mark = (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice);

        $snapshot = $mark->fresh()->provider_identity;
        $this->assertIsArray($snapshot);
        $this->assertSame('LIC_AT_ISSUE_V1', $snapshot['licence_no']);
        $this->assertSame('Fake Provider', $snapshot['commercial_name']);
        $this->assertSame('999', $snapshot['aade_code']);
    }

    public function test_an_incomplete_identity_is_not_frozen_so_it_can_recover(): void
    {
        // PROV-003: a config row present but with a BLANK licence at issue must NOT
        // be frozen — otherwise snapshot-wins would strand the document with a blank
        // licence forever, un-printable even after the config is fixed. No snapshot
        // → the reader falls back to config, which is the recoverable state.
        config(['ekdosi.einvoice.provider_identity.fake' => [
            'commercial_name' => 'Fake Provider',
            'licence_no' => '',
        ]]);

        $invoice = $this->makeInvoice();
        $mark = (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice);

        $this->assertNull($mark->fresh()->provider_identity);
    }

    public function test_a_backdated_draft_is_stamped_to_today_at_send(): void
    {
        // PROV-020 (auto): the legal issue date IS the moment of issue, and the provider
        // requires IssueDate = today (InvoSign 238). A draft prepared yesterday is issued
        // TODAY — stamp it and file, instead of blocking the operator.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['issued_at' => now()->subDay()->setTime(9, 0)])->save();

        $mark = (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh('lines'));

        $today = now()->setTimezone(ProviderIssueDateGuard::TZ)->toDateString();

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame($today, $fresh->issued_at->setTimezone(ProviderIssueDateGuard::TZ)->toDateString(),
            'issued_at is moved to today at send');
        // The OUTBOUND XML must carry today too — the stamp runs BEFORE the payload
        // is serialized (guards against the delivery-path ordering bug regressing here).
        $this->assertStringContainsString("<issueDate>{$today}</issueDate>", (string) $mark->request,
            'the serialized IssueDate must be today, not the stale draft date');
    }

    public function test_a_future_dated_draft_is_stamped_to_today_at_send(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['issued_at' => now()->addDay()])->save();

        (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh('lines'));

        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame(
            now()->setTimezone(ProviderIssueDateGuard::TZ)->toDateString(),
            $fresh->issued_at->setTimezone(ProviderIssueDateGuard::TZ)->toDateString(),
        );
    }

    public function test_an_already_today_issue_date_is_left_untouched(): void
    {
        // The stamp is skipped when the date is already today, so an already-today
        // document is not needlessly rewritten (no spurious audit entry / re-date).
        $invoice = $this->makeInvoice();
        $morning = now()->setTimezone(ProviderIssueDateGuard::TZ)->startOfDay()->addHours(8);
        $invoice->forceFill(['issued_at' => $morning])->save();

        (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh('lines'));

        $this->assertSame(
            $morning->getTimestamp(),
            $invoice->fresh()->issued_at->getTimestamp(),
            'an already-today issue date keeps its exact time',
        );
    }

    public function test_a_build_failure_after_assign_releases_the_reserved_number(): void
    {
        // Gapless-at-send P1: assign() reserves the real ΑΑ before the payload is built.
        // If the LOCAL build then throws (here: a type with no §8.1 classification), the
        // number must be RELEASED — the counter must not advance and the invoice reverts
        // to provisional — so a config error never burns a number (no gap).
        $badType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'BAD', 'name' => 'Χωρίς κλάση',
            'invcount' => 1, 'mydata_type' => null,
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $badType->id,
            'customer_id' => $this->customer->id, 'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        $invoice->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'x', 'qty' => 1,
            'price_per_item' => 100, 'vat_percent' => 24,
        ]);
        $this->assertNull($invoice->code, 'draft starts provisional');

        try {
            (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh('lines'));
            $this->fail('Expected the payload build to throw on an unclassified type.');
        } catch (\Throwable $e) {
            // build failed locally — nothing transmitted.
        }

        $this->assertNull($invoice->fresh()->code, 'reverted to provisional');
        $this->assertSame(1, $badType->fresh()->invcount, 'counter NOT advanced — number was released');
        $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)->count(), 'nothing filed');
    }

    public function test_provider_rejection_throws_and_records_forensic_row_without_filing(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'fail'));

        try {
            $submitter->submit($invoice);
            $this->fail('Expected MyDataRejected.');
        } catch (MyDataRejected $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }

        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_REJECTED')->count());
        $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
        // Mirror untouched — no fake filing.
        $this->assertNull($invoice->fresh()->mydata_state);
    }

    public function test_a_definitive_rejection_releases_the_reserved_number(): void
    {
        // Gapless-at-send: a provisional draft → submit reserves ΤΠΥ1 (counter→2) → the
        // provider DEFINITIVELY rejects → the number is returned to the pool (counter back
        // to 1, invoice reverts to provisional), so the fixed resubmit re-uses ΤΠΥ1 — no gap.
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id, 'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        $invoice->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Υπηρεσία', 'qty' => 1,
            'price_per_item' => 100, 'vat_percent' => 24,
        ]);
        $this->assertNull($invoice->code);

        try {
            (new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'fail')))->submit($invoice->fresh('lines'));
            $this->fail('Expected MyDataRejected.');
        } catch (MyDataRejected $e) {
            // rejected
        }

        $this->assertNull($invoice->fresh()->code, 'reverted to provisional on rejection');
        $this->assertSame(1, (int) $this->type->fresh()->invcount, 'number released — counter back to 1');
    }

    public function test_a_rejection_does_not_renumber_an_alread_y_numbered_invoice(): void
    {
        // Review finding 1 (P0): an invoice can reach the submitter ALREADY numbered — one
        // finalized at a mode='off' tenant then submitted after go-live, or a legacy import.
        // assign() no-ops (returns false), so a DEFINITIVE rejection must NOT release: the
        // ΑΑ was validly issued earlier and stripping/renumbering it corrupts a legal document.
        $invoice = $this->makeInvoice(7);              // a real, pre-existing ΑΑ (ΤΠΥ7)
        $this->type->forceFill(['invcount' => 12])->save();  // counter has long moved on

        try {
            (new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'fail')))->submit($invoice->fresh('lines'));
            $this->fail('Expected MyDataRejected.');
        } catch (MyDataRejected) {
            // rejected
        }

        $fresh = $invoice->fresh();
        $this->assertSame(7, (int) $fresh->code, 'the pre-existing ΑΑ is preserved, not stripped');
        $this->assertSame('TPY7', $fresh->invcode);
        $this->assertSame(12, (int) $this->type->fresh()->invcount, 'counter untouched');
    }

    public function test_refuses_to_refile_an_already_valid_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400099999999999'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed at myDATA/');

        (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice->fresh());
    }

    public function test_ambiguous_send_failure_adopts_existing_mark_via_status_check(): void
    {
        // §14.4: send() throws (timeout), but status-check finds a MARK → ADOPT it
        // instead of re-filing. The invoice ends VALID with the recovered MARK.
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'throw', status: 'ok'));

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('400000000000123', $mark->mark);
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
    }

    public function test_ambiguous_send_failure_with_nothing_to_adopt_throws(): void
    {
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'throw', status: 'fail'));

        $this->expectException(RuntimeException::class);

        try {
            $submitter->submit($invoice);
        } finally {
            $this->assertSame(0, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
            $this->assertNull($invoice->fresh()->mydata_state);
        }
    }

    public function test_status_check_is_not_consulted_when_send_succeeds(): void
    {
        // N1: a successful send must never call status() — guards against a future
        // refactor that always status-checks. The fake throws on status(); a clean
        // send='ok' must still succeed.
        $invoice = $this->makeInvoice();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'ok', status: 'throw'));

        $mark = $submitter->submit($invoice);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
    }

    public function test_persist_is_idempotent_on_invoice_and_mark(): void
    {
        // N2: if a PROVIDER_INSERT row for (invoice, mark) already exists (e.g. a
        // prior attempt persisted then the response was lost), filing the same MARK
        // again adopts the existing row — no duplicate audit, no second filing.
        $invoice = $this->makeInvoice();
        $existing = MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '400000000000123', // == FakeGrTransport's mark
            'mydata_action' => 'PROVIDER_INSERT',
            'provider_key' => 'fake',
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        $mark = (new GrProviderSubmitter($this->tenant, new FakeGrTransport))->submit($invoice);

        $this->assertSame($existing->id, $mark->id);
        $this->assertSame(1, MyDataMark::where('invoice_id', $invoice->id)->where('mydata_action', 'PROVIDER_INSERT')->count());
    }

    public function test_recovery_does_not_null_out_existing_qr_and_flags_adopted(): void
    {
        // M2: a lighter status response (mark only) must not wipe the QR; the
        // adopted row is flagged delivery_state='ADOPTED'.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['mydata_url' => 'https://existing/qr'])->save();
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport(send: 'throw', status: 'mark-only'));

        $mark = $submitter->submit($invoice);

        $this->assertSame('ADOPTED', $mark->delivery_state);
        $this->assertSame('https://existing/qr', $invoice->fresh()->mydata_url); // not nulled
    }

    public function test_cancel_records_provider_cancel_and_flips_state_for_a_9_3_delivery_note(): void
    {
        // Provider cancel is possible ONLY for 9.3 δελτία αποστολής (CancelDeliveryNote).
        // Seed a filed 9.3 directly — a 9.3 payload needs delivery-note fields the
        // plain makeInvoice() path doesn't set, and cancel() only needs the MARK.
        $dnType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'DAP', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'DAP1', 'code' => 1,
            'invoice_type_id' => $dnType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400000000000999', 'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'fake',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400000000000999'])->save();

        $cancel = (new GrProviderSubmitter($this->tenant, new FakeGrTransport))
            ->cancel($invoice->fresh(), 'λάθος διακίνηση');

        $this->assertSame('PROVIDER_CANCEL', $cancel->mydata_action);

        // MYD-023: distinct evidence, distinct columns. The old code wrote
        // `$result->cancellationMark ?? $mark`, which both overwrote the document's
        // MARK and — when the provider returned no cancellation mark — silently
        // relabelled the ISSUE mark as proof of the cancellation.
        $this->assertSame('400000000000999', $cancel->mark, 'the document that was cancelled');
        $this->assertSame('400000000000111', $cancel->cancellation_mark, 'the cancel act itself');
        $this->assertSame('CANCELLED', $invoice->fresh()->mydata_state);
        $this->assertSame('cancelled', $invoice->fresh()->local_status);
    }

    public function test_cancel_refuses_a_non_9_3_provider_invoice_pointing_to_a_credit_note(): void
    {
        // A filed 1.1/2.1 via a provider is NOT cancellable (CancelDeliveryNote →
        // [283]); the transport must never be called and the operator is pointed to
        // a credit note. Mirrors the UI gate (ViewInvoice::cancel_at_mydata).
        $invoice = $this->makeInvoice();   // type 1.1
        $submitter = new GrProviderSubmitter($this->tenant, new FakeGrTransport);
        $submitter->submit($invoice);      // files via fake → VALID + PROVIDER_INSERT

        try {
            $submitter->cancel($invoice->fresh(), 'λάθος ποσό');
            $this->fail('Expected a refusal for a non-9.3 provider invoice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('πιστωτικό', $e->getMessage());
        }

        // Nothing was cancelled: state unchanged, no PROVIDER_CANCEL row written.
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
        $this->assertSame(0, MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mydata_action', 'PROVIDER_CANCEL')
            ->count());
    }

    public function test_test_connection_delegates_to_transport_ping(): void
    {
        $this->assertTrue((new GrProviderSubmitter($this->tenant, new FakeGrTransport))->testConnection());
        $this->assertFalse((new GrProviderSubmitter($this->tenant, new FakeGrTransport(ping: false)))->testConnection());
    }

    private function makeInvoice(int $code = 1): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY'.$code,
            'code' => $code,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
        ]);
        $invoice->lines()->create([
            'company_id' => $this->tenant->id,
            'product_descr' => 'Υπηρεσία',
            'qty' => 1,
            'price_per_item' => 100,
            'vat_percent' => 24,
        ]);

        return $invoice->fresh('lines');
    }
}

/** Configurable fake transport — no network. */
class FakeGrTransport implements EInvoiceProviderTransport
{
    public function __construct(
        private readonly string $send = 'ok',
        private readonly string $status = 'ok',
        private readonly bool $ping = true,
    ) {}

    public function key(): string
    {
        return 'fake';
    }

    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return $this->behave($this->send);
    }

    public function sendDelivery(DeliveryNote $note, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return $this->behave($this->send);
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        // DIFFERENT from the issue MARK on purpose: when both were the same value
        // the test could not tell the two columns apart, so it could not have
        // caught the `?? $mark` fallback that relabelled one as the other.
        return ProviderResult::ok(cancellationMark: '400000000000111', raw: '<cancel/>');
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        return $this->behave($this->status);
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        return $this->ping;
    }

    private function behave(string $mode): ProviderResult
    {
        return match ($mode) {
            'throw' => throw new RuntimeException('simulated provider timeout'),
            'fail' => ProviderResult::failed(['[101] simulated validation error'], '<error/>'),
            // A lighter status response — only the MARK came back (no QR/auth).
            'mark-only' => ProviderResult::ok(mark: '400000000000123'),
            default => ProviderResult::ok(
                mark: '400000000000123',
                uid: 'UID-1',
                authenticationCode: 'AUTHCODE-XYZ',
                qrUrl: 'https://prov/qr',
                deliveryState: 'DELIVERED',
                raw: '<response/>',
            ),
        };
    }
}
