<?php

namespace Tests\Feature;

use App\Jobs\SendQuoteEmail;
use App\Mail\QuoteOfferMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Services\QuoteNumberer;
use App\Services\QuotePdfRenderer;
use App\Services\QuoteTotals;
use App\Services\TenantMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuotePdfEmailTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuote(?string $email = 'c@x.gr'): Quote
    {
        $tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης', 'email' => $email,
        ]);

        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $tenant->id,
            'customer_id' => $customer->id,
            'code' => app(QuoteNumberer::class)->allocate($tenant),
            'subject' => 'Δοκιμαστική προσφορά',
            'company_name' => 'Πελάτης',
            'issued_at' => now(),
            'valid_until' => now()->addDays(30),
            'proposal_text' => 'Σας ευχαριστούμε για το ενδιαφέρον.',
        ]));

        QuoteLine::create([
            'quote_id' => $quote->id, 'product_descr' => 'Υπηρεσία',
            'qty' => 2, 'price_per_item' => 50, 'vat_percent' => 24,
        ]);

        return app(QuoteTotals::class)($quote);
    }

    public function test_renders_quote_pdf_bytes(): void
    {
        $quote = $this->makeQuote();

        $pdf = app(QuotePdfRenderer::class)->render($quote);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_job_sends_mail_and_logs_sent(): void
    {
        Mail::fake();
        $quote = $this->makeQuote();

        (new SendQuoteEmail($quote, 'manual', null))->handle(
            app(QuotePdfRenderer::class),
            app(TenantMailerFactory::class),
        );

        Mail::assertSent(QuoteOfferMail::class);
        $this->assertDatabaseHas('quote_mail_logs', [
            'quote_id' => $quote->id,
            'status' => 'sent',
            'trigger' => 'manual',
        ]);
    }

    public function test_job_logs_failed_when_no_email(): void
    {
        Mail::fake();
        $quote = $this->makeQuote(null);

        (new SendQuoteEmail($quote, 'manual', null))->handle(
            app(QuotePdfRenderer::class),
            app(TenantMailerFactory::class),
        );

        Mail::assertNotSent(QuoteOfferMail::class);
        $this->assertDatabaseHas('quote_mail_logs', [
            'quote_id' => $quote->id,
            'status' => 'failed',
        ]);
    }
}
