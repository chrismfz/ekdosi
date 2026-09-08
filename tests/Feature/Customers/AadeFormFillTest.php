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

    /**
     * @return array{0: callable, 1: callable}  [get, set] over a shared store
     */
    private function formStore(array $initial = []): array
    {
        $store = $initial;
        $get = function (string $field) use (&$store) { return $store[$field] ?? null; };
        $set = function (string $field, $value) use (&$store) { $store[$field] = $value; };

        return [$get, $set, function () use (&$store) { return $store; }];
    }

    public function test_assign_import_mode_fills_only_empty_fields(): void
    {
        [$get, $set, $dump] = $this->formStore(['name' => 'Ο πελάτης το έγραψε']);

        // overwrite=false → typed value wins, empty field gets filled.
        AadeFormFill::assign($get, $set, 'name', 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ', false);
        AadeFormFill::assign($get, $set, 'tax_office', 'Α ΑΘΗΝΩΝ', false);

        $s = $dump();
        $this->assertSame('Ο πελάτης το έγραψε', $s['name'], 'typed value not clobbered in import mode');
        $this->assertSame('Α ΑΘΗΝΩΝ', $s['tax_office'], 'empty field filled');
    }

    public function test_assign_correct_mode_overwrites_from_aade(): void
    {
        [$get, $set, $dump] = $this->formStore(['name' => 'Λάθος που έγραψε ο πελάτης']);

        // overwrite=true → AADE is the source of truth, replace it.
        AadeFormFill::assign($get, $set, 'name', 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ', true);

        $this->assertSame('ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ', $dump()['name']);
    }

    public function test_assign_never_blanks_a_field_with_empty_aade_value(): void
    {
        [$get, $set, $dump] = $this->formStore(['name' => 'Υπάρχον']);

        // Even in overwrite mode, an empty AADE value must NOT wipe the field.
        AadeFormFill::assign($get, $set, 'name', '', true);
        AadeFormFill::assign($get, $set, 'name', null, true);

        $this->assertSame('Υπάρχον', $dump()['name']);
    }
}
