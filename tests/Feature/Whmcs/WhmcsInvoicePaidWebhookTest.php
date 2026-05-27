<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PR #31: HTTP contract of POST /webhooks/whmcs/{slug}/invoice-paid.
 *
 * Covers:
 *   - 404 for unknown tenant
 *   - 422 for tenant without webhook secret
 *   - 401 for missing / wrong signature
 *   - 400 for malformed body
 *   - 502 for WHMCS upstream auth/network failure
 *   - 409 for unknown WHMCS invoice id
 *   - 202 on first ingest (new pending_whmcs_invoices row)
 *   - 200 on re-push (idempotent)
 *
 * The signature scheme is the security perimeter for this endpoint -
 * a missed test here is a missed attack surface. Every code path that
 * touches state past the signature check has at least one test that
 * proves the signature was verified first.
 */
class WhmcsInvoicePaidWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-secret-do-not-use-in-prod-xxxxx';

    private function configuredTenant(): Company
    {
        return Company::create([
            'name' => 'WebhookTenant',
            'slug' => 'web-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
            'whmcs_api_identifier' => 'X',
            'whmcs_api_secret' => 'Y',
            'whmcs_webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    private function sign(string $body, string $secret = self::WEBHOOK_SECRET): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    private function postSigned(string $slug, array $body, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($body);
        $signature ??= $this->sign($raw);

        return $this->call(
            'POST',
            "/webhooks/whmcs/{$slug}/invoice-paid",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
                'HTTP_ACCEPT' => 'application/json',
            ],
            $raw,
        );
    }

    public function test_returns_404_for_unknown_tenant_slug(): void
    {
        $response = $this->postSigned('does-not-exist', ['whmcs_invoice_id' => 1]);
        $response->assertStatus(404)->assertJson(['error' => 'tenant_not_found']);
    }

    public function test_returns_422_when_tenant_has_no_webhook_secret(): void
    {
        $tenant = Company::create([
            'name' => 'NoSecret',
            'slug' => 'ns-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
            'whmcs_api_identifier' => 'X',
            'whmcs_api_secret' => 'Y',
            // whmcs_webhook_secret deliberately omitted
        ]);

        $response = $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 1]);
        $response->assertStatus(422)->assertJson(['error' => 'webhook_secret_not_configured']);
    }

    public function test_returns_401_when_signature_header_missing(): void
    {
        $tenant = $this->configuredTenant();
        $response = $this->call(
            'POST',
            "/webhooks/whmcs/{$tenant->slug}/invoice-paid",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['whmcs_invoice_id' => 1]),
        );

        $response->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
    }

    public function test_returns_401_when_signature_wrong(): void
    {
        $tenant = $this->configuredTenant();
        $response = $this->postSigned(
            $tenant->slug,
            ['whmcs_invoice_id' => 1],
            'sha256='.str_repeat('f', 64),  // bogus hex
        );
        $response->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
    }

    public function test_returns_401_when_signature_signed_with_wrong_secret(): void
    {
        $tenant = $this->configuredTenant();
        $body = ['whmcs_invoice_id' => 1];
        $response = $this->postSigned(
            $tenant->slug,
            $body,
            $this->sign(json_encode($body), 'wrong-secret'),
        );
        $response->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
    }

    public function test_signature_check_runs_before_any_side_effects(): void
    {
        // If signature fails, we MUST NOT call WHMCS or write to DB.
        $tenant = $this->configuredTenant();
        Http::fake(fn () => Http::response([], 500));

        $response = $this->postSigned(
            $tenant->slug,
            ['whmcs_invoice_id' => 1],
            'sha256=bad',
        );

        $response->assertStatus(401);
        Http::assertNothingSent();
        $this->assertSame(0, PendingWhmcsInvoice::count());
    }

    public function test_returns_400_when_body_missing_whmcs_invoice_id(): void
    {
        $tenant = $this->configuredTenant();
        $response = $this->postSigned($tenant->slug, ['unrelated' => 'data']);
        $response->assertStatus(400)->assertJson(['error' => 'missing_or_invalid_whmcs_invoice_id']);
    }

    public function test_returns_502_on_whmcs_auth_failure(): void
    {
        $tenant = $this->configuredTenant();
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'error', 'message' => 'Invalid IP',
            ], 200),
        ]);

        $response = $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 42]);
        $response->assertStatus(502)->assertJson(['error' => 'whmcs_upstream_failure']);
        $this->assertSame(0, PendingWhmcsInvoice::count());
    }

    public function test_returns_409_when_whmcs_does_not_know_the_invoice(): void
    {
        $tenant = $this->configuredTenant();
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'error', 'message' => 'Invoice ID Not Found',
            ], 200),
        ]);

        $response = $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 999999]);
        $response->assertStatus(409)->assertJson([
            'error' => 'whmcs_invoice_not_found',
            'whmcs_invoice_id' => 999999,
        ]);
        $this->assertSame(0, PendingWhmcsInvoice::count());
    }

    public function test_returns_202_on_first_ingest_and_creates_pending_row(): void
    {
        $tenant = $this->configuredTenant();
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'invoiceid' => 555,
                'userid' => 77,
                'total' => '25.00',
            ], 200),
        ]);

        $response = $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 555]);

        $response->assertStatus(202)->assertJson([
            'whmcs_invoice_id' => 555,
            'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'created'          => true,
            'audit_preserved'  => false,
        ]);
        $this->assertSame(1, PendingWhmcsInvoice::count());
        $row = PendingWhmcsInvoice::first();
        $this->assertSame($tenant->id, $row->company_id);
        $this->assertSame(555, $row->whmcs_invoice_id);
    }

    public function test_returns_200_on_idempotent_re_push(): void
    {
        $tenant = $this->configuredTenant();
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success', 'invoiceid' => 555, 'userid' => 77, 'total' => '25.00',
            ], 200),
        ]);

        $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 555])->assertStatus(202);
        $response = $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 555]);

        $response->assertStatus(200)->assertJson([
            'created' => false,
            'audit_preserved' => false,
        ]);
        $this->assertSame(1, PendingWhmcsInvoice::count());
    }

    public function test_returns_200_with_audit_preserved_when_row_already_filed(): void
    {
        $tenant = $this->configuredTenant();
        // Pre-existing filed row.
        PendingWhmcsInvoice::create([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => 555,
            'payload' => ['invoiceid' => 555, 'total' => '100.00'],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at' => now(),
            'mydata_mark' => '4000111222333',
        ]);

        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success', 'invoiceid' => 555, 'userid' => 77,
                'total'  => '999.99',  // would mutate if accepted
            ], 200),
        ]);

        $response = $this->postSigned($tenant->slug, ['whmcs_invoice_id' => 555]);

        $response->assertStatus(200)->assertJson([
            'audit_preserved' => true,
            'status'          => PendingWhmcsInvoice::STATUS_FILED,
        ]);

        // Payload must not have been overwritten.
        $row = PendingWhmcsInvoice::first();
        $this->assertSame('100.00', $row->payload['total']);
    }
}
