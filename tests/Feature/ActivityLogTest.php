<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The cross-model audit trail (TracksActivity on Customer / Payment / Invoice).
 * Verifies: created/updated are logged with a curated diff and a causer, and —
 * critically — that a save touching ONLY unlogged columns produces NO log row
 * (the guarantee that money-cache recomputes and the query-builder ETL don't
 * spam the trail).
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Acme', 'slug' => 'acme-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        $this->user = User::create([
            'name' => 'Operator', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($this->user);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Πελάτης ΑΕ',
            'afm' => '123456789',
        ], $overrides));
    }

    public function test_creating_a_customer_logs_an_activity_with_causer(): void
    {
        $customer = $this->customer();

        $activity = Activity::query()->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('customers', $activity->log_name);
        $this->assertSame('created', $activity->event);
        $this->assertSame('Δημιουργία', $activity->description);
        $this->assertTrue($activity->subject->is($customer));
        $this->assertTrue($activity->causer->is($this->user));
        // The curated diff carries the new name, not unlogged columns.
        $this->assertSame('Πελάτης ΑΕ', $activity->attribute_changes['attributes']['name'] ?? null);
        $this->assertArrayNotHasKey('legacy_id', $activity->attribute_changes['attributes'] ?? []);
    }

    public function test_updating_a_logged_field_records_old_and_new(): void
    {
        $customer = $this->customer();
        $before = Activity::count();

        $customer->update(['name' => 'Νέα Επωνυμία']);

        $this->assertSame($before + 1, Activity::count());
        $activity = Activity::query()->latest('id')->first();
        $this->assertSame('updated', $activity->event);
        $this->assertSame('Νέα Επωνυμία', $activity->attribute_changes['attributes']['name']);
        $this->assertSame('Πελάτης ΑΕ', $activity->attribute_changes['old']['name']);
    }

    public function test_updating_only_unlogged_columns_does_not_log(): void
    {
        $customer = $this->customer();
        // Reload from the DB so the instance carries real column values (incl.
        // DB defaults) — exactly how Filament's Edit page / the money-cache
        // recompute see the record. (On a freshly-created in-memory instance,
        // unset default columns would otherwise read back as null→default.)
        $customer->refresh();
        $before = Activity::count();

        // sort_order is fillable but NOT in Customer::loggedAttributes() — this
        // mimics a money-cache recompute / ETL touch: no logged column changed.
        $customer->update(['sort_order' => 99]);

        $this->assertSame($before, Activity::count(), 'an unlogged-only change must not create a log row');
    }

    public function test_payment_changes_are_logged(): void
    {
        $customer = $this->customer();
        $baseline = Activity::where('log_name', 'payments')->count();

        $payment = Payment::create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'amount' => 50.00,
            'pay_date' => now()->toDateString(),
        ]);

        $created = Activity::query()->latest('id')->first();
        $this->assertSame('payments', $created->log_name);
        $this->assertSame('created', $created->event);
        $this->assertSame('50.00', (string) ($created->attribute_changes['attributes']['amount'] ?? null));

        $payment->update(['amount' => 75.00]);
        $this->assertSame($baseline + 2, Activity::where('log_name', 'payments')->count());
    }

    public function test_no_causer_on_unauthenticated_path(): void
    {
        auth()->logout();
        $this->customer(['name' => 'Σύστημα ΑΕ']);

        $activity = Activity::query()->latest('id')->first();
        $this->assertNull($activity->causer, 'a CLI/queue-style write has no causer');
    }
}
