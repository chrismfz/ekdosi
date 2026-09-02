<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MyDataCodeGuide;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Οδηγός κωδικών myDATA» — the read-only glossary page. It is help, open to ANY
 * authenticated panel user (no Shield permission), and renders the §8 code tables
 * with their «πού χρησιμοποιείται» notes.
 */
class MyDataCodeGuidePageTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(): array
    {
        $company = Company::create([
            'name' => 'Guide OE', 'slug' => 'guide-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);

        return [$company, $user];
    }

    #[Test]
    public function any_authenticated_user_can_access_the_guide(): void
    {
        [$company, $user] = $this->tenantUser();
        $this->actingAs($user);
        Filament::setTenant($company);

        $this->assertTrue(MyDataCodeGuide::canAccess(), 'the codes guide is help — open to every operator');
    }

    #[Test]
    public function the_guide_renders_the_code_explanations(): void
    {
        [$company, $user] = $this->tenantUser();
        $this->actingAs($user);
        Filament::setTenant($company);

        Livewire::test(MyDataCodeGuide::class)
            ->assertOk()
            // The operator's example «2.1» and its plain-Greek meaning.
            ->assertSee('2.1')
            ->assertSee('Τιμολόγιο Παροχής')
            // The MYD-006 distinction that motivated all this.
            ->assertSee('category1_2')
            // Family headings.
            ->assertSee('Τύποι παραστατικών')
            ->assertSee('Αιτίες απαλλαγής');
    }
}
