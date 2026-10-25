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
use App\Models\VatCategory;
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
 * plus the `mycs` block (recipient ΑΦΜ/ΔΟΥ/document type, γκρινιάρης, notes) and each line's recipient.
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

    /** The MARK goes back without a fingerprint: an issued document is final, whatever changed in MyCS since (owner). */
    public function test_the_mark_goes_back_to_mycs_as_issued(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $invoice = $this->invoiceFor($tenant, $pending);

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $invoice, '400001234567890');

        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SUCCEEDED, $pending->fresh()->whmcs_writeback_state);
        Http::assertSent(fn (Request $r) => $r->url() === self::ENDPOINT && $r['op'] === 'mark' && $r['invoice_id'] === 1
            && $r['mark'] === '400001234567890' && ! isset($r['fingerprint'])
            && $r['invcode'] === 'TPY5' && $r['state'] === 'active');
    }

    public function test_an_invoice_merged_in_mycs_since_it_was_read_is_shown_not_retried(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs(fn () => Http::response(['error' => 'invoice_changed', 'message' => 'Merged into invoice 7: its lines are there.'], 409));
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $pending = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $this->invoiceFor($tenant, $pending), '400001234567890');

        $pending->refresh();
        // Merged or split in MyCS: shown, not retried; the document stands as issued.
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_CHANGED, $pending->whmcs_writeback_state);
        $this->assertStringContainsString('ισχύει όπως εκδόθηκε', (string) $pending->whmcs_writeback_error);
        $this->assertStringContainsString('invoice_changed (Merged into invoice 7', (string) $pending->whmcs_writeback_error);
        $this->expectException(\RuntimeException::class);
        app(WhmcsWritebackService::class)->retryWriteback($pending);
    }

    /** Owner: archived or issued, an invoice never asks again — MyCS sends it again, the row stays as decided. */
    public function test_an_archived_or_issued_invoice_is_never_offered_again(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $archived = PendingWhmcsInvoice::where('whmcs_invoice_id', 2)->sole();
        $archived->update(['status' => PendingWhmcsInvoice::STATUS_REJECTED, 'rejected_reason' => 'Δεν τιμολογείται']);
        $issued = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $issued->update(['status' => PendingWhmcsInvoice::STATUS_FILED, 'mydata_mark' => '400001234567890']);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        $this->assertSame(2, PendingWhmcsInvoice::where('company_id', $tenant->id)->count(), 'no new row');
        $this->assertSame([PendingWhmcsInvoice::STATUS_REJECTED, 'Δεν τιμολογείται'], [$archived->fresh()->status, $archived->fresh()->rejected_reason]);
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $issued->fresh()->status);
    }

    /** Owner: as with WHMCS — ΑΦΜ and an invoice wanted → ΤΠΥ; no ΑΦΜ, or a receipt wanted → ΑΛΠ. */
    public function test_invoice_or_receipt_follows_the_same_rule_as_whmcs(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs();
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertTrue($row->ownLinesAreReceipt(), 'no ekdosi customer with that ΑΦΜ yet → ΑΛΠ');

        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'ΔΟΚΙΜΗ ΑΕ', 'afm' => '099999999']);
        $row->update(['customer_id' => $customer->id]);
        $this->assertFalse($row->fresh()->ownLinesAreReceipt(), 'ΑΦΜ + the contact wants an invoice → ΤΠΥ');

        $payload = $row->payload;
        $payload['mycs']['recipient']['document_type'] = 'receipt';
        $row->update(['payload' => $payload]);
        $this->assertTrue($row->fresh()->ownLinesAreReceipt(), 'the contact wants a receipt → ΑΛΠ');
    }

    /**
     * Owner: «γκρινιάρης» works as with WHMCS. MyCS's immediate invoicing (on the fixture's account) and a 24% invoice for
     * one recipient → whmcs:auto-issue files it with no operator; the invoice for two recipients is held for the split,
     * never issued alone.
     */
    public function test_immediate_invoicing_from_mycs_issues_by_itself(): void
    {
        $tenant = $this->tenant(['whmcs_auto_issue_immediate' => true]);
        VatCategory::create(['company_id' => $tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $pm->id]);
        $tenant->update(['whmcs_default_invoice_type_id' => $type->id]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'ΔΟΚΙΜΗ ΑΕ', 'afm' => '099999999', 'needs_immediate_invoice' => false]);
        $this->fakeMycs();

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();
        $this->assertTrue((bool) $customer->fresh()->needs_immediate_invoice, 'MyCS\'s checkbox reached the customer');
        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $issued = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $issued->status, (string) $issued->notes);
        $this->assertTrue($issued->wasAutoIssued());
        $invoice = Invoice::find($issued->invoice_id);
        $this->assertSame('ΤΠΥ1', $invoice->invcode);
        $this->assertEqualsWithDelta(124.0, (float) $invoice->gross_total, 0.001, '100 net + 24% VAT, as MyCS sent it');
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, PendingWhmcsInvoice::where('whmcs_invoice_id', 2)->sole()->status, 'two recipients: held for the split');
    }

    /** Final review: a client set to «ignore invoicing» after its invoice was staged is held, never auto-issued. */
    public function test_an_ignored_client_is_held_when_it_reaches_the_inbox(): void
    {
        $tenant = $this->tenant();
        $payload = collect($this->feed()['invoices'])->firstWhere('id', 1);
        $ingest = fn (array $p) => app(WhmcsInvoiceIngestor::class)->ingest($tenant, $p);
        $ingest($payload);
        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->sole();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);

        $payload['mycs']['ignore_invoicing'] = true;
        $ingest($payload);
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->fresh()->status);
        $this->assertStringContainsString('χωρίς τιμολόγηση', (string) $row->fresh()->notes);
    }

    /** Final review: a WHMCS mass-pay filed after the cut-over sends nothing to MyCS (one MARK per invoice there). */
    public function test_a_whmcs_mass_pay_sends_no_mark_to_mycs(): void
    {
        $tenant = $this->tenant();
        $pending = PendingWhmcsInvoice::create(['company_id' => $tenant->id, 'whmcs_invoice_id' => 900, 'match_reason' => 'test',
            'status' => PendingWhmcsInvoice::STATUS_FILED, 'payload' => ['invoiceid' => 900, 'ekdosi_consolidated_children' => [901, 902]]]);
        Http::fake();

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $this->invoiceFor($tenant, $pending), '400001234567890');

        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SKIPPED, $pending->fresh()->whmcs_writeback_state);
        Http::assertNothingSent();
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
        $this->assertIsArray($row->payload['mycs']);
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
        $this->assertSame(['mycs', true], [$same->source, $same->isFromMycs()]);
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
