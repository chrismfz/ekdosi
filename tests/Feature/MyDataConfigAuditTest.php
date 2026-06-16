<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\MyData\MyDataConfigAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared config audit behind `mydata:preflight`, the «Έλεγχος ρυθμίσεων»
 * console tab, and the Invoice Types readiness badge.
 */
class MyDataConfigAuditTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Audit OE',
            'slug' => 'audit-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function type(Company $c, array $attrs = []): InvoiceType
    {
        return InvoiceType::create(array_merge([
            'company_id' => $c->id,
            'code' => 'TPY'.uniqid(),
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '11.2',
            'mydata_income_class' => 'E3_561_003',
            'mydata_income_class_category' => 'category1_3',
        ], $attrs));
    }

    public function test_good_invoice_type_is_ok(): void
    {
        $c = $this->tenant();
        $row = app(MyDataConfigAudit::class)->auditInvoiceType($this->type($c));

        $this->assertSame('ok', $row->status());
        $this->assertSame([], $row->messages());
        $this->assertSame('11.2', $row->detail);
    }

    public function test_invalid_mydata_type_is_error_with_code(): void
    {
        $c = $this->tenant();
        $row = app(MyDataConfigAudit::class)->auditInvoiceType($this->type($c, ['mydata_type' => '99.9']));

        $this->assertSame('error', $row->status());
        $this->assertTrue($row->hasError());
        $this->assertStringContainsString('[223]', $row->messages()[0]);
    }

    public function test_blank_mydata_type_is_a_warning_not_error(): void
    {
        $c = $this->tenant();
        $row = app(MyDataConfigAudit::class)->auditInvoiceType($this->type($c, [
            'mydata_type' => null, 'mydata_income_class' => null, 'mydata_income_class_category' => null,
        ]));

        $this->assertSame('warn', $row->status());
        $this->assertFalse($row->hasError());
    }

    public function test_missing_income_classification_is_error(): void
    {
        $c = $this->tenant();
        $row = app(MyDataConfigAudit::class)->auditInvoiceType($this->type($c, [
            'mydata_income_class' => null, 'mydata_income_class_category' => null,
        ]));

        $this->assertSame('error', $row->status());
        $this->assertCount(2, $row->messages()); // missing type + missing category
    }

    public function test_zero_and_ambiguous_vat_warn(): void
    {
        $c = $this->tenant();
        $audit = app(MyDataConfigAudit::class);

        $zero = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => 'Άνευ', 'rate' => 0, 'is_default' => false,
        ]));
        $four = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => 'Νησιά', 'rate' => 4, 'is_default' => false,
        ]));
        $std = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => 'Καν.', 'rate' => 24, 'is_default' => true,
        ]));

        $this->assertSame('warn', $zero->status());
        $this->assertStringContainsString('[217]', $zero->messages()[0]);
        $this->assertSame('warn', $four->status());
        $this->assertSame('ok', $std->status());
    }

    public function test_full_audit_rolls_up_counts(): void
    {
        $c = $this->tenant();
        $this->type($c);                                   // ok
        $this->type($c, ['mydata_type' => '99.9']);        // 1 error
        VatCategory::create(['company_id' => $c->id, 'description' => '0%', 'rate' => 0, 'is_default' => false]); // 1 warn
        VatCategory::create(['company_id' => $c->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]); // ok

        $result = app(MyDataConfigAudit::class)->audit($c);

        $this->assertSame(1, $result->errorCount());
        $this->assertGreaterThanOrEqual(1, $result->warnCount());
        $this->assertFalse($result->isClean());
        $this->assertCount(2, $result->invoiceTypes);
        $this->assertCount(2, $result->vatCategories);
    }
}
