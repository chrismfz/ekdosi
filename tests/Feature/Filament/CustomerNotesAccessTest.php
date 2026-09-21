<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\CustomerNotes;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The «Σημειώσεις πελάτη» page is gated on View:Customer (like the Καρτέλα) —
 * no global Gate::before here, so the real permission check is exercised.
 */
class CustomerNotesAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function access_is_gated_on_view_customer(): void
    {
        $company = Company::create([
            'name' => 'T', 'slug' => 'cna-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Permission::findOrCreate('View:Customer', 'web');

        $allowed = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $bare = User::create(['name' => 'B', 'email' => 'b-'.uniqid().'@t.l', 'password' => bcrypt('x')]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        $allowed->givePermissionTo('View:Customer');

        // actingAs BEFORE setTenant — the TenantSet event requires an auth user.
        $this->actingAs($allowed);
        Filament::setTenant($company);
        $this->assertTrue(CustomerNotes::canAccess(), 'a user with View:Customer may open the notes page');

        $this->actingAs($bare);
        $this->assertFalse(CustomerNotes::canAccess(), 'a user without View:Customer may NOT open the notes page');
    }
}
