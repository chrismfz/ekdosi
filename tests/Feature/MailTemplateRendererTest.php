<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\MailTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the curated-placeholder contract for operator-edited mail
 * templates. The renderer's security promise: NO Blade evaluation,
 * NO PHP execution, NO arbitrary substitution beyond the documented
 * placeholder set. An operator who edits mail_body_template cannot
 * achieve code execution through it.
 */
class MailTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Acme Greek Ltd',
            'slug' => 'acme-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Cust',
            'email' => 'cust@example.com',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);
    }

    public function test_default_subject_template_used_when_blank(): void
    {
        $invoice = $this->makeInvoice();
        $subject = app(MailTemplateRenderer::class)
            ->renderSubject($invoice, null);

        $this->assertStringContainsString('Acme Greek Ltd', $subject);
        $this->assertStringContainsString('Τιμολόγιο', $subject);
        $this->assertStringContainsString($invoice->invcode, $subject);
    }

    public function test_default_body_template_renders_with_placeholders_interpolated(): void
    {
        $invoice = $this->makeInvoice();
        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice, null);

        $this->assertStringContainsString('Acme Greek Ltd', $body);
        $this->assertStringContainsString($invoice->invcode, $body);
        // No raw placeholders should remain in the rendered output
        $this->assertStringNotContainsString('{tenant_name}', $body);
        $this->assertStringNotContainsString('{invoice_code}', $body);
    }

    public function test_custom_template_overrides_default(): void
    {
        $invoice = $this->makeInvoice();
        $custom = 'Γεια σας! {invoice_code} έχει εκδοθεί. Σύνολο: {total}.';

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice, $custom);

        $this->assertStringStartsWith('Γεια σας!', $body);
        $this->assertStringContainsString($invoice->invcode, $body);
        $this->assertStringContainsString(',00 €', $body);  // total formatted
    }

    public function test_subject_collapses_newlines_to_single_line(): void
    {
        $invoice = $this->makeInvoice();
        $multiline = "Line one\nLine two\n\nWith blank line";

        $subject = app(MailTemplateRenderer::class)
            ->renderSubject($invoice, $multiline);

        $this->assertSame('Line one Line two With blank line', $subject);
    }

    public function test_unknown_placeholders_are_left_intact_not_crashed(): void
    {
        $invoice = $this->makeInvoice();
        $template = 'Hi {customer_name} — see {nonexistent_field} for {invoice_code}';

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice, $template);

        // {nonexistent_field} stays as literal text — no crash
        $this->assertStringContainsString('{nonexistent_field}', $body);
        $this->assertStringContainsString($invoice->invcode, $body);
    }

    public function test_blade_syntax_in_template_is_NOT_evaluated(): void
    {
        // Security: operator-edited templates must never execute PHP
        // or Blade. The renderer is pure str_replace; Blade syntax
        // should pass through as literal text.
        $invoice = $this->makeInvoice();
        $hostile = '{{ phpinfo() }} <?php exec("rm -rf /"); ?> {!! $bla !!}';

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice, $hostile);

        // The hostile content survives verbatim — proving it wasn't
        // evaluated. If a future change introduces Blade::render(),
        // this test fails immediately.
        $this->assertStringContainsString('{{ phpinfo() }}', $body);
        $this->assertStringContainsString('<?php exec', $body);
        $this->assertStringContainsString('{!! $bla !!}', $body);
    }

    public function test_mark_section_renders_empty_for_drafts(): void
    {
        $invoice = $this->makeInvoice();  // draft, no mark
        $template = 'Body. {mark_section} End.';

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice, $template);

        // mark_section is an empty string when no MARK — no AADE
        // confirmation paragraph polluting draft emails.
        $this->assertSame('Body.  End.', $body);
    }

    public function test_mark_section_renders_full_confirmation_for_filed_invoices(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_mark' => '400099999999999',
            'mydata_url'  => 'https://verify.aade.gr/?mark=400099999999999',
            'mydata_state' => 'VALID',
        ])->save();

        $template = '{mark_section}';
        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice->fresh(), $template);

        $this->assertStringContainsString('400099999999999', $body);
        $this->assertStringContainsString('https://verify.aade.gr', $body);
        $this->assertStringContainsString('myDATA', $body);
    }

    private function makeInvoice(): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'company_name' => 'Cust',
            'gross_total' => 124.00,
            'net_total' => 100.00,
        ]);
    }
}
