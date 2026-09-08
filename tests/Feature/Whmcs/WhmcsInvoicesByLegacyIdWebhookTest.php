<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /webhooks/whmcs/{slug}/invoices-by-legacy-id — deterministic HISTORICAL
 * lookup keyed by legacy id (tblinvoices.invoiced === invoices.legacy_id).
 * Lights up imported invoices with their ΤΠΥ + ΜΑΡΚ, no re-import.
 */
class WhmcsInvoicesByLegacyIdWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'legacy-id-secret-not-for-prod-xxxxx';

    private function tenant(?string $slug = null): Company
    {
        return Company::create([
            'name' => 'LegacyIdTenant',
            'slug' => $slug ?? 'lid-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    private function invoice(Company $t, int $legacyId, string $invcode, int $code, ?int $whmcsId = null): Invoice
    {
        $it = InvoiceType::firstOrCreate(
            ['company_id' => $t->id, 'code' => 'ΤΠΥ'],
            ['name' => 'ΤΠΥ', 'invcount' => 1],
        );
        $inv = Invoice::create([
            'company_id' => $t->id, 'invoice_type_id' => $it->id,
            'legacy_id' => $legacyId, 'invcode' => $invcode, 'code' => $code, 'issued_at' => now(),
        ]);
        $inv->forceFill([
            'local_status' => 'active', 'mydata_state' => 'VALID', 'mydata_mark' => '4000137'.$code,
            'whmcs_invoice_id' => $whmcsId,
        ])->save();

        return $inv;
    }

    /** @param array<string, mixed> $body */
    private function call_(string $slug, array $body, string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($body);
        $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call(
            'POST',
            "/webhooks/whmcs/{$slug}/invoices-by-legacy-id",
            [], [], [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $raw,
        );
    }

    public function test_maps_legacy_id_to_invcode_and_mark(): void
    {
        $t = $this->tenant();
        $this->invoice($t, legacyId: 7677, invcode: 'ΤΠΥ6642', code: 6642, whmcsId: 31618);

        $resp = $this->call_($t->slug, ['legacy_ids' => [7677, 9999]]);

        $resp->assertOk()
            ->assertJsonPath('invoices.7677.ekdosi_invcode', 'ΤΠΥ6642')
            ->assertJsonPath('invoices.7677.mydata_mark', '40001376642')
            ->assertJsonPath('invoices.7677.mydata_state', 'VALID')
            ->assertJsonPath('invoices.7677.whmcs_invoice_id', 31618)
            ->assertJsonPath('invoices.9999', null);   // no imported invoice with that legacy_id
    }

    public function test_is_tenant_scoped(): void
    {
        $a = $this->tenant('lid-a-'.uniqid());
        $b = $this->tenant('lid-b-'.uniqid());
        $this->invoice($b, legacyId: 5000, invcode: 'ΤΠΥ1', code: 1);

        // Tenant A asks for B's legacy id → null (legacy_id is unique per company).
        $this->call_($a->slug, ['legacy_ids' => [5000]])
            ->assertOk()->assertJsonPath('invoices.5000', null);
    }

    public function test_drops_negative_sentinels_and_zero(): void
    {
        $t = $this->tenant();
        // -1000/-333/-1/0 are not legacy ids; an all-sentinel body is "empty".
        $this->call_($t->slug, ['legacy_ids' => [-1000, -1, 0]])
            ->assertStatus(400)->assertJsonPath('error', 'missing_or_invalid_ids');
    }

    public function test_rejects_bad_signature(): void
    {
        $t = $this->tenant();
        $this->call_($t->slug, ['legacy_ids' => [1]], 'wrong')
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_signature');
    }

    public function test_unknown_tenant_is_404(): void
    {
        $this->call_('nope-'.uniqid(), ['legacy_ids' => [1]])
            ->assertNotFound()->assertJsonPath('error', 'tenant_not_found');
    }
}
