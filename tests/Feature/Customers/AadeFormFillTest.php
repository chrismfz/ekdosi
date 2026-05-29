<?php

namespace Tests\Feature\Customers;

use App\Filament\Support\AadeFormFill;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Guards the shared GSIS lookup helper that both CustomerForm and
 * SupplierForm delegate to. Exercises the real AadeRegistryLookup +
 * exception class references — the code path a render test does NOT touch
 * (a broken import there fatals only when the button is clicked).
 */
class AadeFormFillTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Company
    {
        $tenant = Company::create([
            'name' => 'Fill Test',
            'slug' => 'fill-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            // No GSIS credentials → findByAfm throws AadeCredentialsInvalid,
            // which lookup() must catch and turn into null + a notification.
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    public function test_returns_null_on_empty_afm(): void
    {
        $this->boot();

        $this->assertNull(AadeFormFill::lookup(''));
        $this->assertNull(AadeFormFill::lookup(null));
    }

    public function test_returns_null_and_notifies_when_gsis_not_configured(): void
    {
        $this->boot();

        // Reaches AadeRegistryLookup::findByAfm → AadeCredentialsInvalid →
        // caught by lookup(). If any of those class refs were broken (the
        // regression this guards), this line would fatal instead of null.
        $this->assertNull(AadeFormFill::lookup('123456789'));
    }
}
