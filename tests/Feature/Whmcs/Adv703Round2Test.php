<?php

namespace Tests\Feature\Whmcs;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Mycs\MycsRowRefresher;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #703, round 2 (MycsRowRefresher and the paths around it). Each test fails on the PR as written.
 */
class Adv703Round2Test extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://my.example.test/api/ekdosi';

    /** @return array<string, mixed> */
    private function payload(int $id, ?string $requestedAt = null): array
    {
        $payload = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/mycs/feed-invoices.json')), true)['invoices'])
            ->firstWhere('id', 1);
        $payload['id'] = $payload['invoiceid'] = $id;
        $payload['third_party']['whmcs_invoice_id'] = $id;
        $payload['mycs']['requested_at'] = $requestedAt;
        $payload['mycs']['ignore_invoicing'] = false;

        return $payload;
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'adv703b-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mycs_enabled' => true, 'mycs_url' => 'https://my.example.test', 'mycs_secret' => 'a-mycs-shared-secret-of-forty-characters',
        ]);
    }

    /** An operator in the inbox of this company, and a document type. */
    private function operator(Company $tenant): InvoiceType
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($tenant->id);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        VatCategory::create(['company_id' => $tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'name' => 'Bank', 'due_days' => 0, 'is_active' => true]);

        return InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο', 'invcount' => 0, 'payment_method_id' => $pm->id]);
    }

    /** MyCS answering op=invoice with this payload; counts the reads. */
    private function fakeInvoice(array $payload, ?int &$reads = null): void
    {
        $reads = 0;
        Http::fake([self::ENDPOINT => function (Request $request) use ($payload, &$reads) {
            if ((json_decode($request->body(), true)['op'] ?? '') === 'invoice') {
                $reads++;
            }

            return Http::response(['status' => 'ok', 'invoice' => $payload]);
        }, '*' => Http::response([], 500)]);
    }

    /**
     * R2-1 (P1). «Διαχωρισμός σε προσχέδια» never reads MyCS again: only «Δημιουργία Παραστατικού» and auto-issue call
     * MycsRowRefresher. A multi-party MyCS row (MyCS always sends routing) is split into drafts from the stale snapshot,
     * even after staff withdrew their «Send to Ekdosi» and changed a line.
     * Scenario: a sent, multi-party row waits in review; in MyCS staff withdraw it and edit a line; the operator splits it.
     */
    public function test_the_split_reads_mycs_again_before_making_drafts(): void
    {
        $tenant = $this->tenant();
        $type = $this->operator($tenant);
        $reseller = Customer::create(['company_id' => $tenant->id, 'name' => 'Reseller', 'afm' => '099999999']);

        $payload = $this->payload(61, '2026-10-27 10:00:00');
        unset($payload['third_party']);
        $payload['subtotal'] = '200.00';
        $payload['tax'] = '48.00';
        $payload['total'] = '248.00';
        $second = $payload['items']['item'][0];
        $second['id'] = 2;
        $second['relid'] = 2;
        $second['description'] = 'Domain b.gr';
        $payload['items']['item'][] = $second;
        $row = PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'source' => PendingWhmcsInvoice::SOURCE_MYCS, 'whmcs_invoice_id' => 61, 'whmcs_userid' => 100001,
            'customer_id' => $reseller->id, 'payload' => $payload, 'match_reason' => PendingWhmcsInvoice::REASON_AFM,
            'third_party_state' => PendingWhmcsInvoice::TP_MULTI, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'third_party_resolution' => ['whmcs_invoice_id' => 61, 'whmcs_userid' => 100001, 'multi_party' => true, 'lines' => [
                ['item_id' => 1, 'routed' => true, 'is_receipt' => false, 'contact' => ['id' => 7, 'company_name' => 'Reseller', 'gr_vatno' => '099999999']],
                ['item_id' => 2, 'routed' => true, 'is_receipt' => false, 'contact' => ['id' => 5, 'company_name' => 'Haris', 'gr_vatno' => '081951154']],
            ]],
        ]);

        $now = $this->payload(61); // withdrawn, and a line changed
        $now['mycs']['fingerprint'] = 'changed-in-mycs';
        $this->fakeInvoice($now);

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('split_third_party', $row, data: ['invoice_type_id' => $type->id]);

        $this->assertSame(0, Invoice::count(), 'drafts made from a snapshot MyCS no longer has (request withdrawn, line changed)');
    }

    /**
     * R2-0 (P0). «Δημιουργία Παραστατικού» in the inbox never works for a MyCS row. The table eager-loads
     * `company:id,whmcs_custom_field_map`, so the record the action gets has a Company without mycs_enabled/url/secret;
     * MycsRowRefresher::check($r) calls forSource($r->company), which falls to the WHMCS bridge (not configured), throws,
     * and every MyCS row is refused with «Η MyCS δεν απάντησε … ξαναδοκίμασε σε λίγο», MyCS up and the invoice unchanged.
     * K3's tests call the refresher on a fully loaded row, never through the action.
     */
    public function test_create_draft_works_on_an_unchanged_mycs_row(): void
    {
        $tenant = $this->tenant();
        $type = $this->operator($tenant);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'ΔΟΚΙΜΗ ΑΕ', 'afm' => '099999999']);
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(60, '2026-10-27 10:00:00'))->row;
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status, 'precondition');
        $this->fakeInvoice($this->payload(60, '2026-10-27 10:00:00'), $reads); // MyCS up, nothing changed

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('create_draft', $row, data: ['customer_id' => $customer->id, 'invoice_type_id' => $type->id]);

        $this->assertSame(1, $reads, 'MyCS was never asked: the refresher failed before the request');
        $this->assertSame(1, Invoice::count(), 'no draft for an unchanged MyCS row');
    }

    /**
     * R2-2 (P1). The refresher compares the fingerprint, status, client, request and «ignore» only. The routing it asks
     * for (`with_routing`) is not in MyCS's fingerprint, so a line now going to another party (a related contact set on
     * the service since) reads as «unchanged»: the document goes to the old recipient, from the old resolution.
     */
    public function test_a_line_routed_to_another_party_in_mycs_is_a_change(): void
    {
        $tenant = $this->tenant();
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(62))->row;
        $this->assertSame(PendingWhmcsInvoice::TP_NONE, $row->third_party_state ?? PendingWhmcsInvoice::TP_NONE, 'precondition: own line');

        $now = $this->payload(62);
        $now['third_party']['lines'][0]['routed'] = true;
        $now['third_party']['lines'][0]['contact'] = ['id' => 5, 'company_name' => 'Haris', 'gr_vatno' => '081951154'];
        $now['third_party']['summary']['routed_lines'] = 1;
        $now['third_party']['summary']['unrouted_lines'] = 0;
        $this->fakeInvoice($now);

        $this->assertNotNull(app(MycsRowRefresher::class)->check($row), 'the line goes to another party in MyCS, read as unchanged');
    }

    /**
     * R2-3 (P1). The recipient (`mycs.recipient`: ΑΦΜ, the document the account wants) is not in the fingerprint either.
     * Staff correct the client's ΑΦΜ and set «Απόδειξη» in MyCS, then press «Send to Ekdosi»… on an invoice already
     * waiting: the refresher sees nothing, the customer match and receipt-vs-invoice choice stay the old ones, and a
     * Τιμολόγιο goes to AADE on the wrong ΑΦΜ (auto-issue chooses the type from the stale wantsInvoice).
     */
    public function test_a_corrected_recipient_in_mycs_is_a_change(): void
    {
        $tenant = $this->tenant();
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(63))->row;
        $this->assertTrue($row->wantsInvoice(), 'precondition');

        $now = $this->payload(63);
        $now['mycs']['recipient']['gr_vatno'] = '081951154';
        $now['mycs']['recipient']['document_type'] = 'receipt';
        $this->fakeInvoice($now);

        $this->assertNotNull(app(MycsRowRefresher::class)->check($row), 'another ΑΦΜ and a receipt in MyCS, read as unchanged');
        $this->assertFalse($row->fresh()->wantsInvoice(), 'the row still asks for a Τιμολόγιο');
    }

    /**
     * R2-4 (P2). «Άμεση τιμολόγηση» turned off in MyCS is not compared, and an «unchanged» row is not ingested again, so
     * the customer keeps needs_immediate_invoice and whmcs:auto-issue files it unattended right after the check.
     */
    public function test_immediate_invoicing_turned_off_in_mycs_stops_the_unattended_issue(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Γκρινιάρης', 'afm' => '099999999', 'needs_immediate_invoice' => true]);
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(64))->row;
        $this->assertSame($customer->id, $row->customer_id, 'precondition: matched by ΑΦΜ');

        $now = $this->payload(64);
        $now['mycs']['immediate_invoicing'] = false;
        $this->fakeInvoice($now);

        $why = app(MycsRowRefresher::class)->check($row);

        $this->assertTrue($why !== null || ! $customer->fresh()->needs_immediate_invoice,
            'MyCS says «not immediate»; the check passes and auto-issue files it');
    }

    /**
     * R2-5 (P2). MyCS answers a missing invoice with HTTP 404 `invoice_not_found` (its contract; K3's own test fake does
     * too), which postResolve throws on. The refresher's «δεν υπάρχει πια στη MyCS» branch (`$fresh === null`) is
     * unreachable: a deleted invoice is reported as «Η MyCS δεν απάντησε … ξαναδοκίμασε», never held, and auto-issue
     * retries it every run as an outage.
     */
    public function test_an_invoice_gone_from_mycs_is_held_as_gone_not_as_an_outage(): void
    {
        $tenant = $this->tenant();
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->payload(65))->row;
        Http::fake([self::ENDPOINT => Http::response(['error' => 'invoice_not_found'], 404), '*' => Http::response([], 500)]);

        $why = (string) app(MycsRowRefresher::class)->check($row);

        $this->assertStringContainsString('δεν υπάρχει πια', $why, 'a deleted invoice told as «MyCS did not answer»');
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->fresh()->status);
    }

    /**
     * R2-6 (P2). A row staged from WHMCS before the cut-over (no `mycs` block) and still in review is the same invoice
     * id in MyCS now (MyCS keeps WHMCS's ids), but the refresher skips it (`isFromMycs()` false): created or auto-issued
     * from the frozen WHMCS snapshot although MyCS has refunded it (so its paid feed never converts the row).
     */
    public function test_a_pre_cut_over_whmcs_row_of_a_mycs_company_is_read_again(): void
    {
        $tenant = $this->tenant();
        $whmcs = $this->payload(66);
        unset($whmcs['mycs']);
        $row = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $whmcs)->row;
        $this->assertSame(PendingWhmcsInvoice::SOURCE_WHMCS, $row->source, 'precondition');

        $now = $this->payload(66);
        $now['status'] = 'Refunded';
        $this->fakeInvoice($now);

        $why = app(MycsRowRefresher::class)->check($row);

        $this->assertNotNull($why, 'refunded in MyCS, the WHMCS snapshot goes on to a document');
    }
}
