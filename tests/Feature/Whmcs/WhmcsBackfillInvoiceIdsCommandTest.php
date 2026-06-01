<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * whmcs:backfill-invoice-ids — stamps invoices.whmcs_invoice_id from the
 * deterministic legacy link (tblinvoices.invoiced === invoices.legacy_id),
 * paging the bridge's read-only legacy_invoice_links op (faked here).
 */
class WhmcsBackfillInvoiceIdsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(bool $configured = true): Company
    {
        return Company::create([
            'name' => 'BF', 'slug' => 'bf-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => $configured ? str_repeat('s', 40) : null,
        ]);
    }

    private function invoice(Company $t, int $legacyId, ?int $whmcsId = null): Invoice
    {
        $it = InvoiceType::firstOrCreate(
            ['company_id' => $t->id, 'code' => 'ΤΠΥ'],
            ['name' => 'ΤΠΥ', 'invcount' => 1],
        );
        $inv = Invoice::create([
            'company_id' => $t->id, 'invoice_type_id' => $it->id,
            'legacy_id' => $legacyId, 'invcode' => 'ΤΠΥ'.$legacyId, 'code' => $legacyId, 'issued_at' => now(),
        ]);
        if ($whmcsId !== null) {
            $inv->forceFill(['whmcs_invoice_id' => $whmcsId])->save();
        }

        return $inv;
    }

    /** Fake legacy_invoice_links returning one page then an empty page. */
    private function fakeLinks(array $links): void
    {
        $responses = [
            Http::response(['status' => 'ok', 'links' => $links, 'offset' => 0, 'count' => count($links)], 200),
            Http::response(['status' => 'ok', 'links' => [], 'offset' => count($links), 'count' => 0], 200),
        ];
        Http::fake([self::RESOLVE_URL => Http::sequence($responses)]);
    }

    public function test_backfills_matching_invoices(): void
    {
        $t = $this->tenant();
        $a = $this->invoice($t, legacyId: 7677);   // → WHMCS 31618
        $b = $this->invoice($t, legacyId: 7680);   // → WHMCS 31619
        // 9999 has no ekdosi invoice → unmatched, no write.

        $this->fakeLinks([
            ['whmcs_id' => 31618, 'invoiced' => 7677],
            ['whmcs_id' => 31619, 'invoiced' => 7680],
            ['whmcs_id' => 31999, 'invoiced' => 9999],
        ]);

        $this->artisan('whmcs:backfill-invoice-ids', ['--tenant' => $t->slug, '--limit' => 500])
            ->assertSuccessful();

        $this->assertSame(31618, $a->fresh()->whmcs_invoice_id);
        $this->assertSame(31619, $b->fresh()->whmcs_invoice_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $t = $this->tenant();
        $a = $this->invoice($t, legacyId: 7677);

        $this->fakeLinks([['whmcs_id' => 31618, 'invoiced' => 7677]]);

        $this->artisan('whmcs:backfill-invoice-ids', ['--tenant' => $t->slug, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($a->fresh()->whmcs_invoice_id);
    }

    public function test_is_idempotent_on_already_linked_rows(): void
    {
        $t = $this->tenant();
        $a = $this->invoice($t, legacyId: 7677, whmcsId: 31618);   // already linked

        $this->fakeLinks([['whmcs_id' => 31618, 'invoiced' => 7677]]);

        $this->artisan('whmcs:backfill-invoice-ids', ['--tenant' => $t->slug])
            ->expectsOutputToContain('already linked: 1')
            ->assertSuccessful();

        $this->assertSame(31618, $a->fresh()->whmcs_invoice_id);
    }

    public function test_errors_when_tenant_unknown(): void
    {
        $this->artisan('whmcs:backfill-invoice-ids', ['--tenant' => 'nope'])
            ->assertExitCode(6);
    }

    public function test_errors_when_bridge_unconfigured(): void
    {
        $t = $this->tenant(configured: false);
        $this->artisan('whmcs:backfill-invoice-ids', ['--tenant' => $t->slug])
            ->assertExitCode(3);
    }
}
