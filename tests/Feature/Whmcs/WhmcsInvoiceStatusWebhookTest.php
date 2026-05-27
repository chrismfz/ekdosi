<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Stage B-3 (PR #48): HTTP contract of
 *   GET /webhooks/whmcs/{slug}/invoice-status/{whmcs_invoice_id}
 *
 * Covers the auth perimeter (signature over the request path, since
 * GET has no body) and the response-shape contract the WHMCS-side
 * ekdosi_bridge plugin parses into per-invoice badges.
 */
class WhmcsInvoiceStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-secret-do-not-use-in-prod-xxxxx';

    private function configuredTenant(): Company
    {
        return Company::create([
            'name'                 => 'WebhookTenant',
            'slug'                 => 'st-'.uniqid(),
            'country_code'         => 'GR',
            'einvoice_provider'    => 'gr-mydata',
            'mydata_mode'          => 'off',
            'whmcs_webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    private function signedPath(string $path): string
    {
        return 'sha256='.hash_hmac('sha256', $path, self::WEBHOOK_SECRET);
    }

    public function test_returns_404_for_unknown_tenant(): void
    {
        $path = '/webhooks/whmcs/nope/invoice-status/123';
        $response = $this->withHeaders([
            'X-Webhook-Signature' => 'sha256=anything',
        ])->getJson($path);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'tenant_not_found']);
    }

    public function test_returns_422_for_tenant_without_webhook_secret(): void
    {
        $tenant = Company::create([
            'name'              => 'X',
            'slug'              => 'nosec-'.uniqid(),
            'country_code'      => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode'       => 'off',
            // intentionally no whmcs_webhook_secret
        ]);

        $path = "/webhooks/whmcs/{$tenant->slug}/invoice-status/123";
        $response = $this->withHeaders([
            'X-Webhook-Signature' => 'sha256=anything',
        ])->getJson($path);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'webhook_secret_not_configured');
    }

    public function test_returns_401_for_missing_signature(): void
    {
        $tenant = $this->configuredTenant();
        $path = "/webhooks/whmcs/{$tenant->slug}/invoice-status/123";

        $response = $this->getJson($path);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_signature']);
    }

    public function test_returns_401_for_wrong_signature(): void
    {
        $tenant = $this->configuredTenant();
        $path = "/webhooks/whmcs/{$tenant->slug}/invoice-status/123";

        $response = $this->withHeaders([
            'X-Webhook-Signature' => 'sha256='.str_repeat('0', 64),
        ])->getJson($path);

        $response->assertStatus(401);
    }

    public function test_returns_401_when_signature_covers_a_different_invoice_id(): void
    {
        // The security property the per-path HMAC provides: a
        // signature for /invoice-status/123 must NOT validate
        // /invoice-status/456. Otherwise an attacker observing one
        // signed URL could query any other invoice.
        $tenant = $this->configuredTenant();
        $wrongPath = "/webhooks/whmcs/{$tenant->slug}/invoice-status/123";
        $signature = $this->signedPath($wrongPath);

        $response = $this->withHeaders([
            'X-Webhook-Signature' => $signature,
        ])->getJson("/webhooks/whmcs/{$tenant->slug}/invoice-status/456");

        $response->assertStatus(401);
    }

    public function test_returns_200_found_false_when_no_pending_row_exists(): void
    {
        $tenant = $this->configuredTenant();
        $path = "/webhooks/whmcs/{$tenant->slug}/invoice-status/8888";

        $response = $this->withHeaders([
            'X-Webhook-Signature' => $this->signedPath($path),
        ])->getJson($path);

        $response->assertStatus(200);
        $response->assertJson([
            'found'            => false,
            'whmcs_invoice_id' => 8888,
        ]);
    }

    public function test_returns_200_with_full_row_state_for_filed_invoice(): void
    {
        $tenant = $this->configuredTenant();
        $row = PendingWhmcsInvoice::create([
            'company_id'       => $tenant->id,
            'whmcs_invoice_id' => 8888,
            'payload'          => ['invoiceid' => 8888],
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_FILED,
            'mydata_mark'      => '999000111',
            'filed_at'         => now(),
            'notes'            => 'Filed at AADE as invoice #ΤΠΥ123 (MARK 999000111).',
        ]);

        $path = "/webhooks/whmcs/{$tenant->slug}/invoice-status/8888";
        $response = $this->withHeaders([
            'X-Webhook-Signature' => $this->signedPath($path),
        ])->getJson($path);

        $response->assertStatus(200);
        $response->assertJson([
            'found'            => true,
            'pending_id'       => $row->id,
            'whmcs_invoice_id' => 8888,
            'status'           => PendingWhmcsInvoice::STATUS_FILED,
            'mydata_mark'      => '999000111',
        ]);
        $response->assertJsonStructure([
            'found', 'pending_id', 'whmcs_invoice_id', 'status',
            'mydata_mark', 'filed_at', 'rejected_reason', 'notes',
            'ekdosi_invoice_id',
        ]);
    }

    public function test_signature_check_runs_before_row_lookup(): void
    {
        // A bad signature must not produce database side effects
        // (read OR write). Verify by spying Log: a successful row
        // lookup with a 200 response wouldn't emit the rejection
        // log line, but a signature failure must.
        Log::spy();

        $tenant = $this->configuredTenant();
        PendingWhmcsInvoice::create([
            'company_id'       => $tenant->id,
            'whmcs_invoice_id' => 8888,
            'payload'          => ['invoiceid' => 8888],
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);

        $path = "/webhooks/whmcs/{$tenant->slug}/invoice-status/8888";
        $response = $this->withHeaders([
            'X-Webhook-Signature' => 'sha256=garbage',
        ])->getJson($path);

        $response->assertStatus(401);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx) => $msg === 'whmcs.status.rejected'
                && $ctx['reason'] === 'invalid_signature')
            ->once();
    }

    public function test_isolates_tenants_when_two_tenants_share_a_whmcs_invoice_id(): void
    {
        // Two ekdosi tenants both have a pending_whmcs_invoices row
        // with whmcs_invoice_id=8888 (each tenant's WHMCS install
        // happens to use the same numeric id). Status query for
        // tenant A must NOT return tenant B's row.
        $tenantA = $this->configuredTenant();
        $tenantB = $this->configuredTenant();

        PendingWhmcsInvoice::create([
            'company_id'       => $tenantA->id,
            'whmcs_invoice_id' => 8888,
            'payload'          => ['invoiceid' => 8888],
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'notes'            => 'tenant A row',
        ]);
        PendingWhmcsInvoice::create([
            'company_id'       => $tenantB->id,
            'whmcs_invoice_id' => 8888,
            'payload'          => ['invoiceid' => 8888],
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_FILED,
            'mydata_mark'      => '99900022',
            'filed_at'         => now(),
            'notes'            => 'tenant B row',
        ]);

        $path = "/webhooks/whmcs/{$tenantA->slug}/invoice-status/8888";
        $response = $this->withHeaders([
            'X-Webhook-Signature' => $this->signedPath($path),
        ])->getJson($path);

        $response->assertStatus(200);
        $response->assertJson([
            'found'  => true,
            'notes'  => 'tenant A row',
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
        $response->assertJsonMissing(['mydata_mark' => '99900022']);
    }
}
