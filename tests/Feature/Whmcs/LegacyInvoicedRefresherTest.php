<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\LegacyInvoicedRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dual-run: the inbox's «Legacy» flag refresh — ask the bridge whether a staged
 * WHMCS invoice has ALSO been invoiced in the legacy ekdosi app, so the operator
 * doesn't double-issue. Bridge transport faked.
 */
class LegacyInvoicedRefresherTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(bool $configured = true): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'lir-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => $configured ? str_repeat('s', 40) : null,
        ]);
    }

    private function pending(Company $tenant, int $whmcsId, string $status, ?int $invoiceId = null, ?int $legacy = null): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => $whmcsId,
            'invoice_id' => $invoiceId,
            'payload' => ['id' => $whmcsId, 'total' => 10.0],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => $status,
            'legacy_invoiced' => $legacy,
        ]);
    }

    private function refresher(): LegacyInvoicedRefresher
    {
        return app(LegacyInvoicedRefresher::class);
    }

    public function test_flags_actionable_rows_invoiced_in_legacy(): void
    {
        $tenant = $this->tenant();
        $a = $this->pending($tenant, 8001, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);
        $b = $this->pending($tenant, 8002, PendingWhmcsInvoice::STATUS_HELD);

        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'flags' => ['8001' => 1, '8002' => 0],
        ], 200)]);

        $changed = $this->refresher()->refresh($tenant);

        $this->assertSame(2, $changed);
        $this->assertSame(1, $a->fresh()->legacy_invoiced);
        $this->assertTrue($a->fresh()->invoicedInLegacy());
        $this->assertSame(0, $b->fresh()->legacy_invoiced);
        $this->assertFalse($b->fresh()->invoicedInLegacy());

        // Only the actionable ids were asked about (order not guaranteed).
        Http::assertSent(function (Request $r) {
            $body = json_decode($r->body(), true);
            $ids = $body['ids'] ?? [];
            sort($ids);

            return ($body['op'] ?? null) === 'invoiced_flags' && $ids === [8001, 8002];
        });
    }

    public function test_skips_filed_and_already_linked_rows(): void
    {
        $tenant = $this->tenant();
        // drafted/filed are out of the actionable set (already produced a
        // παραστατικό); the double-invoicing risk is moot for them.
        $linked = $this->pending($tenant, 8003, PendingWhmcsInvoice::STATUS_DRAFTED);
        $filed = $this->pending($tenant, 8004, PendingWhmcsInvoice::STATUS_FILED);

        Http::fake([self::RESOLVE_URL => Http::response(['status' => 'ok', 'flags' => []], 200)]);

        $changed = $this->refresher()->refresh($tenant);

        $this->assertSame(0, $changed);
        $this->assertNull($linked->fresh()->legacy_invoiced);
        $this->assertNull($filed->fresh()->legacy_invoiced);
        // No actionable rows → no bridge call at all.
        Http::assertNothingSent();
    }

    public function test_no_op_when_bridge_unconfigured(): void
    {
        $tenant = $this->tenant(configured: false);
        $row = $this->pending($tenant, 8005, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);

        Http::fake();

        $this->assertSame(0, $this->refresher()->refresh($tenant));
        $this->assertNull($row->fresh()->legacy_invoiced);
        Http::assertNothingSent();
    }

    public function test_clamps_a_raw_15_digit_mark_to_a_boolean_signal(): void
    {
        // Dual-run reality: a tenant whose plugin hasn't been re-activated still
        // has a 15-digit MARK in tblinvoices.invoiced. It must NOT be stored
        // verbatim (would overflow the smallint column on real MariaDB) — the
        // refresher collapses anything > 0 to 1.
        $tenant = $this->tenant();
        $row = $this->pending($tenant, 8007, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);

        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'flags' => ['8007' => 400001234567890],
        ], 200)]);

        $changed = $this->refresher()->refresh($tenant);

        $this->assertSame(1, $changed);
        $this->assertSame(1, $row->fresh()->legacy_invoiced);
        $this->assertTrue($row->fresh()->invoicedInLegacy());
    }

    public function test_missing_id_in_response_does_not_clobber_known_value(): void
    {
        $tenant = $this->tenant();
        $row = $this->pending($tenant, 8006, PendingWhmcsInvoice::STATUS_PENDING_REVIEW, legacy: 1);

        // Bridge answers but omits this id (unknown) — keep the known value.
        Http::fake([self::RESOLVE_URL => Http::response(['status' => 'ok', 'flags' => []], 200)]);

        $changed = $this->refresher()->refresh($tenant);

        $this->assertSame(0, $changed);
        $this->assertSame(1, $row->fresh()->legacy_invoiced);
    }
}
