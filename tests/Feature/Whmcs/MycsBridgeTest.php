<?php

namespace Tests\Feature\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use App\Services\Whmcs\WhmcsWritebackService;
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
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_FAILED, $pending->whmcs_writeback_state);
        $this->assertStringContainsString('invoice_changed (Merged into invoice 7', (string) $pending->whmcs_writeback_error);
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
