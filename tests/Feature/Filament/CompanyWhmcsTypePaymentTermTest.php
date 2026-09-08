<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Schemas\CompanyForm;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The WHMCS-tab live tripwire: the two default-type selectors warn when the
 * chosen invoice/receipt type resolves to a payment method with due_days > 0,
 * because a paid WHMCS invoice issued under a credit-term type would surface as
 * a phantom open receivable. Cash-term (due_days = 0 OR no method) = no warning.
 */
class CompanyWhmcsTypePaymentTermTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private InvoiceType $cashType;

    private InvoiceType $creditType;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $this->company = Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($this->company);

        $cash = PaymentMethod::create(['company_id' => $this->company->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $credit = PaymentMethod::create(['company_id' => $this->company->id, 'description' => 'Πίστωση 30', 'due_days' => 30]);

        $this->cashType = InvoiceType::create([
            'company_id' => $this->company->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
            'payment_method_id' => $cash->id,
        ]);
        $this->creditType = InvoiceType::create([
            'company_id' => $this->company->id, 'name' => 'Επί πιστώσει', 'code' => 'ΤΙΜ', 'invcount' => 1,
            'payment_method_id' => $credit->id,
        ]);
    }

    // «έχει τρόπο πληρωμής» is text UNIQUE to the live warning (not in the
    // helper/all-clear), so it discriminates warning present vs absent.
    private const WARNING_MARKER = 'έχει τρόπο πληρωμής';

    public function test_credit_term_type_triggers_the_open_receivable_warning(): void
    {
        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['whmcs_default_invoice_type_id' => $this->creditType->id])
            ->assertSee(self::WARNING_MARKER)
            ->assertSee('30 ημέρες πίστωσης');
    }

    public function test_cash_term_type_does_not_warn(): void
    {
        // The important contract: a cash-term (due_days=0) type must NOT trip the
        // «open receivable» warning.
        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['whmcs_default_invoice_type_id' => $this->cashType->id])
            ->assertDontSee(self::WARNING_MARKER);
    }

    public function test_type_with_no_payment_method_does_not_warn(): void
    {
        // No payment method at all = also cash-term (settled at issue) → no warning.
        $noMethodType = InvoiceType::create([
            'company_id' => $this->company->id, 'name' => 'Χωρίς τρόπο', 'code' => 'ΑΛΠ', 'invcount' => 1,
        ]);

        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['whmcs_default_receipt_type_id' => $noMethodType->id])
            ->assertDontSee(self::WARNING_MARKER);
    }

    // «ΑΠΛΗΡΩΤΩΝ» (all-caps genitive) is text UNIQUE to the UNPAID-slot warning.
    private const UNPAID_WARNING_MARKER = 'ΑΠΛΗΡΩΤΩΝ';

    public function test_unpaid_slot_warns_when_the_type_is_cash_term(): void
    {
        // The MIRROR check: the ΑΠΛΗΡΩΤΑ type SHOULD be credit-term; a cash-term
        // (due_days=0) type there → an unpaid invoice would read as settled → warn.
        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['whmcs_default_unpaid_type_id' => $this->cashType->id])
            ->assertSee(self::UNPAID_WARNING_MARKER);
    }

    public function test_unpaid_slot_with_credit_term_type_does_not_warn(): void
    {
        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['whmcs_default_unpaid_type_id' => $this->creditType->id])
            ->assertDontSee(self::UNPAID_WARNING_MARKER);
    }

    public function test_whmcs_default_type_options_exclude_movement_only_types(): void
    {
        // The three WHMCS default-type selectors (invoice/receipt/unpaid) share one
        // query and must never offer a movement-only 9.x Δελτίο Αποστολής (MYD-003).
        $delivery = InvoiceType::create([
            'company_id' => $this->company->id, 'name' => 'Δελτίο Αποστολής', 'code' => 'ΔΑΠ',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);

        $m = new \ReflectionMethod(CompanyForm::class, 'whmcsDefaultTypeOptions');
        $m->setAccessible(true);
        $keys = array_keys($m->invoke(null, $this->company));

        $this->assertContains($this->cashType->id, $keys, 'null mydata_type stays selectable');
        $this->assertContains($this->creditType->id, $keys);
        $this->assertNotContains($delivery->id, $keys, '9.x excluded from WHMCS default selectors');

        // But a value the field ALREADY holds (a legacy 9.x mis-stored before
        // MYD-003) is re-injected flagged, so the admin sees it instead of a silent
        // blank that a save could quietly null — mirrors DeliveryNoteForm.
        $withCurrent = $m->invoke(null, $this->company, $delivery->id);
        $this->assertArrayHasKey($delivery->id, $withCurrent);
        $this->assertStringContainsString('μη έγκυρο', $withCurrent[$delivery->id]);
    }
}
