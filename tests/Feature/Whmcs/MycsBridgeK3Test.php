<?php

namespace Tests\Feature\Whmcs;

use App\Console\Commands\WhmcsAutoIssue;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Support\Billing\MycsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * K3 of the MyCS Bridge (docs/bridges-connectors.md §12): what MyCS staff sent with «Send to Ekdosi» (MyCS E5), read
 * from the feed's `requested` when MyCS has it, staged for a human (an unpaid one on credit), never issued unattended.
 */
class MycsBridgeK3Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-mycs-shared-secret-of-forty-characters';

    private const ENDPOINT = 'https://my.example.test/api/ekdosi';

    /** @return array<string, mixed> */
    private function payload(int $id, string $status = 'Paid', ?string $requestedAt = null, bool $ignored = false): array
    {
        $payload = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/mycs/feed-invoices.json')), true)['invoices'])
            ->firstWhere('id', 1);
        $payload['id'] = $payload['invoiceid'] = $id;
        $payload['status'] = $status;
        $payload['mycs']['requested_at'] = $requestedAt;
        $payload['mycs']['ignore_invoicing'] = $ignored;

        return $payload;
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'k3-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mycs_enabled' => true, 'mycs_url' => 'https://my.example.test', 'mycs_secret' => self::SECRET,
        ]);
    }

    /** MyCS answering: ping with or without `requested`, a page per status. */
    private function fakeMycs(array $paid, array $requested, bool $hasRequested = true): void
    {
        Http::fake([self::ENDPOINT => function (Request $request) use ($paid, $requested, $hasRequested) {
            $body = json_decode($request->body(), true);
            if ($body['op'] === 'ping') {
                return Http::response(['status' => 'ok', 'source' => 'mycs'] + ($hasRequested ? ['statuses' => ['paid_unfiled', 'requested']] : []));
            }
            $page = (int) $body['offset'] === 0 ? ($body['status'] === 'requested' ? $requested : $paid) : [];

            return Http::response(['status' => 'ok', 'invoices' => $page, 'offset' => $body['offset'], 'count' => count($page)]);
        }, '*' => Http::response([], 500)]);
    }

    public function test_what_mycs_staff_sent_comes_in_with_the_paid_ones(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs([$this->payload(1)], [$this->payload(2, 'Unpaid', '2026-10-27 10:00:00', ignored: true)]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        $sent = PendingWhmcsInvoice::where('whmcs_invoice_id', 2)->sole();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $sent->status, 'staff asked for it: not held as «ignored»');
        $this->assertTrue($sent->whmcsIsUnpaid(), 'unpaid: issued on credit, by a human');
        $this->assertStringContainsString('Send to Ekdosi', (string) $sent->notes);
        $this->assertTrue(PendingWhmcsInvoice::where('whmcs_invoice_id', 1)->exists());
        Http::assertSent(fn (Request $r) => $r['op'] === 'invoices' && $r['status'] === 'requested' && ! isset($r['since']));
    }

    public function test_an_older_mycs_without_requested_is_not_asked_for_it(): void
    {
        $tenant = $this->tenant();
        $this->fakeMycs([$this->payload(1)], [$this->payload(2, 'Unpaid', '2026-10-27 10:00:00')], hasRequested: false);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => ($r['status'] ?? null) === 'requested');
        $this->assertFalse(PendingWhmcsInvoice::where('whmcs_invoice_id', 2)->exists());
    }

    public function test_a_new_request_brings_back_an_archived_row_once(): void
    {
        $tenant = $this->tenant();
        $ingestor = app(WhmcsInvoiceIngestor::class);
        $row = $ingestor->ingest($tenant, $this->payload(3))->row;
        $row->update(['status' => PendingWhmcsInvoice::STATUS_REJECTED, 'rejected_reason' => 'not now']);

        $ingestor->ingest($tenant, $this->payload(3, 'Paid', '2026-10-27 10:00:00'));
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->fresh()->status, 'staff asked again');

        $row->fresh()->update(['status' => PendingWhmcsInvoice::STATUS_REJECTED, 'rejected_reason' => 'still not']);
        $ingestor->ingest($tenant, $this->payload(3, 'Paid', '2026-10-27 10:00:00'));
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $row->fresh()->status, 'the same request does not nag again');
    }

    public function test_a_sent_invoice_is_never_issued_unattended(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Γκρινιάρης', 'afm' => '099999999', 'needs_immediate_invoice' => true]);
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(4, 'Paid', '2026-10-27 10:00:00'))->row;
        $row->update(['customer_id' => $customer->id, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);

        $candidates = (new ReflectionMethod(WhmcsAutoIssue::class, 'candidates'))->invoke(app(WhmcsAutoIssue::class), $tenant);

        $this->assertTrue(MycsPayload::requested($row->fresh()->payload));
        $this->assertFalse(collect($candidates)->contains('id', $row->id));
    }
}
