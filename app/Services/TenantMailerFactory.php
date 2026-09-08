<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Contracts\Mail\Factory as MailFactoryContract;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\MailManager;
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

        // Fixed per-tenant name + a forgetMailers() flush before each
        // build. Tradeoffs:
        //   - The flush wipes the WHOLE mailer cache (verified at
        //     vendor/laravel/.../MailManager.php — $this->mailers = [];).
        //     Any neighbouring code that uses Mail::mailer() rebuilds
        //     once on next access. Cost is milliseconds per send.
        //   - The ALTERNATIVE (unique name per call) avoids the flush
        //     but accumulates one config entry + one cached Mailer
        //     instance per send, with no eviction. Under a long-lived
        //     queue worker doing 1000 sends, that's 2-10MB held
        //     indefinitely. A flush per send is the better tradeoff.
        // Under Octane this still holds: per-tenant fixed names + flush
        // means cache hits exactly once on the same tenant's next call
        // and never accumulates.
        $mailerName = 'tenant-'.$tenant->getKey();

        config()->set("mail.mailers.{$mailerName}", [
            'transport'  => 'smtp',
            'host'       => $tenant->mail_smtp_host,
            'port'       => $tenant->mail_smtp_port ?: 587,
            'encryption' => $tenant->mail_smtp_encryption ?: null,
            'username'   => $tenant->mail_smtp_username,
            'password'   => $tenant->mail_smtp_password,
            'timeout'    => 30,
        ]);

        if ($this->factory instanceof MailManager) {
            $this->factory->forgetMailers();
        }

        try {
            return $this->factory->mailer($mailerName);
        } catch (\Throwable $e) {
            // Misconfigured tenant SMTP would otherwise wedge every
            // future send. Fall through to the default mailer + log so
            // the operator sees the diagnostic in queue worker logs.
            // OPS-12: the From stays the tenant's address but now leaves via the
            // GLOBAL SMTP server, which is almost certainly NOT authorised by the
            // tenant domain's SPF/DKIM → the mail may be spam-foldered/rejected.
            // Deliberate (send-degraded beats send-never); flagged loudly so an
            // operator fixes the tenant SMTP rather than relying on the fallback.
            Log::warning('Tenant SMTP build failed — falling back to default mailer (SPF/DKIM may misalign; fix the tenant SMTP)', [
                'tenant_id' => $tenant->getKey(),
                'error'     => $e->getMessage(),
            ]);
            return $this->factory->mailer();
        }
    }
}
