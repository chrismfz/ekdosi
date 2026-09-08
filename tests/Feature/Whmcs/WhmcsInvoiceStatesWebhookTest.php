<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /webhooks/whmcs/{slug}/invoice-states — batch deterministic state for
 * the addon's consolidated invoice list. Maps each WHMCS invoice id to its
 * ekdosi state (ΤΠΥ + ΜΑΡΚ + κατάσταση), or null when never pushed.
 */
class WhmcsInvoiceStatesWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'states-secret-do-not-use-in-prod-xxx';

    private function tenant(?string $slug = null): Company
    {
        return Company::create([
            'name' => 'StatesTenant',
            'slug' => $slug ?? 'states-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function callStates(string $slug, array $body, string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($body);
        $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call(
            'POST',
            "/webhooks/whmcs/{$slug}/invoice-states",
            [], [], [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $raw,
        );
    }

    public function test_returns_state_per_id_with_invcode_and_mark(): void
    {
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);

        $inv = Invoice::create([
            'company_id' => $t->id, 'invoice_type_id' => $it->id,
            'invcode' => 'ΤΠΥ6643', 'code' => 6643, 'issued_at' => now(),
        ]);
        $inv->forceFill([
            'local_status' => 'active', 'mydata_state' => 'VALID', 'mydata_mark' => '400013724770604',
        ])->save();

        // 31619 filed + linked + single third-party; 31640 staged; 31999 unknown.
        PendingWhmcsInvoice::create(['company_id' => $t->id, 'whmcs_invoice_id' => 31619, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_FILED,
            'invoice_id' => $inv->id, 'third_party_state' => PendingWhmcsInvoice::TP_SINGLE]);
        PendingWhmcsInvoice::create(['company_id' => $t->id, 'whmcs_invoice_id' => 31640, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);

        $resp = $this->callStates($t->slug, ['whmcs_invoice_ids' => [31619, 31640, 31999]]);

        $resp->assertOk()
            ->assertJsonPath('states.31619.status', PendingWhmcsInvoice::STATUS_FILED)
            ->assertJsonPath('states.31619.ekdosi_invcode', 'ΤΠΥ6643')
            ->assertJsonPath('states.31619.mydata_mark', '400013724770604')
            ->assertJsonPath('states.31619.mydata_state', 'VALID')
            ->assertJsonPath('states.31619.third_party_state', PendingWhmcsInvoice::TP_SINGLE)
            ->assertJsonPath('states.31640.status', PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->assertJsonPath('states.31640.ekdosi_invcode', null)
            ->assertJsonPath('states.31640.third_party_state', null)
            ->assertJsonPath('states.31999', null);   // never pushed
    }

    public function test_is_tenant_scoped(): void
    {
        $a = $this->tenant('st-a-'.uniqid());
        $b = $this->tenant('st-b-'.uniqid());
        PendingWhmcsInvoice::create(['company_id' => $b->id, 'whmcs_invoice_id' => 5000, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);

        // Tenant A asks for B's WHMCS id → null (not leaked).
        $this->callStates($a->slug, ['whmcs_invoice_ids' => [5000]])
            ->assertOk()->assertJsonPath('states.5000', null);
    }

    public function test_rejects_bad_signature(): void
    {
        $t = $this->tenant();
        $this->callStates($t->slug, ['whmcs_invoice_ids' => [1]], 'wrong')
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_signature');
    }

    public function test_rejects_empty_id_list(): void
    {
        $t = $this->tenant();
        $this->callStates($t->slug, ['whmcs_invoice_ids' => []])
            ->assertStatus(400)->assertJsonPath('error', 'missing_or_invalid_ids');
    }

    public function test_unknown_tenant_is_404(): void
    {
        $this->callStates('nope-'.uniqid(), ['whmcs_invoice_ids' => [1]])
            ->assertNotFound()->assertJsonPath('error', 'tenant_not_found');
    }
}
