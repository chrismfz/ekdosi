<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\MailTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
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

    public function test_blade_syntax_in_template_is_no_t_evaluated(): void
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
            'mydata_url' => 'https://verify.aade.gr/?mark=400099999999999',
            'mydata_state' => 'VALID',
        ])->save();

        $template = '{mark_section}';
        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice->fresh(), $template);

        $this->assertStringContainsString('400099999999999', $body);
        $this->assertStringContainsString('https://verify.aade.gr', $body);
        $this->assertStringContainsString('myDATA', $body);
    }

    public function test_markdown_in_customer_name_is_escaped_not_a_live_link(): void
    {
        // DOC-8: a customer name «[x](http://evil)» must NOT become a live link
        // when the markdown mail body is parsed by CommonMark.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['company_name' => '[Δες εδώ](http://evil.example)'])->save();

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice->fresh(), 'Πελάτης: {customer_name}');

        // The renderer escaped the markdown punctuation…
        $this->assertStringContainsString('\\[', $body);
        $this->assertStringNotContainsString('[Δες εδώ](http://evil', $body);

        // …so CommonMark renders it literally, NOT as an anchor.
        $html = (string) Markdown::parse($body);
        $this->assertStringNotContainsString('href="http://evil', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_normal_name_with_punctuation_displays_unchanged_after_markdown(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['company_name' => 'Παπαδόπουλος Α.Ε. & Σία'])->save();

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice->fresh(), 'Πελάτης: {customer_name}');
        $html = (string) Markdown::parse($body);

        // Backslash-escaped punctuation renders as the literal char.
        $this->assertStringContainsString('Παπαδόπουλος Α.Ε. &amp; Σία', $html);
    }

    public function test_subject_is_not_markdown_escaped(): void
    {
        // The subject is a plain header, not markdown — no backslashes.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['company_name' => 'Α.Ε. [test]'])->save();

        $subject = app(MailTemplateRenderer::class)
            ->renderSubject($invoice->fresh(), '{customer_name}');

        $this->assertSame('Α.Ε. [test]', $subject);
    }

    public function test_verify_url_stays_a_functional_url_not_escaped(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_mark' => '400099999999999',
            'mydata_url' => 'https://verify.aade.gr/?mark=400099999999999',
        ])->save();

        $body = app(MailTemplateRenderer::class)
            ->renderBody($invoice->fresh(), 'Επαλήθευση: {verify_url}');

        // The AADE url is a RAW placeholder — must stay usable, not backslashed.
        $this->assertStringContainsString('https://verify.aade.gr/?mark=400099999999999', $body);
    }

    public function test_render_body_plain_does_not_escape_markdown(): void
    {
        // DOC-8 Finding A: the plain-text variant must NOT carry the CommonMark
        // backslash-escaping — the text part is never parsed as markdown.
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['company_name' => 'Παπαδόπουλος Α.Ε.'])->save();

        $plain = app(MailTemplateRenderer::class)
            ->renderBodyPlain($invoice->fresh(), 'Πελάτης: {customer_name}');

        $this->assertSame('Πελάτης: Παπαδόπουλος Α.Ε.', $plain);
        $this->assertStringNotContainsString('\\', $plain);
    }

    public function test_invoice_mail_plain_text_part_has_no_backslashes(): void
    {
        // DOC-8 Finding A end-to-end: render the real mailable and assert the
        // plain-text MIME part shows values verbatim (no «1\.234\,56 €»). The
        // default body template carries {total}/{issued_at} whose punctuation is
        // escaped in the HTML part — the text part must stay clean.
        $invoice = $this->makeInvoice();
        $fresh = Invoice::query()->whereKey($invoice->getKey())
            ->with(['company', 'customer', 'invoiceType'])
            ->first();

        $mailable = new InvoiceIssuedMail($fresh, 'fake-pdf-bytes');

        // The text part shows the formatted total with its real comma…
        $mailable->assertSeeInText('124,00 €');
        // …and carries NO markdown backslash-escaping.
        $mailable->assertDontSeeInText('\\');
    }

    public function test_autolink_wraps_bare_url_in_clickable_anchor(): void
    {
        $html = MailTemplateRenderer::autolink(e('Δείτε: https://verify.aade.gr/?mark=123'));

        $this->assertStringContainsString(
            '<a href="https://verify.aade.gr/?mark=123" target="_blank" rel="noopener noreferrer">https://verify.aade.gr/?mark=123</a>',
            $html,
        );
    }

    public function test_autolink_keeps_ampersand_entity_inside_the_link(): void
    {
        // e() turns & into &amp;; a real query `&` is part of the URL and must
        // stay inside the link (and inside href="", where the entity is valid).
        $html = MailTemplateRenderer::autolink(e('https://www1.aade.gr/q?mark=1&sig=abc'));

        $this->assertStringContainsString(
            '<a href="https://www1.aade.gr/q?mark=1&amp;sig=abc" target="_blank" rel="noopener noreferrer">https://www1.aade.gr/q?mark=1&amp;sig=abc</a>',
            $html,
        );
    }

    public function test_autolink_stops_at_a_quote_entity_it_does_not_over_consume(): void
    {
        // A stray `"` after a URL becomes &quot;; the link must END at the URL,
        // not swallow the entity (the DOC-8 escaped-body over-consumption guard).
        $html = MailTemplateRenderer::autolink(e('https://aade.gr/v"tail'));

        $this->assertStringContainsString(
            '<a href="https://aade.gr/v" target="_blank" rel="noopener noreferrer">https://aade.gr/v</a>&quot;tail',
            $html,
        );
    }

    public function test_autolink_leaves_trailing_sentence_punctuation_outside_the_link(): void
    {
        $html = MailTemplateRenderer::autolink(e('Επαλήθευση: https://aade.gr/x.'));

        $this->assertStringContainsString('">https://aade.gr/x</a>.', $html);
        $this->assertStringNotContainsString('x.</a>', $html);
    }

    public function test_autolink_only_links_http_and_https_never_other_schemes(): void
    {
        $html = MailTemplateRenderer::autolink(e('javascript:alert(1) mailto:x@y.gr data:text/html,x'));

        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_autolink_keeps_operator_markup_escaped_no_live_tag(): void
    {
        // The DOC-8 boundary: operator angle-brackets are escaped BEFORE autolink,
        // so a typed <script>/<a> never becomes a live tag; only the genuine URL
        // run is linked.
        $html = MailTemplateRenderer::autolink(nl2br(e('<script>alert(1)</script> https://ok.gr/v')));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<a href="https://ok.gr/v" target="_blank" rel="noopener noreferrer">https://ok.gr/v</a>', $html);
    }

    public function test_invoice_mail_html_part_has_a_clickable_verification_link(): void
    {
        // End-to-end: the AADE verify URL from {mark_section} must render as a
        // clickable <a href> in the real mailable's HTML part (not dead text).
        $invoice = $this->makeInvoice();
        $invoice->forceFill([
            'mydata_mark' => '400099999999999',
            'mydata_url' => 'https://www1.aade.gr/q?mark=400099999999999&fim=1',
            'mydata_state' => 'VALID',
        ])->save();

        $fresh = Invoice::query()->whereKey($invoice->getKey())
            ->with(['company', 'customer', 'invoiceType'])
            ->first();

        $mailable = new InvoiceIssuedMail($fresh, 'fake-pdf-bytes');

        // $escape=false: assert the raw anchor markup, not an escaped literal.
        // No trailing `>` — Laravel's CSS inliner injects a style="" attribute
        // into the anchor before the close, so match up to rel="…" only.
        $mailable->assertSeeInHtml('<a href="https://www1.aade.gr/q?mark=400099999999999&amp;fim=1" target="_blank" rel="noopener noreferrer"', false);
        // The plain-text part keeps the URL as-is (mail clients linkify it there).
        $mailable->assertSeeInText('https://www1.aade.gr/q?mark=400099999999999&fim=1');
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
