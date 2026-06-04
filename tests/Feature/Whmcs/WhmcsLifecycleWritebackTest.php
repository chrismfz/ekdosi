<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsWritebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the draft-first LIFECYCLE write-back (the gap that direct file()
 * already handled but lifecycle-issued drafts did not): when a draft carrying
 * whmcs_pending_id reaches myDATA VALID, the linked pending row must flip
 * drafted→filed and the MARK must be pushed back to WHMCS.
 */
class WhmcsLifecycleWritebackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    private function tenant(bool $withBridge = true): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'whmcs_api_url' => $withBridge ? 'https://whmcs.example.gr/includes/api.php' : null,
            'whmcs_webhook_secret' => $withBridge ? self::SECRET : null,
        ]);
    }

    private function draftInvoiceLinkedToPending(Company $tenant, PendingWhmcsInvoice $pending): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 5,
        ]);

        return Invoice::create([
            'company_id' => $tenant->id,
            'invoice_type_id' => $type->id,
            'code' => 5,
            'invcode' => 'TPY5',
            'issued_at' => now(),
            'local_status' => 'active',
            'whmcs_pending_id' => $pending->id,
        ]);
    }

    private function pending(Company $tenant, string $status): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => 4242,
            'status' => $status,
            'match_reason' => 'test',
            'payload' => ['whmcs_invoice_id' => 4242],
        ]);
    }

    public function test_lifecycle_valid_flips_drafted_to_filed_and_pushes_mark(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_DRAFTED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400001234567890');

        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $pending->status);
        $this->assertSame('400001234567890', $pending->mydata_mark);
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SUCCEEDED, $pending->whmcs_writeback_state);
        $this->assertNotNull($pending->filed_at);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'inbound.php')
            && $req['whmcs_invoice_id'] === 4242
            && $req['mark'] === '400001234567890');
    }

    public function test_lifecycle_valid_pushes_active_state(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_DRAFTED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400001234567890');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'inbound.php')
            && ($req['state'] ?? null) === 'active');
    }

    public function test_cancel_repushes_same_mark_with_cancelled_state(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_FILED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);
        $invoice->forceFill(['mydata_mark' => '400001234567890'])->save();

        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'inbound.php')
            && $req['whmcs_invoice_id'] === 4242
            && $req['mark'] === '400001234567890'
            && ($req['state'] ?? null) === 'cancelled');
    }

    public function test_cancel_no_op_when_invoice_never_filed(): void
    {
        Http::fake();
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_FILED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);   // no mydata_mark

        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        Http::assertNothingSent();
    }

    public function test_cancel_skips_split_rows(): void
    {
        Http::fake();
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_SPLIT);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);
        $invoice->forceFill(['mydata_mark' => '400000000000009'])->save();

        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        Http::assertNothingSent();
    }

    public function test_no_op_for_non_whmcs_invoice(): void
    {
        Http::fake();
        $tenant = $this->tenant();
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'X', 'name' => 'X', 'invcount' => 1]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id,
            'code' => 1, 'invcode' => 'X1', 'issued_at' => now(), 'local_status' => 'active',
            // no whmcs_pending_id
        ]);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400000000000001');

        Http::assertNothingSent();
    }

    public function test_no_op_without_mark_offmode(): void
    {
        Http::fake();
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_DRAFTED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, null);

        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_DRAFTED, $pending->status);
        Http::assertNothingSent();
    }

    public function test_skips_split_rows(): void
    {
        Http::fake();
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_SPLIT);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400000000000002');

        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_SPLIT, $pending->status);
        Http::assertNothingSent();
    }

    public function test_records_skipped_when_bridge_not_configured(): void
    {
        Http::fake();
        $tenant = $this->tenant(withBridge: false);
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_DRAFTED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400000000000003');

        $pending->refresh();
        // Row still flips to filed (the AADE truth), write-back marked skipped.
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $pending->status);
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SKIPPED, $pending->whmcs_writeback_state);
        Http::assertNothingSent();
    }

    public function test_records_failed_when_bridge_rejects(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_signature'], 401)]);
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, PendingWhmcsInvoice::STATUS_DRAFTED);
        $invoice = $this->draftInvoiceLinkedToPending($tenant, $pending);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, '400000000000004');

        $pending->refresh();
        // Filing is the legal truth → still filed; write-back failure captured.
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $pending->status);
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_FAILED, $pending->whmcs_writeback_state);
        $this->assertNotNull($pending->whmcs_writeback_error);
    }
}
