<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Contracts\Mail\Factory as MailFactoryContract;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Resolves a Mailer instance configured for a specific tenant.
 *
 * Each tenant can configure its own SMTP server (host/port/user/pass/
 * encryption) on the companies table. When set, this factory builds
 * a one-off mailer that sends through that server — so an invoice mail
 * from tenant A comes from A's domain (with A's SPF/DKIM), even though
 * tenant B and the app itself use different settings.
 *
 * When a tenant has NO custom SMTP (host is empty), we return the
 * default Mail facade resolver — the global MAIL_MAILER from .env.
 *
 * Test-mode compatibility: Mail::fake() swaps in a MailFake which
 * does NOT extend MailManager. We detect this via the Factory contract
 * (both implement Illuminate\Contracts\Mail\Factory) and bypass the
 * per-tenant config dance — the fake captures whatever the job sends
 * regardless of which named mailer it asks for. This keeps the
 * production code path correct while letting Mail::fake() Just Work
 * in tests without per-test plumbing.
 */
class TenantMailerFactory
{
    /**
     * Injected via the Factory contract so Mail::fake() resolves
     * correctly under test (MailFake implements the contract; MailManager
     * is the concrete class behind it in production).
     */
    public function __construct(private readonly MailFactoryContract $factory) {}

    public function for(Company $tenant): MailerContract
    {
        // Test mode: bypass per-tenant SMTP config. The MailFake captures
        // sends regardless of the requested mailer name, so we just hand
        // back the default. This preserves Mail::assertSent / hasBcc /
        // hasTo behaviour in feature tests.
        if ($this->factory instanceof MailFake) {
            return $this->factory->mailer();
        }

        if (! $tenant->hasOwnSmtp()) {
            return $this->factory->mailer();
        }

        // Unique mailer name per call so we NEVER collide with
        // MailManager's per-name cache. forgetMailers() would flush
        // ALL cached mailers (verified at vendor/laravel/.../MailManager
        // .php:631 — `$this->mailers = []`), which under a queue
        // worker processing tenants A → B → A would force every
        // dispatch to rebuild every other tenant's mailer too. Unique
        // names cost a fresh resolve per send but leave neighbouring
        // sends untouched.
        $mailerName = 'tenant-'.$tenant->getKey().'-'.uniqid('', true);

        config()->set("mail.mailers.{$mailerName}", [
            'transport'  => 'smtp',
            'host'       => $tenant->mail_smtp_host,
            'port'       => $tenant->mail_smtp_port ?: 587,
            'encryption' => $tenant->mail_smtp_encryption ?: null,
            'username'   => $tenant->mail_smtp_username,
            'password'   => $tenant->mail_smtp_password,
            'timeout'    => 30,
        ]);

        try {
            return $this->factory->mailer($mailerName);
        } catch (\Throwable $e) {
            // Misconfigured tenant SMTP would otherwise wedge every
            // future send. Fall through to the default mailer + log so
            // the operator sees the diagnostic in queue worker logs.
            Log::warning('Tenant SMTP build failed — falling back to default mailer', [
                'tenant_id' => $tenant->getKey(),
                'error'     => $e->getMessage(),
            ]);
            return $this->factory->mailer();
        }
    }
}
