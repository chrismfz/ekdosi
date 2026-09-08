<?php

namespace Tests\Feature\Billing;

use App\Contracts\BillingSource;
use App\Models\BillingConnection;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Billing\BillingSourceRegistry;
use App\Services\Billing\Sources\WhmcsBillingSource;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 0 (Bridges/Connectors): the seam — registry resolution, capabilities,
 * the multi-source registry table, and the per-document source stamp. No
 * behaviour change to the live WHMCS pipeline; these just prove the seam.
 */
class BillingConnectorsPhase0Test extends TestCase
{
    use RefreshDatabase;

    private function registry(): BillingSourceRegistry
    {
        return app(BillingSourceRegistry::class);
    }

    public function test_registry_resolves_whmcs_to_its_source(): void
    {
        $source = $this->registry()->for('whmcs');

        $this->assertInstanceOf(BillingSource::class, $source);
        $this->assertInstanceOf(WhmcsBillingSource::class, $source);
        $this->assertSame('whmcs', $source->key());
        $this->assertSame('WHMCS', $source->label());
    }

    public function test_whmcs_capabilities(): void
    {
        $caps = $this->registry()->for('whmcs')->capabilities();

        $this->assertSame('WHMCS #', $caps->externalIdLabel);
        $this->assertTrue($caps->supportsWriteBack);
        $this->assertTrue($caps->supportsThirdParty);
        $this->assertNotSame('', $caps->docNoun);
    }

    public function test_unknown_source_returns_null_not_throws(): void
    {
        $this->assertNull($this->registry()->for('woocommerce'));
        $this->assertNull($this->registry()->for(''));
    }

    public function test_keys_lists_configured_sources(): void
    {
        $this->assertContains('whmcs', $this->registry()->keys());
    }

    public function test_billing_connection_registry_is_multi_source_per_company(): void
    {
        $c = $this->company();

        // A company can hold several connections — even two of the same source
        // (two shops). No unique constraint blocks it.
        BillingConnection::create(['company_id' => $c->id, 'source' => 'whmcs', 'label' => 'Κύριο WHMCS']);
        BillingConnection::create(['company_id' => $c->id, 'source' => 'woocommerce', 'label' => 'Shop EU']);
        BillingConnection::create(['company_id' => $c->id, 'source' => 'woocommerce', 'label' => 'Shop US']);

        $this->assertSame(3, BillingConnection::where('company_id', $c->id)->count());
        $this->assertSame(2, BillingConnection::where('company_id', $c->id)->where('source', 'woocommerce')->count());
    }

    public function test_ensure_for_is_idempotent(): void
    {
        $c = $this->company();

        $a = BillingConnection::ensureFor($c, 'whmcs', 'WHMCS');
        $b = BillingConnection::ensureFor($c, 'whmcs', 'WHMCS (again)');

        $this->assertSame($a->id, $b->id);   // same row, not a duplicate
        $this->assertSame(1, BillingConnection::where('company_id', $c->id)->where('source', 'whmcs')->count());
        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_ingestor_stamps_source_whmcs(): void
    {
        Http::fake();   // third-party disabled → no bridge call expected
        $tenant = $this->company();
        Customer::create(['company_id' => $tenant->id, 'name' => 'Reseller', 'afm' => '700700700', 'whmcs_client_id' => 793]);

        $row = app(WhmcsInvoiceIngestor::class)
            ->ingest($tenant, ['invoiceid' => 4242, 'userid' => 793, 'total' => 10.0])
            ->row;

        $this->assertSame('whmcs', $row->source);
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'Bridge Co', 'slug' => 'bc-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_third_party_enabled' => false,
        ]);
    }
}
