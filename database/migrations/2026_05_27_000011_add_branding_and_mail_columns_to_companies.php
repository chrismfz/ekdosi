<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #27: PDF polish + per-tenant outbound mail (full config + editable
 * templates + send-log).
 *
 * Three groups of columns, all nullable so existing tenants keep
 * working without filling them. The PDF renderer, Mailer factory, and
 * mailable all gracefully degrade when a column is empty:
 *   - empty logo_path           → blank header logo area
 *   - empty pdf_footer_text     → no extra footer text
 *   - empty SMTP host           → falls back to global MAIL_MAILER
 *   - empty subject/body tmpl   → sensible Greek default at send time
 *   - empty BCC                 → no BCC header
 *
 * Decisions locked in here (don't re-litigate without reason):
 *
 * - SMTP password is `text` + cast `encrypted` in the model. Same
 *   shape as gsis_password / mydata_subscription_key. The application-
 *   wide ciphertext-collation concern (CLAUDE.md deferred from PR #22)
 *   applies here too; tracked as a single cross-cutting fix.
 *
 * - mail_subject_template / mail_body_template are plain text, NOT
 *   raw Blade. The mailer uses MailTemplateRenderer with pure strtr()
 *   substitution of a curated placeholder set ({invoice_code},
 *   {customer_name}, {total}, {mark}, {verify_url}, {tenant_name},
 *   etc) — NO Blade::render, NO eval, NO operator code execution.
 *   The Blade view that wraps the rendered body uses {!! nl2br(e($body)) !!}
 *   so e() escapes operator-supplied HTML BEFORE nl2br adds <br>s
 *   — security boundary locked in by MailTemplateRendererTest.
 *
 * - invoice_audit_bcc is a single comma/semicolon-separated string
 *   (not a JSON array). Legacy was a single hardcoded address; tenants
 *   typically need 1-3 recipients. Parsed by Company::auditBccList().
 *
 * - auto_email_on_mydata_accept defaults to false. Opt-in: a sandbox
 *   tenant should NEVER auto-mail synthetic-MARK PDFs to real customer
 *   addresses. Operators flip it on explicitly when ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            // ------- Branding -------
            $t->string('logo_path')->nullable()->after('email');
            $t->text('pdf_footer_text')->nullable()->after('logo_path');

            // ------- Outbound mail identity -------
            $t->string('mail_from_address', 191)->nullable()->after('pdf_footer_text');
            $t->string('mail_from_name', 191)->nullable()->after('mail_from_address');
            $t->string('invoice_audit_bcc', 500)->nullable()->after('mail_from_name');
            $t->boolean('auto_email_on_mydata_accept')->default(false)->after('invoice_audit_bcc');

            // ------- Per-tenant SMTP transport -------
            // When mail_smtp_host is set, the runtime Mailer factory
            // swaps Laravel's mail config to send through this tenant's
            // server. Each tenant lives behind their own SPF/DKIM.
            // When null, the global MAIL_MAILER from .env is used.
            $t->string('mail_smtp_host', 191)->nullable()->after('auto_email_on_mydata_accept');
            $t->unsignedSmallInteger('mail_smtp_port')->nullable()->after('mail_smtp_host');
            $t->string('mail_smtp_username', 191)->nullable()->after('mail_smtp_port');
            $t->text('mail_smtp_password')->nullable()->after('mail_smtp_username');  // encrypted in cast
            $t->string('mail_smtp_encryption', 10)->nullable()->after('mail_smtp_password');  // tls / ssl / null

            // ------- Editable mail templates -------
            // Plain text with curly-brace placeholders ({invoice_code},
            // {customer_name}, {total}, {mark}, {verify_url},
            // {tenant_name}). Rendered via MailTemplateRenderer using
            // pure strtr() — no Blade evaluation, no PHP execution
            // path on operator-supplied content. See the renderer
            // class docblock + MailTemplateRendererTest for the
            // security boundary.
            $t->string('mail_subject_template', 191)->nullable()->after('mail_smtp_encryption');
            $t->text('mail_body_template')->nullable()->after('mail_subject_template');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn([
                'logo_path',
                'pdf_footer_text',
                'mail_from_address',
                'mail_from_name',
                'invoice_audit_bcc',
                'auto_email_on_mydata_accept',
                'mail_smtp_host',
                'mail_smtp_port',
                'mail_smtp_username',
                'mail_smtp_password',
                'mail_smtp_encryption',
                'mail_subject_template',
                'mail_body_template',
            ]);
        });
    }
};
