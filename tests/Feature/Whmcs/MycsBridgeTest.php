<?php

namespace Tests\Feature\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use App\Services\Whmcs\WhmcsWritebackService;
use App\Support\Billing\MycsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «MyCS Bridge» (docs/bridges-connectors.md §12): MyCS as the inbox's source instead of WHMCS. The fixture is what
 * MyCS's `POST /api/ekdosi op=invoices` answers (made by MyCS's own code with test data): the plugin's feed shape,
 * plus the `mycs` block (recipient ΑΦΜ/ΔΟΥ/document type, γκρινιάρης, fingerprint) and each line's recipient.
 */
class MycsBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-mycs-shared-secret-of-forty-characters';

    private const ENDPOINT = 'https://my.example.test/api/ekdosi';

    /** @return array<string, mixed> */
    private function feed(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/mycs/feed-invoices.json')), true);
    }

    /** @param  array<string, mixed>  $attributes */
    private function tenant(array $attributes = []): Company
    {
        return Company::create($attributes + [
            'name' => 'MyIP', 'slug' => 'mycs-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            // WHMCS still configured, and on its bridge: MyCS must win, WHMCS never read.
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('w', 40),
            'whmcs_fetch_via_bridge' => true,
            'whmcs_third_party_enabled' => false,
            'mycs_enabled' => true,
            'mycs_url' => 'https://my.example.test/',
            'mycs_secret' => self::SECRET,
            'whmcs_invoice_min_date' => '2026-10-01',
        ]);
    }

    /** MyCS's endpoint, checking every request's signature as MyCS does. */
    private function fakeMycs(?callable $mark = null): void
    {
        $feed = $this->feed();
        Http::fake([self::ENDPOINT => function (Request $request) use ($feed, $mark) {
            if ($request->header('X-Webhook-Signature')[0] !== 'sha256='.hash_hmac('sha256', $request->body(), self::SECRET)) {
                return Http::response(['error' => 'invalid_signature'], 401);
            }
            $body = json_decode($request->body(), true);

            return match ($body['op'] ?? '') {
                'ping' => Http::response(['status' => 'ok', 'source' => 'mycs', 'ops' => ['ping', 'invoices', 'invoice', 'resolve', 'mark']]),
                'invoices' => Http::response((int) ($body['offset'] ?? 0) === 0 ? $feed : ['status' => 'ok', 'invoices' => [], 'offset' => $body['offset'], 'count' => 0]),
                'mark' => $mark !== null ? $mark($body) : Http::response(['status' => 'ok', 'created' => true]),
                default => Http::response(['error' => 'unknown_op'], 400),
            };
        }, '*' => Http::response(['error' => 'not WHMCS'], 500)]);
    }

    public function test_the_inbox_reads_mycs_and_never_whmcs(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        $this->assertSame(2, PendingWhmcsInvoice::where('company_id', $tenant->id)->where('source', 'mycs')->count());
        Http::assertSent(fn (Request $r) => $r->url() === self::ENDPOINT && $r['op'] === 'invoices'
            && $r['status'] === 'paid_unfiled' && $r['since'] === '2026-10-01');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'whmcs.example.test'));
    }

    public function test_the_fiscal_details_come_from_mycs_not_from_custom_fields(): void
    {
        $tenant = $this->tenant(['whmcs_custom_field_map' => ['vatno' => 13, 'griniaris' => 338]]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'ΔΟΚΙΜΗ ΑΕ', 'afm' => '099999999']);
        $this->fakeMycs();

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        $row = PendingWhmcsInvoice::where('company_id', $tenant->id)->where('whmcs_invoice_id', 1)->sole();
        $this->assertSame([$customer->id, 'afm'], [$row->customer_id, $row->match_reason]);
        $this->assertSame(['099999999', 'Α΄ ΑΘΗΝΩΝ', 'Υπηρεσίες'], [$row->whmcsAfm(), $row->whmcsTaxOffice(), $row->whmcsActivity()]);
        $this->assertTrue($row->wantsInvoice());
        $this->assertTrue($row->wantsImmediateInvoice());
        $this->assertTrue((bool) $customer->fresh()->needs_immediate_invoice, 'γκρινιάρης mirrored from MyCS');
    }

    public function test_each_lines_recipient_is_taken_without_the_third_party_switch(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        $multi = PendingWhmcsInvoice::where('company_id', $tenant->id)->where('whmcs_invoice_id', 2)->sole();
        $this->assertSame([PendingWhmcsInvoice::TP_MULTI, PendingWhmcsInvoice::STATUS_HELD], [$multi->third_party_state, $multi->status]);
        $this->assertSame(PendingWhmcsInvoice::TP_NONE, PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole()->third_party_state);
    }

    public function test_the_mark_goes_back_to_mycs_with_the_fingerprint_it_was_read_with(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $invoice = $this->invoiceFor($tenant, $pending);

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $invoice, '400001234567890');

        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SUCCEEDED, $pending->fresh()->whmcs_writeback_state);
        Http::assertSent(fn (Request $r) => $r->url() === self::ENDPOINT && $r['op'] === 'mark' && $r['invoice_id'] === 1
            && $r['mark'] === '400001234567890' && $r['fingerprint'] === '5c72d95ef728b708a65bf6e99e2f1339'
            && $r['invcode'] === 'TPY5' && $r['state'] === 'active');
    }

    public function test_an_invoice_changed_in_mycs_since_it_was_read_fails_the_write_back_visibly(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs(fn () => Http::response(['error' => 'invoice_changed', 'message' => 'Merged into invoice 7: its lines are there.'], 409));
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $this->invoiceFor($tenant, $pending), '400001234567890');

        $pending->refresh();
        // Not a retryable failure: the same fingerprint would get the same 409 (review of #701).
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_CHANGED, $pending->whmcs_writeback_state);
        $this->assertStringContainsString('Ακύρωσέ το', (string) $pending->whmcs_writeback_error);
        $this->assertStringContainsString('invoice_changed (Merged into invoice 7', (string) $pending->whmcs_writeback_error);
        $this->expectException(\RuntimeException::class);
        app(WhmcsWritebackService::class)->retryWriteback($pending);
    }

    /** Review 2 of #701: «Άλλαξε στη MyCS» has a way out — cancelled at AADE, the row opens again and is read anew. */
    public function test_a_changed_row_reopens_when_its_document_is_cancelled_and_is_read_again(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs(fn () => Http::response(['error' => 'invoice_changed', 'message' => 'Its lines changed since it was read.'], 409));
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $invoice = $this->invoiceFor($tenant, $pending);
        $pending->update(['status' => PendingWhmcsInvoice::STATUS_DRAFTED]);
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400001234567890');
        $pending->refresh();
        $this->assertSame([PendingWhmcsInvoice::STATUS_FILED, PendingWhmcsInvoice::WRITEBACK_CHANGED], [$pending->status, $pending->whmcs_writeback_state]);

        // The audit lock still holds for anything else on the filed row.
        try {
            $pending->fresh()->update(['payload' => ['tampered' => true]]);
            $this->fail('a filed row was changed');
        } catch (\LogicException) {
        }

        $invoice->forceFill(['mydata_mark' => '400001234567890', 'mydata_state' => 'CANCELLED'])->save();
        $marks = fn () => collect(Http::recorded())->filter(fn ($pair) => ($pair[0]['op'] ?? null) === 'mark')->count();
        $before = $marks();
        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->status);
        $this->assertNull($pending->invoice_id);
        $this->assertCount(1, $pending->reopen_history);
        $this->assertSame('400001234567890', $pending->reopen_history[0]['mydata_mark']);
        $this->assertSame($before, $marks(), 'MyCS never took that MARK: no cancellation goes there');

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->mycsFingerprint());
    }

    /**
     * Round 3 of #701: reopen → read again → reissue records the customer's payment ONCE. The receipt recorded on the
     * cancelled document moves to the new one; and until the invoice is read again, it can't be issued.
     */
    public function test_a_reissue_after_a_mycs_change_moves_the_payment_and_waits_for_the_new_read(): void
    {
        $tenant = $this->tenant();
        $feed = $this->feed();
        $one = collect($feed['invoices'])->firstWhere('id', 1);
        $changed = $one;
        $changed['mycs']['fingerprint'] = 'ffffffffffffffffffffffffffffffff';
        $changed['items']['item'][0]['description'] = 'Web hosting (changed in MyCS)';
        $state = ['read' => $one, 'mark' => 409];
        Http::fake([self::ENDPOINT => function (Request $request) use (&$state) {
            $body = json_decode($request->body(), true);

            return match ($body['op'] ?? '') {
                'invoices' => Http::response(['status' => 'ok', 'invoices' => (int) ($body['offset'] ?? 0) === 0 ? [$state['read']] : [], 'offset' => 0, 'count' => 1]),
                'invoice' => Http::response(['status' => 'unavailable'], 503),
                'mark' => $state['mark'] === 409
                    ? Http::response(['error' => 'invoice_changed', 'message' => 'Its lines changed since it was read.'], 409)
                    : Http::response(['status' => 'ok', 'created' => true]),
                default => Http::response(['error' => 'unknown_op'], 400),
            };
        }]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'ΔΟΚΙΜΗ ΑΕ', 'afm' => '099999999']);
        $cash = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 5]);
        $issue = function (int $code, PendingWhmcsInvoice $pending) use ($tenant, $customer, $cash, $type): Invoice {
            $pending->update(['status' => PendingWhmcsInvoice::STATUS_DRAFTED]);
            $invoice = Invoice::create([
                'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'code' => $code, 'invcode' => 'TPY'.$code,
                'issued_at' => now(), 'local_status' => 'active', 'whmcs_pending_id' => $pending->id, 'customer_id' => $customer->id,
                'payment_method_id' => $cash->id, 'net_total' => 100, 'gross_total' => 100,
            ]);
            app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '40000123456789'.$code);

            return $invoice;
        };

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $first = $issue(5, $pending);
        $this->assertSame(1, Payment::where('company_id', $tenant->id)->count(), 'the receipt of the paid MyCS invoice');
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_CHANGED, $pending->fresh()->whmcs_writeback_state);

        $first->forceFill(['mydata_mark' => '400001234567895', 'mydata_state' => 'CANCELLED'])->save();
        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($first);
        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->status);
        // MyCS didn't answer the immediate re-read: not issuable on the content MyCS refused.
        $this->assertTrue($pending->awaitsMycsReread());

        $state['read'] = $changed;
        $state['mark'] = 200;
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending->refresh();
        $this->assertFalse($pending->awaitsMycsReread());
        $second = $issue(6, $pending);

        $payments = Payment::where('company_id', $tenant->id)->get();
        $this->assertCount(1, $payments, 'the customer paid once');
        $this->assertSame($second->id, (int) $payments->sole()->invoice_id);
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SUCCEEDED, $pending->fresh()->whmcs_writeback_state);
    }

    public function test_a_cancellation_that_itself_gets_the_409_reopens_the_row(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs(fn () => Http::response(['error' => 'invoice_changed', 'message' => 'Merged.'], 409));
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $invoice = $this->invoiceFor($tenant, $pending);
        $pending->update(['status' => PendingWhmcsInvoice::STATUS_FILED, 'mydata_mark' => '400001234567890',
            'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_FAILED]); // its first write-back failed (MyCS down)
        $invoice->forceFill(['mydata_mark' => '400001234567890', 'mydata_state' => 'CANCELLED'])->save();

        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }

    public function test_notes_hold_a_row_once_not_on_every_fetch(): void
    {
        $tenant = $this->tenant();
        $payload = collect($this->feed()['invoices'])->firstWhere('id', 1);
        $payload['mycs']['adjustments'] = '-24.00';
        $ingest = fn (array $p) => app(WhmcsInvoiceIngestor::class)->ingest($tenant, $p);

        $ingest($payload);
        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $row->update(['status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]); // the operator checked it

        $ingest($payload);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->fresh()->status, 'the same notes: not held again');

        $payload['mycs']['adjustments'] = '-30.00';
        $ingest($payload);
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->fresh()->status, 'another amount: held again');
    }

    public function test_a_released_number_collision_is_not_held_again_and_its_mark_stays_home(): void
    {
        $tenant = $this->tenant();
        $mycs = collect($this->feed()['invoices'])->firstWhere('id', 1);
        $whmcs = $mycs;
        unset($whmcs['mycs'], $whmcs['third_party']);
        $whmcs['userid'] = 555;
        $ingest = fn (array $p) => app(WhmcsInvoiceIngestor::class)->ingest($tenant, $p);
        $ingest($whmcs);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'ΔΟΚΙΜΗ ΑΕ', 'afm' => '099999999', 'needs_immediate_invoice' => false]);

        $ingest($mycs);
        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame([PendingWhmcsInvoice::STATUS_HELD, true], [$row->status, $row->source_conflict]);
        $this->assertFalse((bool) $customer->fresh()->needs_immediate_invoice, 'nothing of the other payload mirrored');
        $row->update(['status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]); // released

        $ingest($mycs);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->fresh()->status);
        $this->assertSame('whmcs', $row->fresh()->source);

        Http::fake();
        app(WhmcsWritebackService::class)->pushMark($tenant, $row->fresh(), $this->invoiceFor($tenant, $row), '400001234567890');
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SKIPPED, $row->fresh()->whmcs_writeback_state);
        Http::assertNothingSent();
    }

    public function test_the_whmcs_unpaid_fetch_never_runs_for_a_mycs_company(): void
    {
        $tenant = $this->tenant(['whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret']);
        Customer::create(['company_id' => $tenant->id, 'name' => 'Επί πιστώσει', 'afm' => '099999999', 'whmcs_client_id' => 100001,
            'needs_invoice_before_payment' => true]);
        Http::fake(['*' => Http::response(['result' => 'success', 'invoices' => ['invoice' => []]])]);

        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])->assertExitCode(3);
        Http::assertNothingSent();
    }

    public function test_a_whmcs_payload_never_replaces_a_row_mycs_staged(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $whmcs = collect($this->feed()['invoices'])->firstWhere('id', 1);
        unset($whmcs['mycs'], $whmcs['third_party']);
        $whmcs['status'] = 'Unpaid';

        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $whmcs);

        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame(['mycs', 'Paid'], [$row->source, $row->payload['status']]);
        $this->assertNotNull($row->mycsFingerprint());
    }

    public function test_a_row_staged_from_whmcs_is_refreshed_by_mycs_unless_it_is_another_clients(): void
    {
        $tenant = $this->tenant();
        $mycs = collect($this->feed()['invoices'])->keyBy('id');
        $whmcs = $mycs[1];
        unset($whmcs['mycs'], $whmcs['third_party']);
        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $whmcs);
        $other = $mycs[2];
        unset($other['mycs'], $other['third_party']);
        $other['userid'] = 555; // WHMCS issued #2 to another client after the last import
        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $other);
        $this->assertSame('whmcs', PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole()->source);

        $this->fakeMycs();
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        $same = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame(['mycs', '5c72d95ef728b708a65bf6e99e2f1339'], [$same->source, $same->mycsFingerprint()]);
        $collision = PendingWhmcsInvoice::where('whmcs_invoice_id', 2)->sole();
        $this->assertSame([PendingWhmcsInvoice::STATUS_HELD, 'whmcs', 555], [$collision->status, $collision->source, (int) $collision->whmcs_userid]);
        $this->assertStringContainsString('για άλλον πελάτη', (string) $collision->hold_reason);
    }

    public function test_an_invoice_with_credit_or_debit_notes_in_mycs_is_held(): void
    {
        $tenant = $this->tenant();
        $payload = collect($this->feed()['invoices'])->firstWhere('id', 1);
        $payload['mycs']['adjustments'] = '-24.00';

        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $payload);

        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertStringContainsString('-24,00 €', (string) $row->notes);
    }

    public function test_the_payload_readers_keep_unknown_as_unknown(): void
    {
        $payload = collect($this->feed()['invoices'])->firstWhere('id', 1);
        unset($payload['mycs']['immediate_invoicing']);
        $payload['mycs']['recipient']['document_type'] = null;
        $this->assertNull(MycsPayload::immediate($payload));
        $this->assertNull(MycsPayload::wantsInvoice($payload));
        $this->assertNull(MycsPayload::role($payload, 'griniaris'));
        $payload['mycs']['recipient']['document_type'] = 'receipt';
        $this->assertFalse(MycsPayload::wantsInvoice($payload));
    }

    public function test_the_address_may_be_pasted_with_the_endpoint_on_it(): void
    {
        $this->assertSame(self::ENDPOINT, $this->tenant(['mycs_url' => 'https://my.example.test/api/ekdosi/'])->mycsBridgeUrl());
    }

    public function test_a_mycs_only_company_is_a_billing_source_for_its_jobs(): void
    {
        $tenant = $this->tenant(['whmcs_api_url' => null, 'whmcs_webhook_secret' => null, 'whmcs_fetch_via_bridge' => false]);

        $this->assertTrue($tenant->hasBillingSource());
        $this->assertFalse($tenant->hasWhmcsIntegration());
        $this->artisan('whmcs:retry-writebacks', ['--tenant' => $tenant->slug])
            ->doesntExpectOutputToContain('Nothing to do')
            ->assertSuccessful();
    }

    public function test_on_but_not_usable_it_never_falls_back_to_whmcs(): void
    {
        $tenant = $this->tenant(['mycs_url' => 'http://not-https.example.test']);

        $this->expectException(WhmcsNotConfigured::class);
        app(WhmcsBridgeClientFactory::class)->forSource($tenant);
    }

    public function test_ping_and_what_mycs_does_not_take_yet(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();

        $this->assertSame('mycs', app(WhmcsBridgeClientFactory::class)->forSource($tenant)->ping()['source']);
        // No payment goes to MyCS (its E3), nor to the old WHMCS.
        $this->assertNull(app(WhmcsPaymentPusherFactory::class)->for($tenant));
        // WHMCS's own plugin client is still WHMCS's (its legacy ops).
        $this->assertFalse(app(WhmcsBridgeClientFactory::class)->for($tenant)->isMycs());
    }

    public function test_a_late_whmcs_paid_push_is_refused_on_mycs(): void
    {
        $tenant = $this->tenant();
        $body = json_encode(['whmcs_invoice_id' => 33001]);

        $this->call('POST', "/webhooks/whmcs/{$tenant->slug}/invoice-paid", [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, str_repeat('w', 40)),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(409)->assertJsonPath('error', 'source_is_mycs');

        $this->assertSame(0, PendingWhmcsInvoice::where('company_id', $tenant->id)->count());
    }

    private function invoiceFor(Company $tenant, PendingWhmcsInvoice $pending): Invoice
    {
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 5]);

        return Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'code' => 5, 'invcode' => 'TPY5',
            'issued_at' => now(), 'local_status' => 'active', 'whmcs_pending_id' => $pending->id,
        ]);
    }
}
