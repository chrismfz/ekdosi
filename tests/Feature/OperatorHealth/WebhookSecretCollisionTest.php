<?php

namespace Tests\Feature\OperatorHealth;

use App\Models\Company;
use App\Support\OperatorHealth\OperatorHealthReport;
use App\Support\OperatorHealth\OperatorHealthSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-3: the webhook HMAC is tenant-scoped by the shared secret alone (the
 * body/canonical don't bind the slug), so two tenants sharing a
 * `whmcs_webhook_secret` = forgeable cross-tenant webhooks. ops:health must
 * detect the collision (and warn) so it's rotated before it's exploitable.
 */
class WebhookSecretCollisionTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug, ?string $secret): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug, 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_webhook_secret' => $secret,
        ]);
    }

    #[Test]
    public function distinct_secrets_are_not_flagged(): void
    {
        $this->company('alpha', 'secret-aaaa');
        $this->company('beta', 'secret-bbbb');
        $this->company('gamma', null);   // no bridge → ignored

        $security = (new OperatorHealthReport)->build()['security'];

        $this->assertFalse($security['shared_webhook_secret']);
        $this->assertSame([], $security['shared_webhook_secret_tenants']);
    }

    #[Test]
    public function a_shared_secret_is_flagged_and_warns(): void
    {
        $this->company('alpha', 'the-same-secret');
        $this->company('beta', 'the-same-secret');
        $this->company('gamma', 'unique-one');

        $report = (new OperatorHealthReport)->build();

        $this->assertTrue($report['security']['shared_webhook_secret']);
        // The colliding pair is grouped; the unique tenant is not.
        $this->assertCount(1, $report['security']['shared_webhook_secret_tenants']);
        $this->assertEqualsCanonicalizing(
            ['alpha', 'beta'],
            $report['security']['shared_webhook_secret_tenants'][0],
        );

        // …and the collision raises a (non-critical) warning.
        $severity = OperatorHealthSeverity::evaluate($report);
        $this->assertSame('warning', $severity['level']);
        $this->assertNotEmpty(array_filter(
            $severity['warnings'],
            fn (string $w): bool => str_contains($w, 'webhook secret'),
        ));

        // The plaintext secret must never appear in the report.
        $this->assertStringNotContainsString('the-same-secret', json_encode($report));
    }
}
