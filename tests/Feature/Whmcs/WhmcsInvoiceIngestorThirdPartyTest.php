<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-1b: third-party-invoicing resolution wired into the ingestor.
 *
 * The bridge's resolve.php is faked. Each test sets (or omits) the per-tenant
 * kill-switch to prove: off = today's behaviour (no HTTP), single = bill the
 * contact, multi = held, no-AFM = held, resolve-failure = graceful fallback.
 */
class WhmcsInvoiceIngestorThirdPartyTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(bool $enabled): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'ing-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('s', 40),
            'whmcs_third_party_enabled' => $enabled,
        ]);
    }

    /** A reseller customer linked to WHMCS client 793 (the matcher's fallback). */
    private function reseller(Company $tenant): Customer
    {
        return Customer::create([
            'company_id' => $tenant->id, 'name' => 'Chris (reseller)',
            'afm' => '700700700', 'whmcs_client_id' => 793,
        ]);
    }

    private function payload(): array
    {
        return ['invoiceid' => 1234, 'userid' => 793, 'total' => 10.0];
    }

    private function ingestor(): WhmcsInvoiceIngestor
    {
        return app(WhmcsInvoiceIngestor::class);
    }

    private function fakeResolve(array $lines): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'whmcs_invoice_id' => 1234, 'userid' => 793,
            'timologia_present' => true, 'lines' => $lines,
        ], 200)]);
    }

    private function routedLine(int $contactId, string $name, string $afm): array
    {
        return [
            'item_id' => $contactId, 'relid' => 100 + $contactId, 'type' => 'Domain',
            'service_type' => 'domain', 'description' => 'svc', 'routed' => true,
            'is_receipt' => false,
            'contact' => ['id' => $contactId, 'company_name' => $name, 'gr_vatno' => $afm],
        ];
    }

    private function unroutedLine(): array
    {
        return [
            'item_id' => 1, 'relid' => 0, 'type' => 'Hosting', 'service_type' => 'hosting',
            'description' => 'own', 'routed' => false, 'is_receipt' => false, 'contact' => null,
        ];
    }

    public function test_disabled_flag_makes_no_bridge_call_and_bills_the_client(): void
    {
        Http::fake();
        $tenant = $this->tenant(enabled: false);
        $reseller = $this->reseller($tenant);

        $row = $this->ingestor()->ingest($tenant, $this->payload())->row;

        Http::assertNothingSent();
        $this->assertNull($row->third_party_state);
        $this->assertSame($reseller->id, $row->customer_id);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
    }

    public function test_single_third_party_bills_the_contact(): void
    {
        $tenant = $this->tenant(enabled: true);
        $this->reseller($tenant);
        $this->fakeResolve([
            $this->routedLine(5, 'Haris', '081951154'),
            $this->routedLine(5, 'Haris', '081951154'),
        ]);

        $row = $this->ingestor()->ingest($tenant, $this->payload())->row;

        $this->assertSame(PendingWhmcsInvoice::TP_SINGLE, $row->third_party_state);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);

        $haris = Customer::where('company_id', $tenant->id)->where('afm', '081951154')->first();
        $this->assertNotNull($haris, 'the end customer was created');
        $this->assertSame($haris->id, $row->customer_id, 'invoice bills Haris, not Chris');
        $this->assertTrue($row->third_party_resolution['multi_party'] === false);
    }

    public function test_multi_party_is_held_for_operator_split(): void
    {
        $tenant = $this->tenant(enabled: true);
        $reseller = $this->reseller($tenant);
        $this->fakeResolve([
            $this->routedLine(5, 'Haris', '081951154'),
            $this->routedLine(6, 'Maria', '062062062'),
        ]);

        $row = $this->ingestor()->ingest($tenant, $this->payload())->row;

        $this->assertSame(PendingWhmcsInvoice::TP_MULTI, $row->third_party_state);
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertStringContainsString('διαχωρισμό', (string) $row->notes);
        // Held rows keep the reseller as the reference customer.
        $this->assertSame($reseller->id, $row->customer_id);
    }

    public function test_single_contact_without_afm_is_held(): void
    {
        $tenant = $this->tenant(enabled: true);
        $this->reseller($tenant);
        $this->fakeResolve([$this->routedLine(5, 'No-AFM Ltd', '')]);

        $row = $this->ingestor()->ingest($tenant, $this->payload())->row;

        $this->assertSame(PendingWhmcsInvoice::TP_SINGLE, $row->third_party_state);
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertStringContainsString('ΑΦΜ', (string) $row->notes);
    }

    public function test_no_routing_bills_client_with_none_state(): void
    {
        $tenant = $this->tenant(enabled: true);
        $reseller = $this->reseller($tenant);
        $this->fakeResolve([$this->unroutedLine(), $this->unroutedLine()]);

        $row = $this->ingestor()->ingest($tenant, $this->payload())->row;

        $this->assertSame(PendingWhmcsInvoice::TP_NONE, $row->third_party_state);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
        $this->assertSame($reseller->id, $row->customer_id);
    }

    public function test_resolve_failure_degrades_gracefully(): void
    {
        // resolve.php not deployed yet → 404. Ingestion must still work and
        // bill the client exactly as before the feature existed.
        Http::fake([self::RESOLVE_URL => Http::response(['error' => 'whmcs_init_not_found'], 500)]);
        $tenant = $this->tenant(enabled: true);
        $reseller = $this->reseller($tenant);

        $row = $this->ingestor()->ingest($tenant, $this->payload())->row;

        $this->assertNull($row->third_party_state);
        $this->assertSame($reseller->id, $row->customer_id);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
    }
}
