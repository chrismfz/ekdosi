<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyDataPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Preflight test',
            'slug' => 'preflight-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id' => 'TESTUSER',
            'mydata_subscription_key' => 'TESTKEY',
        ]);
    }

    private function invoiceType(Company $c, array $attrs = []): InvoiceType
    {
        return InvoiceType::create(array_merge([
            'company_id' => $c->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '11.2',
            'mydata_income_class' => 'E3_561_003',
            'mydata_income_class_category' => 'category1_3',
        ], $attrs));
    }

    private function vat(Company $c, float $rate, bool $default = true): VatCategory
    {
        return VatCategory::create([
            'company_id' => $c->id,
            'description' => $rate.'%',
            'rate' => $rate,
            'is_default' => $default,
        ]);
    }

    public function test_fully_configured_tenant_passes_clean(): void
    {
        $c = $this->tenant();
        $this->invoiceType($c);
        $this->vat($c, 24);

        $this->artisan('mydata:preflight', ['--tenant' => $c->slug])
            ->assertExitCode(0);
    }

    public function test_missing_income_classification_is_an_error(): void
    {
        $c = $this->tenant();
        $this->invoiceType($c, ['mydata_income_class' => null, 'mydata_income_class_category' => null]);
        $this->vat($c, 24);

        // Errors → exit code 2.
        $this->artisan('mydata:preflight', ['--tenant' => $c->slug])
            ->assertExitCode(2);
    }

    public function test_missing_mydata_type_warns_but_does_not_fail(): void
    {
        // A blank mydata_type = "never filed to myDATA", legitimate for
        // delivery/internal docs → WARN, not ERROR. Exit 0.
        $c = $this->tenant();
        $this->invoiceType($c, [
            'code' => 'DELIVERY',
            'mydata_type' => null,
            'mydata_income_class' => null,
            'mydata_income_class_category' => null,
        ]);
        $this->vat($c, 24);

        $this->artisan('mydata:preflight', ['--tenant' => $c->slug])
            ->assertExitCode(0);
    }

    public function test_invalid_mydata_type_is_an_error(): void
    {
        $c = $this->tenant();
        $this->invoiceType($c, ['mydata_type' => '99.9']);
        $this->vat($c, 24);

        $this->artisan('mydata:preflight', ['--tenant' => $c->slug])
            ->assertExitCode(2);
    }

    public function test_zero_percent_vat_warns_but_does_not_fail(): void
    {
        $c = $this->tenant();
        $this->invoiceType($c);
        $this->vat($c, 0);

        // 0% is a WARN (exemption needed), not an ERROR → still exit 0.
        $this->artisan('mydata:preflight', ['--tenant' => $c->slug])
            ->assertExitCode(0);
    }

    public function test_unknown_tenant_fails(): void
    {
        $this->artisan('mydata:preflight', ['--tenant' => 'does-not-exist'])
            ->assertExitCode(1);
    }

    public function test_codes_helpers(): void
    {
        $this->assertTrue(Codes::invoiceTypeExists('11.2'));
        $this->assertFalse(Codes::invoiceTypeExists('99.9'));

        $this->assertTrue(Codes::isIncomeInvoiceType('11.2'));   // retail sale
        $this->assertTrue(Codes::isIncomeInvoiceType('2.1'));    // service invoice
        $this->assertFalse(Codes::isIncomeInvoiceType('14.1'));  // expense/acquisition

        $this->assertTrue(Codes::isValidIncomeClassType('E3_561_003'));
        $this->assertFalse(Codes::isValidIncomeClassType('E3_999'));
        $this->assertTrue(Codes::isValidIncomeClassCategory('category1_3'));

        $this->assertSame([1], Codes::vatCategoriesForRate(24));
        $this->assertSame([6, 10], Codes::vatCategoriesForRate(4));  // ambiguous
        $this->assertSame([], Codes::vatCategoriesForRate(99));

        $this->assertTrue(Codes::vatExemptionExists(16));
        $this->assertFalse(Codes::vatExemptionExists(99));
        $this->assertTrue(Codes::paymentMethodExists(3));
        $this->assertFalse(Codes::paymentMethodExists(99));
    }
}
