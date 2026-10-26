<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Mycs\MycsReversalWatcher;
use App\Services\Whmcs\WhmcsWritebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * K2 of the MyCS Bridge (docs/bridges-connectors.md §12): the unpaid feed of «τιμολόγιο πριν την πληρωμή» from MyCS,
 * each document of a split back to MyCS, and the staff told when an issued sale is reversed in MyCS.
 */
class MycsBridgeK2Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-mycs-shared-secret-of-forty-characters';

    private const ENDPOINT = 'https://my.example.test/api/ekdosi';

    /** @return array<string, mixed> */
    private function invoicePayload(int $id = 1, string $status = 'Paid', int $userid = 100001): array
    {
        $payload = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/mycs/feed-invoices.json')), true)['invoices'])
            ->firstWhere('id', 1);
        $payload['id'] = $payload['invoiceid'] = $id;
        $payload['status'] = $status;
        $payload['userid'] = $userid;

        return $payload;
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'k2-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mycs_enabled' => true, 'mycs_url' => 'https://my.example.test', 'mycs_secret' => self::SECRET,
        ]);
    }

    private function document(Company $tenant, PendingWhmcsInvoice $pending, int $code = 5): Invoice
    {
        $type = InvoiceType::firstOrCreate(['company_id' => $tenant->id, 'code' => 'TPY'], ['name' => 'ΤΠΥ', 'invcount' => 5]);

        return Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'code' => $code, 'invcode' => 'TPY'.$code,
            'issued_at' => now(), 'local_status' => 'active', 'whmcs_pending_id' => $pending->id,
        ]);
    }

    public function test_the_unpaid_invoices_of_invoice_before_payment_customers_come_from_mycs_in_one_call(): void
    {
        $tenant = $this->tenant();
        Customer::create(['company_id' => $tenant->id, 'name' => 'Επί πιστώσει', 'afm' => '099999999', 'whmcs_client_id' => 100001, 'needs_invoice_before_payment' => true]);
        Customer::create(['company_id' => $tenant->id, 'name' => 'Άλλος', 'afm' => '088888888', 'whmcs_client_id' => 100002, 'needs_invoice_before_payment' => true]);
        Customer::create(['company_id' => $tenant->id, 'name' => 'Μετρητοίς', 'afm' => '077777777', 'whmcs_client_id' => 100003]);
        Http::fake([self::ENDPOINT => function (Request $request) {
            $body = json_decode($request->body(), true);

            return Http::response(['status' => 'ok', 'invoices' => (int) $body['offset'] === 0 ? [$this->invoicePayload(9, 'Unpaid')] : [], 'offset' => $body['offset'], 'count' => 1]);
        }, '*' => Http::response([], 500)]);

        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])->assertSuccessful();

        $row = PendingWhmcsInvoice::where('whmcs_invoice_id', 9)->sole();
        $this->assertSame(['mycs', true], [$row->source, $row->whmcsIsUnpaid()]);
        Http::assertSent(fn (Request $r) => $r['op'] === 'invoices' && $r['status'] === 'Unpaid' && $r['userids'] === [100001, 100002]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'whmcs'));
    }

    public function test_each_document_of_a_split_goes_back_to_mycs_on_its_own(): void
    {
        $tenant = $this->tenant();
        Http::fake([self::ENDPOINT => Http::response(['status' => 'ok', 'created' => true]), '*' => Http::response([], 500)]);
        $pending = PendingWhmcsInvoice::create(['company_id' => $tenant->id, 'whmcs_invoice_id' => 2, 'match_reason' => 'test',
            'status' => PendingWhmcsInvoice::STATUS_SPLIT, 'payload' => $this->invoicePayload(2)]);
        $first = $this->document($tenant, $pending, 5);
        $second = $this->document($tenant, $pending, 6);

        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($first, '400000000000005');
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($second, '400000000000006');
        $second->forceFill(['mydata_mark' => '400000000000006', 'mydata_state' => 'CANCELLED'])->save();
        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($second);

        $marks = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->where('op', 'mark')->values();
        $this->assertSame([['TPY5', '400000000000005', 'active'], ['TPY6', '400000000000006', 'active'], ['TPY6', '400000000000006', 'cancelled']],
            $marks->map(fn ($b) => [$b['invcode'], $b['mark'], $b['state']])->all());
        $this->assertSame(PendingWhmcsInvoice::STATUS_SPLIT, $pending->fresh()->status);
    }

    public function test_staff_are_belled_once_when_an_issued_sale_is_reversed_in_mycs(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $status = ['1' => 'Refunded', '2' => 'Paid', '3' => 'Unpaid'];
        Http::fake([self::ENDPOINT => function (Request $request) use (&$status) {
            $id = (int) json_decode($request->body(), true)['invoice_id'];

            return Http::response(['status' => 'ok', 'invoice' => $this->invoicePayload($id, $status[(string) $id] ?? 'Paid', $id === 4 ? 555 : 100001)]);
        }]);
        foreach ([1, 2, 3, 4] as $id) {
            $pending = PendingWhmcsInvoice::create(['company_id' => $tenant->id, 'whmcs_invoice_id' => $id, 'whmcs_userid' => 100001, 'match_reason' => 'test',
                'status' => PendingWhmcsInvoice::STATUS_FILED, 'filed_at' => now(), 'payload' => $this->invoicePayload($id)]);
            $this->document($tenant, $pending, 10 + $id);
        }
        $status['4'] = 'Refunded'; // of another client (userid 555): not this document's sale

        $this->assertSame(['checked' => 4, 'flagged' => 2], app(MycsReversalWatcher::class)->run($tenant, 10));
        $bells = DatabaseNotification::query()->where('data->title', MycsReversalWatcher::TITLE)->get();
        $this->assertCount(2, $bells);
        $this->assertStringContainsString('MyCS #1 έγινε επιστροφή χρημάτων', $bells->pluck('data.body')->implode(' | '));
        $this->assertStringContainsString('MyCS #3 ξαναέγινε απλήρωτο', $bells->pluck('data.body')->implode(' | '));

        app(MycsReversalWatcher::class)->run($tenant, 10);
        $this->assertSame(2, DatabaseNotification::query()->where('data->title', MycsReversalWatcher::TITLE)->count(), 'once each');
    }

    public function test_the_operator_sees_mycs_not_whmcs(): void
    {
        $tenant = $this->tenant();
        $row = PendingWhmcsInvoice::create(['company_id' => $tenant->id, 'whmcs_invoice_id' => 1, 'match_reason' => 'test',
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 'payload' => $this->invoicePayload()]);

        $this->assertSame('MyCS', $row->sourceLabel());
        $html = view('filament.whmcs-inbox.invoice-view', ['r' => $row])->render();
        $this->assertStringContainsString('Κατάσταση MyCS', $html);
        $this->assertStringNotContainsString('WHMCS', $html);
    }
}
