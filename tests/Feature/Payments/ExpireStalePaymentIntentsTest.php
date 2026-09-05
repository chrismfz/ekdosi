<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `payments:expire-stale-intents` — hygiene for abandoned ONLINE portal intents,
 * while never touching an offline (manual = operator-worklist) or a recent one.
 */
class ExpireStalePaymentIntentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $t;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Company::create(['name' => 'T', 'slug' => 'ex-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->t->id, 'name' => 'C', 'afm' => '090000045']);
    }

    private function intent(string $gateway, int $ageMinutes, string $status = PaymentIntent::STATUS_PENDING): PaymentIntent
    {
        $intent = PaymentIntent::create([
            'company_id' => $this->t->id, 'customer_id' => $this->customer->id, 'gateway' => $gateway,
            'purpose' => 'balance', 'amount' => 10, 'currency' => 'EUR', 'status' => $status,
            'reference' => 'ΠΛ-'.uniqid(),
        ]);
        // created_at is not fillable — force the age.
        $intent->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $intent;
    }

    public function test_expires_old_online_pending_intents(): void
    {
        $stale = $this->intent('eurobank', 200);

        $this->artisan('payments:expire-stale-intents', ['--minutes' => 120])->assertExitCode(0);

        $this->assertSame(PaymentIntent::STATUS_EXPIRED, $stale->fresh()->status);
    }

    public function test_leaves_offline_manual_pending_intents_alone(): void
    {
        // A bank-deposit intent is the operator's worklist — never auto-expired,
        // even when old.
        $manual = $this->intent('manual', 5000);

        $this->artisan('payments:expire-stale-intents', ['--minutes' => 120])->assertExitCode(0);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $manual->fresh()->status);
    }

    public function test_leaves_recent_online_pending_intents_alone(): void
    {
        $recent = $this->intent('eurobank', 10);   // within the window

        $this->artisan('payments:expire-stale-intents', ['--minutes' => 120])->assertExitCode(0);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $recent->fresh()->status);
    }

    public function test_does_not_touch_already_settled_or_cancelled(): void
    {
        $settled = $this->intent('eurobank', 200, PaymentIntent::STATUS_SETTLED);
        $cancelled = $this->intent('eurobank', 200, PaymentIntent::STATUS_CANCELLED);

        $this->artisan('payments:expire-stale-intents', ['--minutes' => 120])->assertExitCode(0);

        $this->assertSame(PaymentIntent::STATUS_SETTLED, $settled->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_CANCELLED, $cancelled->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $stale = $this->intent('eurobank', 200);

        $this->artisan('payments:expire-stale-intents', ['--minutes' => 120, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(PaymentIntent::STATUS_PENDING, $stale->fresh()->status);
    }
}
