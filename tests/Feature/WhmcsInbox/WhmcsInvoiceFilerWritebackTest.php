<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Stage B-3: WHMCS write-back path on top of the Stage B-2 filer.
 *
 * Tenant set up here runs in SANDBOX (not Off) mode so a real
 * MyDataMark is created — but the EInvoiceSubmitter never actually
 * hits the AADE network (the mydata_subscription_key is empty;
 * the submitter factory returns a fake submitter for invalid
 * config and tests assert mark values via direct seeding instead).
 *
 * Actually simpler: we keep tenant in Off mode but inject a custom
 * submitter that returns a fake MARK. But that requires plumbing
 * we don't have. Even simpler approach: stay in Off-mode and assert
 * that the writeback is correctly SKIPPED there — then have one
 * dedicated test that uses a stub submitter to exercise the writeback
 * with a real MARK.
 *
 * To keep the test scope minimal, the writeback tests directly
 * exercise the writebackInvoicedFlag path by:
 *   - Verifying off-mode → no HTTP call (writeback gated by $hasMark)
 *   - Verifying configured + sandbox-mode + fake-MARK → HTTP call fires
 *   - Verifying configured + bridge unreachable → exception logged,
 *     notes appended, no throw to caller
 *   - Verifying missing webhook_secret → silent skip with Log::info
 */
class WhmcsInvoiceFilerWritebackTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private Customer $customer;
    private InvoiceType $invoiceType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name'              => 'T',
            'slug'              => 'wb-'.uniqid(),
            'country_code'      => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode'       => 'off',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id,
            'name'       => '24%',
            'rate'       => 24.00,
            'is_default' => true,
        ]);
        $pm = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'name'       => 'Cash',
            'due_days'   => 0,
            'is_active'  => true,
        ]);
        $this->invoiceType = InvoiceType::create([
            'company_id'        => $this->tenant->id,
            'name'              => 'ΤΠΥ',
            'code'              => 'ΤΠΥ',
            'invcount'          => 1,
            'payment_method_id' => $pm->id,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name'       => 'ΑΚΜΕ',
            'afm'        => '111111111',
        ]);
    }

    private function makePending(): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id'       => $this->tenant->id,
            'whmcs_invoice_id' => 8888,
            'payload'          => [
                'invoiceid' => 8888,
                'userid'    => 1,
                'date'      => '2026-05-20',
                'total'     => '124.00',
                'items'     => ['item' => [['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]],
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status'       => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id'  => $this->customer->id,
        ]);
    }

    public function test_off_mode_tenant_does_not_make_any_outbound_bridge_call(): void
    {
        // Sanity: Off-mode skips the writeback entirely because there's
        // no MARK to push. Http::fake() with no expectations would
        // accept ANY outbound call — so we use preventStrayRequests()
        // to assert NO HTTP traffic leaves the test.
        Http::preventStrayRequests();

        $this->tenant->update([
            'whmcs_api_url'         => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret'  => str_repeat('a', 64),
        ]);

        $pending = $this->makePending();
        app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        // No assertion needed: preventStrayRequests would have thrown.
        $this->assertTrue(true);
    }

    public function test_writeback_skipped_when_tenant_has_no_bridge_secret(): void
    {
        // Sandbox-mode tenant but no whmcs_webhook_secret configured:
        // bridge factory throws WhmcsNotConfigured → filer catches +
        // logs at info level → returns. No outbound call.
        Http::preventStrayRequests();
        Log::spy();

        $this->tenant->update([
            'mydata_mode'   => 'sandbox',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            // whmcs_webhook_secret intentionally unset
        ]);
        $this->seedFakeSubmitterReturningMark('999000111');

        $pending = $this->makePending();
        app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        Log::shouldHaveReceived('info')->withArgs(
            fn ($msg, $ctx) => str_contains($msg, 'bridge plugin not configured')
                && $ctx['mydata_mark'] === '999000111'
        )->once();
    }

    public function test_writeback_fires_when_configured_and_mark_present(): void
    {
        Http::fake([
            'https://whmcs.example.com/modules/addons/ekdosi_bridge/inbound.php' => Http::response([
                'status'           => 'ok',
                'whmcs_invoice_id' => 8888,
                'invoiced'         => 999000111,
            ], 200),
        ]);

        $this->tenant->update([
            'mydata_mode'          => 'sandbox',
            'whmcs_api_url'        => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('a', 64),
        ]);
        $this->seedFakeSubmitterReturningMark('999000111');

        $pending = $this->makePending();
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        Http::assertSent(function ($request) {
            // URL is the derived bridge path
            if ($request->url() !== 'https://whmcs.example.com/modules/addons/ekdosi_bridge/inbound.php') {
                return false;
            }
            // Body carries whmcs_invoice_id + mark
            $body = json_decode($request->body(), true);
            if (($body['whmcs_invoice_id'] ?? null) !== 8888) {
                return false;
            }
            if (($body['mark'] ?? null) !== '999000111') {
                return false;
            }
            // HMAC-signed: X-Webhook-Signature header is present and
            // matches the body hash with the tenant's secret.
            $sigHeader = $request->header('X-Webhook-Signature')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), str_repeat('a', 64));
            return $sigHeader === $expected;
        });

        // Structured writeback state reflects success.
        $this->assertSame(
            PendingWhmcsInvoice::WRITEBACK_SUCCEEDED,
            $result->pending->fresh()->whmcs_writeback_state
        );
        $this->assertNull($result->pending->fresh()->whmcs_writeback_error);
    }

    public function test_writeback_skipped_records_skipped_state(): void
    {
        // Sandbox-mode, MARK present, but no bridge secret → the
        // writeback factory throws WhmcsNotConfigured. The filing
        // still completes; the writeback state is 'skipped' (NOT
        // 'failed' — distinguishable in dashboards / retry sweeps).
        Http::preventStrayRequests();

        $this->tenant->update([
            'mydata_mode'   => 'sandbox',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            // no whmcs_webhook_secret
        ]);
        $this->seedFakeSubmitterReturningMark('999000333');

        $pending = $this->makePending();
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $fresh = $result->pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SKIPPED, $fresh->whmcs_writeback_state);
    }

    public function test_writeback_failure_does_not_throw_or_undo_filing(): void
    {
        // Bridge endpoint returns 500 → WhmcsApiException. The AADE
        // filing has already happened (status=filed committed in Phase
        // 3 BEFORE the writeback); the filer must NOT propagate the
        // bridge failure as a thrown exception, since that would
        // surface to the operator as "the filing failed" when in fact
        // AADE accepted it. Instead: record state=failed + the error.
        Http::fake([
            'https://whmcs.example.com/modules/addons/ekdosi_bridge/inbound.php' => Http::response([
                'error' => 'database update failed',
            ], 500),
        ]);
        Log::spy();

        $this->tenant->update([
            'mydata_mode'          => 'sandbox',
            'whmcs_api_url'        => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('a', 64),
        ]);
        $this->seedFakeSubmitterReturningMark('999000222');

        $pending = $this->makePending();
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        // Filing succeeded from caller's perspective.
        $fresh = $result->pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
        $this->assertSame('999000222', $fresh->mydata_mark);
        // Structured writeback columns carry the failure + diagnostic.
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_FAILED, $fresh->whmcs_writeback_state);
        $this->assertNotNull($fresh->whmcs_writeback_error);
        // Error logged.
        Log::shouldHaveReceived('error')->withArgs(
            fn ($msg) => str_contains($msg, 'WHMCS write-back failed')
        )->atLeast()->once();
    }

    public function test_writeback_unexpected_throwable_is_non_fatal(): void
    {
        // A non-WHMCS exception (e.g. the HTTP layer throwing a raw
        // RuntimeException, or a JsonException) must ALSO be non-fatal:
        // AADE already filed, so the filing must stand. The catch is
        // \Throwable, not just the two WHMCS exception classes.
        // Simulate by binding a bridge factory whose client throws a
        // bare RuntimeException.
        Log::spy();
        $this->tenant->update([
            'mydata_mode'          => 'sandbox',
            'whmcs_api_url'        => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('a', 64),
        ]);
        $this->seedFakeSubmitterReturningMark('999000444');

        $bridgeFactory = \Mockery::mock(\App\Services\Whmcs\WhmcsBridgeClientFactory::class);
        $bridgeClient = \Mockery::mock(\App\Services\Whmcs\WhmcsBridgeClient::class);
        $bridgeClient->shouldReceive('setInvoiced')
            ->andThrow(new \RuntimeException('totally unexpected'));
        $bridgeFactory->shouldReceive('for')->andReturn($bridgeClient);
        $this->app->instance(\App\Services\Whmcs\WhmcsBridgeClientFactory::class, $bridgeFactory);

        $pending = $this->makePending();
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $fresh = $result->pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_FAILED, $fresh->whmcs_writeback_state);
        $this->assertStringContainsString('totally unexpected', (string) $fresh->whmcs_writeback_error);
    }

    public function test_trailing_slash_in_api_url_still_derives_bridge_url(): void
    {
        // A4 regression: a trailing slash on whmcs_api_url must NOT
        // silently disable the bridge. The writeback should still
        // fire against the derived bridge endpoint.
        Http::fake([
            'https://whmcs.example.com/modules/addons/ekdosi_bridge/inbound.php' => Http::response([
                'status' => 'ok',
            ], 200),
        ]);

        $this->tenant->update([
            'mydata_mode'          => 'sandbox',
            'whmcs_api_url'        => 'https://whmcs.example.com/includes/api.php/',
            'whmcs_webhook_secret' => str_repeat('a', 64),
        ]);
        $this->seedFakeSubmitterReturningMark('999000555');

        $pending = $this->makePending();
        $result = app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        Http::assertSent(fn ($request) => $request->url()
            === 'https://whmcs.example.com/modules/addons/ekdosi_bridge/inbound.php');
        $this->assertSame(
            PendingWhmcsInvoice::WRITEBACK_SUCCEEDED,
            $result->pending->fresh()->whmcs_writeback_state
        );
    }

    /**
     * Test helper: bind a stub e-invoice submitter that returns a
     * MyDataMark with the given MARK value, so the writeback path
     * is exercised without an actual AADE call.
     */
    private function seedFakeSubmitterReturningMark(string $mark): void
    {
        $factory = \Mockery::mock(\App\Services\EInvoiceSubmitterFactory::class);
        $submitter = \Mockery::mock(\App\Contracts\EInvoiceSubmitter::class);
        $submitter->shouldReceive('submit')->andReturnUsing(function ($invoice) use ($mark) {
            return \App\Models\MyDataMark::create([
                'company_id'    => $invoice->company_id,
                'invoice_id'    => $invoice->id,
                'mydata_action' => 'INSERT',
                'mark'          => $mark,
                'mark_date'     => now()->toDateString(),
                'mark_time'     => now()->format('H:i:s'),
                'request'       => '<dryrun/>',
                'response'      => '<dryrun mark="'.$mark.'"/>',
            ]);
        });
        $factory->shouldReceive('for')->andReturn($submitter);
        $this->app->instance(\App\Services\EInvoiceSubmitterFactory::class, $factory);
    }
}
