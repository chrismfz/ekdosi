<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP contract + authorization of the customer-facing "Εκδοθέντα Παραστατικά"
 * endpoints:
 *   - GET /webhooks/whmcs/{slug}/issued-for-client/{whmcs_userid}
 *   - GET /webhooks/whmcs/{slug}/issued-doc-pdf/{whmcs_userid}/{invoice}
 *
 * The security core (decision (B)): a reseller sees their own AND their routed
 * third-party documents — but ONLY those produced from WHMCS invoices THEY paid.
 * A third party's UNRELATED invoices (under another reseller / another tenant)
 * must never leak, and the PDF proxy re-derives that membership per request.
 */
class WhmcsClientIssuedInvoicesWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'issued-secret-do-not-use-in-prod-xxxxx';

    private function tenant(string $slugPrefix = 'iss'): Company
    {
        return Company::create([
            'name' => 'IssuedTenant',
            'slug' => $slugPrefix.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    private function listSig(string $slug, int $userid): string
    {
        return 'sha256='.hash_hmac('sha256', $slug.':issued:'.$userid, self::SECRET);
    }

    private function pdfSig(string $slug, int $userid, int $invoiceId): string
    {
        return 'sha256='.hash_hmac('sha256', $slug.':issued-pdf:'.$userid.':'.$invoiceId, self::SECRET);
    }

    private function customer(Company $t, string $name, string $afm): Customer
    {
        return Customer::create(['company_id' => $t->id, 'name' => $name, 'afm' => $afm]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(Company $t, InvoiceType $it, string $invcode, int $code, ?int $customerId, string $local, ?string $state, ?int $pendingId, array $overrides = []): Invoice
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
        // local_status / mydata_* are cache columns, written via forceFill.
        $inv->forceFill(['local_status' => $local, 'mydata_state' => $state])->save();

        return $inv;
    }

    public function test_returns_own_and_routed_issued_invoices_excluding_drafts(): void
    {
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);

        $reseller = $this->customer($t, 'Reseller OE', '090000045');
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');

        // P1: a split — own part, a routed third-party part, and a draft (hidden).
        $p1 = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $ownSplit = $this->invoice($t, $it, 'ΤΠΥ201', 201, $reseller->id, 'active', 'VALID', $p1->id,
            ['company_name' => 'Reseller OE', 'vat_no' => '090000045']);
        $ownSplit->forceFill(['mydata_mark' => '400000000000201'])->save();
        $routedSplit = $this->invoice($t, $it, 'ΤΠΥ202', 202, $thirdParty->id, 'active', 'VALID', $p1->id,
            ['company_name' => 'NEXON OE', 'vat_no' => '801280908']);
        $this->invoice($t, $it, 'ΤΠΥ203', 203, $reseller->id, 'draft', null, $p1->id);   // draft → hidden

        // P2: the 1:1 file() path — always the reseller's own document. The
        // direct-file invoice carries no whmcs_pending_id (the link is
        // pending.invoice_id), and the row is created already-filed with it set
        // (a filed row is audit-frozen — invoice_id can't be mutated afterwards).
        $directOwn = $this->invoice($t, $it, 'ΤΠΥ204', 204, $reseller->id, 'active', 'VALID', null,
            ['company_name' => 'Reseller OE', 'vat_no' => '090000045']);
        PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31590, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [], 'invoice_id' => $directOwn->id,
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);

        $res = $this->withHeaders(['X-Webhook-Signature' => $this->listSig($t->slug, 793)])
            ->getJson("/webhooks/whmcs/{$t->slug}/issued-for-client/793");

        $res->assertOk()->assertJsonPath('found', true)->assertJsonCount(3, 'rows');

        $rows = collect($res->json('rows'))->keyBy('ekdosi_invoice_id');

        $this->assertTrue($rows[$ownSplit->id]['is_own']);
        $this->assertSame('400000000000201', $rows[$ownSplit->id]['mydata_mark']);
        $this->assertTrue($rows[$ownSplit->id]['has_pdf']);

        $this->assertFalse($rows[$routedSplit->id]['is_own']);
        $this->assertSame('NEXON OE', $rows[$routedSplit->id]['party_name']);
        $this->assertSame('801280908', $rows[$routedSplit->id]['party_afm']);

        $this->assertTrue($rows[$directOwn->id]['is_own']);

        // The draft (ΤΠΥ203) is absent — only issued documents are returned.
        $this->assertNotContains('ΤΠΥ203', collect($res->json('rows'))->pluck('invcode')->all());
    }

    public function test_does_not_leak_invoices_under_a_different_client_or_tenant(): void
    {
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');

        // Same third party, but its invoice sits under ANOTHER reseller (999).
        $other = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 42000, 'whmcs_userid' => 999,
            'customer_id' => $thirdParty->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $this->invoice($t, $it, 'ΤΠΥ900', 900, $thirdParty->id, 'active', 'VALID', $other->id,
            ['company_name' => 'NEXON OE', 'vat_no' => '801280908']);

        // 793 has nothing of its own here → must get an empty list, no leak of 999's row.
        $res = $this->withHeaders(['X-Webhook-Signature' => $this->listSig($t->slug, 793)])
            ->getJson("/webhooks/whmcs/{$t->slug}/issued-for-client/793");

        $res->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_list_rejects_a_bad_signature(): void
    {
        $t = $this->tenant();

        $this->withHeaders(['X-Webhook-Signature' => 'sha256=wrong'])
            ->getJson("/webhooks/whmcs/{$t->slug}/issued-for-client/793")
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_signature']);
    }

    public function test_pdf_streams_for_an_in_scope_viewable_invoice(): void
    {
        $this->mock(InvoicePdfRenderer::class)
            ->shouldReceive('render')->once()->andReturn('%PDF-1.4 fake');

        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $reseller = $this->customer($t, 'Reseller OE', '090000045');

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
        // An invoice reachable only by ANOTHER reseller must 404 for 793 — and the
        // renderer must never run (authorization fails first).
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $thirdParty = $this->customer($t, 'NEXON OE', '801280908');

        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 42000, 'whmcs_userid' => 999,
            'customer_id' => $thirdParty->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        $inv = $this->invoice($t, $it, 'ΤΠΥ900', 900, $thirdParty->id, 'active', 'VALID', $p->id);

        // Sign correctly FOR 793 — the id is in scope of the signature but NOT of
        // the client, so it must still 404.
        $this->withHeaders(['X-Webhook-Signature' => $this->pdfSig($t->slug, 793, $inv->id)])
            ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$inv->id}")
            ->assertStatus(404);
    }

    public function test_pdf_404_for_a_draft_or_cancelled_invoice(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $reseller = $this->customer($t, 'Reseller OE', '090000045');

        $p = PendingWhmcsInvoice::create([
            'company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793,
            'customer_id' => $reseller->id, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_SPLIT,
        ]);
        // In scope for 793, but a draft → not publicly viewable → 404.
        $draft = $this->invoice($t, $it, 'ΤΠΥ203', 203, $reseller->id, 'draft', null, $p->id);

        $this->withHeaders(['X-Webhook-Signature' => $this->pdfSig($t->slug, 793, $draft->id)])
            ->get("/webhooks/whmcs/{$t->slug}/issued-doc-pdf/793/{$draft->id}")
            ->assertStatus(404);
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
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $reseller = $this->customer($t, 'Reseller OE', '090000045');
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
