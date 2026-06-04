<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * #5: the public, SIGNED official-invoice PDF route. The signature makes the URL
 * unforgeable (the only gate, since it's auth-less), drafts are refused, and the
 * renderer is mocked so the test exercises the route/guard, not dompdf.
 */
class PublicInvoicePdfRouteTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $status = 'active'): Invoice
    {
        $tenant = Company::create([
            'name' => 'T', 'slug' => 'pdf-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 9,
        ]);

        return Invoice::create([
            'company_id' => $tenant->id,
            'invoice_type_id' => $type->id,
            'code' => 9,
            'invcode' => 'TPY9',
            'issued_at' => now(),
            'local_status' => $status,
        ]);
    }

    public function test_valid_signed_url_streams_the_pdf(): void
    {
        $this->mock(InvoicePdfRenderer::class)
            ->shouldReceive('render')->once()->andReturn('%PDF-1.4 fake');

        $invoice = $this->invoice();
        $response = $this->get($invoice->publicPdfUrl());

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $invoice = $this->invoice();

        // route() builds the URL WITHOUT a signature → the `signed` middleware 403s.
        $this->get(route('public.invoice.pdf', ['invoice' => $invoice->id]))
            ->assertStatus(403);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $invoice = $this->invoice();
        $this->get($invoice->publicPdfUrl().'&extra=1')->assertStatus(403);
    }

    public function test_draft_is_404_even_with_valid_signature(): void
    {
        // Renderer must NOT be invoked for a draft.
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $invoice = $this->invoice('draft');
        $this->get($invoice->publicPdfUrl())->assertStatus(404);
    }

    public function test_cancelled_invoice_is_404_even_when_active_status(): void
    {
        // Defensive AND in isPubliclyViewable(): an AADE-cancelled doc must not
        // be served publicly even if local_status somehow reads 'active'.
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $invoice = $this->invoice('active');
        $invoice->forceFill(['mydata_state' => 'CANCELLED'])->save();
        $this->get($invoice->publicPdfUrl())->assertStatus(404);
    }
}
