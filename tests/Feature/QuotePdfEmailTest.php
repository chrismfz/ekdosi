<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Jobs\SendQuoteEmail;
use App\Mail\QuoteOfferMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
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

    public function test_job_sends_to_the_lead_when_there_is_no_customer_and_marks_sent(): void
    {
        Mail::fake();
        $tenant = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);
        $lead = Lead::create(['company_id' => $tenant->id, 'name' => 'Lead', 'email' => 'lead@x.gr', 'status' => LeadStatus::Contacted]);
        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $tenant->id, 'lead_id' => $lead->id,
            'code' => app(QuoteNumberer::class)->allocate($tenant), 'issued_at' => now(), 'company_name' => 'Lead',
        ]));
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'Υπηρεσία', 'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24]);
        app(QuoteTotals::class)($quote);

        // A draft is not a contact.
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertSame(0, $lead->timeline()->count());

        (new SendQuoteEmail($quote, 'manual', null))->handle(app(QuotePdfRenderer::class), app(TenantMailerFactory::class));

        Mail::assertSent(QuoteOfferMail::class, fn (QuoteOfferMail $m) => $m->hasTo('lead@x.gr'));
        $this->assertDatabaseHas('quote_mail_logs', ['quote_id' => $quote->id, 'status' => 'sent', 'recipient' => 'lead@x.gr']);

        // A successful send IS the contact: Draft → Sent, lead → Quoted, one timeline row.
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
        $lead->refresh();
        $this->assertSame(LeadStatus::Quoted, $lead->status);
        $this->assertSame(1, $lead->timeline()->where('type', 'quote')->count());
        $this->assertNotNull($lead->last_activity_at);

        // Re-sending logs another mail but never a second contact row.
        (new SendQuoteEmail($quote->fresh(), 'manual', null))->handle(app(QuotePdfRenderer::class), app(TenantMailerFactory::class));
        $this->assertSame(1, $lead->timeline()->where('type', 'quote')->count());
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
