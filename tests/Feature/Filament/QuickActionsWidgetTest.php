<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\QuickActionsWidget;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Καθημερινές εργασίες» quick-actions dashboard widget: a curated launch panel
 * whose every button is gated on the SAME permission as its destination.
 */
class QuickActionsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]));
        // myDATA-readable so the «Κονσόλα myDATA» button's canReadMyData() gate passes.
        $this->tenant = Company::create([
            'name' => 'QA OE', 'slug' => 'qa-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        Filament::setTenant($this->tenant);
    }

    #[Test]
    public function a_full_access_operator_sees_all_eight_actions_with_real_urls(): void
    {
        Gate::before(fn () => true);

        $actions = (new QuickActionsWidget)->actions();
        $labels = array_column($actions, 'label');

        $this->assertCount(8, $actions);
        foreach ([
            'Νέο Παραστατικό', 'Νέα Είσπραξη', 'Νέα Προσφορά', 'Νέος Πελάτης',
            'WHMCS Εισερχόμενα', 'Παραστατικά', 'Κονσόλα myDATA', 'Ηλικίωση οφειλών',
        ] as $label) {
            $this->assertContains($label, $labels);
        }
        // Every button carries a real, tenant-scoped URL (not empty).
        foreach ($actions as $a) {
            $this->assertNotEmpty($a['url']);
        }
    }

    #[Test]
    public function every_button_is_gated_off_when_permissions_are_denied(): void
    {
        Gate::before(fn () => false);

        // No dead links, no leaked screens: nothing the operator can't do shows.
        $this->assertSame([], (new QuickActionsWidget)->actions());
    }

    #[Test]
    public function it_renders_on_the_dashboard(): void
    {
        Gate::before(fn () => true);

        Livewire::test(QuickActionsWidget::class)
            ->assertOk()
            ->assertSee('Καθημερινές εργασίες')
            ->assertSee('Νέο Παραστατικό');
    }

    #[Test]
    public function it_is_hidden_without_a_tenant(): void
    {
        Filament::setTenant(null);
        $this->assertFalse(QuickActionsWidget::canView());
    }
}
