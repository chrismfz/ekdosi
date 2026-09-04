<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * HTTP contract + authorization of the customer-facing "Εκδοθέντα Παραστατικά"
 * endpoints:
 *   - POST /webhooks/whmcs/{slug}/issued-for-client
 *   - GET  /webhooks/whmcs/{slug}/issued-doc-pdf/{whmcs_userid}/{invoice}
 *
 * The security core (decision (B)): a reseller sees their own AND their routed
 * third-party documents — but ONLY those produced from WHMCS invoices THEY paid.
 * Two sources: bridge-derived (pending.whmcs_userid) and pre-bridge historical
 * (invoices.whmcs_invoice_id ∈ the reseller's own WHMCS invoice ids). A third
 * party's UNRELATED invoices (another reseller / a WHMCS invoice not in the
 * client's set) must never leak, and the PDF proxy re-derives membership.
 */
class WhmcsClientIssuedInvoicesWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'issued-secret-do-not-use-in-prod-xxxxx';

    private function tenant(string $provider = 'gr-mydata'): Company
    {
        return Company::create([
            'name' => 'IssuedTenant',
            'slug' => 'iss-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => $provider,
            'mydata_mode' => 'off',
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    /** @param  array<string, mixed>  $body */
    private function callIssued(string $slug, array $body, string $secret = self::SECRET): TestResponse
    {
        $raw = json_encode($body);
        $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call(
            'POST',
            "/webhooks/whmcs/{$slug}/issued-for-client",
            [], [], [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $raw,
        );
    }

    private function pdfSig(string $slug, int $userid, int $invoiceId): string
    {
        return 'sha256='.hash_hmac('sha256', $slug.':issued-pdf:'.$userid.':'.$invoiceId, self::SECRET);
    }

    private function customer(Company $t, string $name, string $afm, ?int $whmcsClientId = null): Customer
    {
        return Customer::create([
            'company_id' => $t->id,
            'name' => $name,
            'afm' => $afm,
            'whmcs_client_id' => $whmcsClientId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  extra fillable columns (company_name/vat_no snapshot)
     * @param  array<string, mixed>  $forced  extra forceFill (cache) columns
     */
    private function invoice(Company $t, InvoiceType $it, string $invcode, int $code, ?int $customerId, string $local, ?string $state, ?int $pendingId, array $overrides = [], array $forced = []): Invoice
    {
        $inv = Invoice::create(array_merge([
            'company_id' => $t->id,
            'invoice_type_id' => $it->id,
            'invcode' => $invcode,
            'code' => $code,
            'customer_id' => $customerId,
            'whmcs_pending_id' => $pendingId,
            'issued_at' => now(),
        ], $overrides));
        // local_status / mydata_* / whmcs_invoice_id are not fillable — forceFill.
        $inv->forceFill(array_merge(['local_status' => $local, 'mydata_state' => $state], $forced))->save();

        return $inv;
    }

    private function type(Company $t): InvoiceType
    {
        return InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
    }

    public function test_returns_own_and_routed_bridge_invoices_excluding_drafts(): void
    {
        $t = $this->tenant();
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');

        // P1: a split — own part, a routed third-party part, and a draft (hidden).
        $p1 = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $ownSplit = $this->invoice($t, $it, 'ΤΠΥ201', 201, $reseller->id, 'active', 'VALID', $p1->id,
            ['company_name' => 'Reseller OE', 'vat_no' => '090000045'], ['mydata_mark' => '400000000000201']);
        $routedSplit = $this->invoice($t, $it, 'ΤΠΥ202', 202, $thirdParty->id, 'active', 'VALID', $p1->id,
            ['company_name' => 'NEXON OE', 'vat_no' => '801280908']);
        $this->invoice($t, $it, 'ΤΠΥ203', 203, $reseller->id, 'draft', null, $p1->id);   // draft → hidden

        // P2: the 1:1 file() path — always the reseller's own document.
        $directOwn = $this->invoice($t, $it, 'ΤΠΥ204', 204, $reseller->id, 'active', 'VALID', null,
            ['company_name' => 'Reseller OE', 'vat_no' => '090000045']);
        PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31590, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [], 'invoice_id' => $directOwn->id,
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);

        $res = $this->callIssued($t->slug, ['whmcs_userid' => 793, 'whmcs_invoice_ids' => []]);

        $res->assertOk()->assertJsonPath('found', true)->assertJsonCount(3, 'rows');

        $rows = collect($res->json('rows'))->keyBy('ekdosi_invoice_id');
        $this->assertTrue($rows[$ownSplit->id]['is_own']);
        $this->assertSame('400000000000201', $rows[$ownSplit->id]['mydata_mark']);
        $this->assertTrue($rows[$ownSplit->id]['has_pdf']);
        $this->assertFalse($rows[$routedSplit->id]['is_own']);
        $this->assertSame('NEXON OE', $rows[$routedSplit->id]['party_name']);
        $this->assertSame('801280908', $rows[$routedSplit->id]['party_afm']);
        $this->assertTrue($rows[$directOwn->id]['is_own']);
        $this->assertNotContains('ΤΠΥ203', collect($res->json('rows'))->pluck('invcode')->all());
    }

    public function test_includes_historical_scoped_to_the_clients_own_whmcs_ids_without_leaking(): void
    {
        $t = $this->tenant();
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');

        // Historical (pre-bridge): matched by invoices.whmcs_invoice_id.
        $histOwn = $this->invoice($t, $it, 'ΤΠΥ101', 101, $reseller->id, 'active', 'VALID', null,
            ['company_name' => 'Reseller OE', 'vat_no' => '090000045'], ['whmcs_invoice_id' => 5001]);
        // A legacy third-party routing issued ON the reseller's WHMCS invoice 5002
        // → legitimately theirs to see (the boundary is the WHMCS invoice id).
        $histRouted = $this->invoice($t, $it, 'ΤΠΥ102', 102, $thirdParty->id, 'active', 'VALID', null,
            ['company_name' => 'NEXON OE', 'vat_no' => '801280908'], ['whmcs_invoice_id' => 5002]);
        // SAME third party, but a WHMCS invoice the reseller never paid (6000) →
        // must NOT leak, even though the ΑΦΜ matches histRouted.
        $histUnrelated = $this->invoice($t, $it, 'ΤΠΥ103', 103, $thirdParty->id, 'active', 'VALID', null,
            ['company_name' => 'NEXON OE', 'vat_no' => '801280908'], ['whmcs_invoice_id' => 6000]);

        // The plugin sends the reseller's OWN WHMCS invoice ids (tblinvoices.userid).
        $res = $this->callIssued($t->slug, ['whmcs_userid' => 793, 'whmcs_invoice_ids' => [5001, 5002]]);

        $res->assertOk()->assertJsonCount(2, 'rows');
        $rows = collect($res->json('rows'))->keyBy('ekdosi_invoice_id');

        $this->assertArrayHasKey($histOwn->id, $rows->all());
        $this->assertTrue($rows[$histOwn->id]['is_own']);
        // Historical rows have no pending row → the PDF proxy can't serve them, so
        // no PDF button is advertised (verify link still stands).
        $this->assertFalse($rows[$histOwn->id]['has_pdf']);
        $this->assertArrayHasKey($histRouted->id, $rows->all());
        $this->assertFalse($rows[$histRouted->id]['is_own']);   // third party → «Σε τρίτους»
        $this->assertArrayNotHasKey($histUnrelated->id, $rows->all());   // no ΑΦΜ leak
    }

    public function test_list_excludes_cancelled_documents(): void
    {
        $t = $this->tenant();
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);

        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $live = $this->invoice($t, $it, 'ΤΠΥ201', 201, $reseller->id, 'active', 'VALID', $p->id);
        // Cancelled (void) — must NOT appear in the customer's list.
        $cancelled = $this->invoice($t, $it, 'ΤΠΥ202', 202, $reseller->id, 'cancelled', 'CANCELLED', $p->id);

        $res = $this->callIssued($t->slug, ['whmcs_userid' => 793, 'whmcs_invoice_ids' => []]);

        $res->assertOk()->assertJsonCount(1, 'rows');
        $ids = collect($res->json('rows'))->pluck('ekdosi_invoice_id')->all();
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_verify_kind_is_provider_for_a_gr_provider_tenant(): void
    {
        $t = $this->tenant('gr-provider');
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);

        $inv = $this->invoice($t, $it, 'ΤΠΥ301', 301, $reseller->id, 'active', 'VALID', null,
            ['company_name' => 'Reseller OE', 'vat_no' => '090000045'],
            ['whmcs_invoice_id' => 7001, 'mydata_url' => 'https://demo.invosign.gr/viewinvoice.php?afm=EL800561849&file=MQ&gvsenc=abc']);

        $res = $this->callIssued($t->slug, ['whmcs_userid' => 793, 'whmcs_invoice_ids' => [7001]]);

        $res->assertOk()->assertJsonCount(1, 'rows');
        $row = $res->json('rows.0');
        $this->assertSame($inv->id, $row['ekdosi_invoice_id']);
        $this->assertSame('provider', $row['verify_kind']);
        $this->assertStringContainsString('invosign.gr', $row['verify_url']);
    }

    public function test_does_not_leak_bridge_invoices_under_a_different_client(): void
    {
        $t = $this->tenant();
        $it = $this->type($t);
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');

        $other = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 42000, 'whmcs_userid' => 999,
            'customer_id' => $thirdParty->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $this->invoice($t, $it, 'ΤΠΥ900', 900, $thirdParty->id, 'active', 'VALID', $other->id,
            ['company_name' => 'NEXON OE', 'vat_no' => '801280908']);

        $res = $this->callIssued($t->slug, ['whmcs_userid' => 793, 'whmcs_invoice_ids' => []]);
        $res->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_list_rejects_a_bad_signature(): void
    {
        $t = $this->tenant();

        $this->call(
            'POST',
            "/webhooks/whmcs/{$t->slug}/issued-for-client",
            [], [], [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=wrong', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['whmcs_userid' => 793, 'whmcs_invoice_ids' => []]),
        )->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
    }

    public function test_pdf_streams_for_an_in_scope_viewable_invoice(): void
    {
        $this->mock(InvoicePdfRenderer::class)
            ->shouldReceive('render')->once()->andReturn('%PDF-1.4 fake');

        $t = $this->tenant();
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);
        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $inv = $this->invoice($t, $it, 'ΤΠΥ201', 201, $reseller->id, 'active', 'VALID', $p->id);

        $this->withHeaders(['X-Webhook-Signature' => $this->pdfSig($t->slug, 793, $inv->id)])
            ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$inv->id}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_404_for_an_out_of_scope_invoice(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->tenant();
        $it = $this->type($t);
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');
        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 42000, 'whmcs_userid' => 999,
            'customer_id' => $thirdParty->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $inv = $this->invoice($t, $it, 'ΤΠΥ900', 900, $thirdParty->id, 'active', 'VALID', $p->id);

        $this->withHeaders(['X-Webhook-Signature' => $this->pdfSig($t->slug, 793, $inv->id)])
            ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$inv->id}")
            ->assertStatus(404);
    }

    public function test_pdf_404_for_a_draft_or_cancelled_invoice(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->tenant();
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);
        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $draft = $this->invoice($t, $it, 'ΤΠΥ203', 203, $reseller->id, 'draft', null, $p->id);
        // In scope for 793, but void → not publicly viewable → 404 (fail-closed).
        $cancelled = $this->invoice($t, $it, 'ΤΠΥ204', 204, $reseller->id, 'active', 'CANCELLED', $p->id);

        foreach ([$draft->id, $cancelled->id] as $id) {
            $this->withHeaders(['X-Webhook-Signature' => $this->pdfSig($t->slug, 793, $id)])
                ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$id}")
                ->assertStatus(404);
        }
    }

    public function test_pdf_404_for_a_nonexistent_invoice_with_a_valid_signature(): void
    {
        // No existence oracle: a correctly-signed request for an id that doesn't
        // exist returns the SAME 404 as an out-of-scope one, and never renders.
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->tenant();
        $missingId = 987654;

        $this->withHeaders(['X-Webhook-Signature' => $this->pdfSig($t->slug, 793, $missingId)])
            ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$missingId}")
            ->assertStatus(404);
    }

    public function test_pdf_rejects_a_bad_signature(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->tenant();
        $it = $this->type($t);
        $reseller = $this->customer($t, 'Reseller OE', '090000045', 793);
        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $inv = $this->invoice($t, $it, 'ΤΠΥ201', 201, $reseller->id, 'active', 'VALID', $p->id);

        $this->withHeaders(['X-Webhook-Signature' => 'sha256=wrong'])
            ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$inv->id}")
            ->assertStatus(401);
    }
}
