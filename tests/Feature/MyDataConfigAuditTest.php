<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
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

    private function tenant(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Audit OE',
            'slug' => 'audit-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ], $attrs));
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

    public function test_goods_type_without_quantity_flag_warns(): void
    {
        // MYD-9: a goods type (1.1) must carry the per-line quantity flag, or the
        // first filing is rejected [204].
        $c = $this->tenant();
        $row = app(MyDataConfigAudit::class)->auditInvoiceType(
            $this->type($c, ['mydata_type' => '1.1', 'mydata_requires_quantity' => false])
        );

        $this->assertSame('warn', $row->status());
        $this->assertStringContainsString('[204]', implode(' ', $row->messages()));
    }

    public function test_services_type_with_quantity_flag_warns(): void
    {
        // MYD-9: a services type (11.2) must NOT require per-line quantity ([205]).
        $c = $this->tenant();
        $row = app(MyDataConfigAudit::class)->auditInvoiceType(
            $this->type($c, ['mydata_type' => '11.2', 'mydata_requires_quantity' => true])
        );

        $this->assertSame('warn', $row->status());
        $this->assertStringContainsString('[205]', implode(' ', $row->messages()));
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

    public function test_zero_without_reason_is_error_with_reason_is_ok(): void
    {
        $c = $this->tenant();
        $audit = app(MyDataConfigAudit::class);

        // MYD-007 / MYD-004: a 0% category with NO §8.3 reason is now a BLOCKING
        // error (AADE rejects [217]) — not a warning that let preflight pass.
        $zeroNoReason = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => 'Άνευ', 'rate' => 0, 'is_default' => false,
        ]));
        $this->assertSame('error', $zeroNoReason->status());
        $this->assertStringContainsString('[217]', $zeroNoReason->messages()[0]);

        // …with a valid §8.3 reason it is clean.
        $zeroWithReason = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => '0% ενδοκοιν.', 'rate' => 0,
            'vat_exemption_category' => 4, 'is_default' => false,
        ]));
        $this->assertSame('ok', $zeroWithReason->status());

        $four = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => 'Νησιά', 'rate' => 4, 'is_default' => false,
        ]));
        $std = $audit->auditVatCategory(VatCategory::create([
            'company_id' => $c->id, 'description' => 'Καν.', 'rate' => 24, 'is_default' => true,
        ]));
        $this->assertSame('warn', $four->status()); // 4% is ambiguous (codes 6/10)
        $this->assertSame('ok', $std->status());
    }

    public function test_a_zero_reason_impossible_for_one_of_the_tenants_types_warns(): void
    {
        // The old MYD-007 default: the single 0% category carries 16 (domestic reverse
        // charge) while the tenant issues intra-EU services (2.2) — lines without their
        // own reason would be refused at issue. Flag it before that happens.
        $c = $this->tenant();
        InvoiceType::create(['company_id' => $c->id, 'code' => 'ENY', 'name' => 'Ενδ. υπηρ.', 'invcount' => 1, 'mydata_type' => '2.2']);
        InvoiceType::create(['company_id' => $c->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        VatCategory::create([
            'company_id' => $c->id, 'description' => '0% ενδοκοιν.', 'rate' => 0,
            'vat_exemption_category' => 16, 'is_default' => false,
        ]);

        $row = collect(app(MyDataConfigAudit::class)->audit($c)->vatCategories)->first();

        $this->assertSame('warn', $row->status());
        $this->assertStringContainsString('Αιτία 16 σε τύπο 2.2', $row->messages()[0]);
        $this->assertStringContainsString('σε τύπο 2.2 θα μπλοκάρουν', $row->messages()[0]);

        // The same 16 on a purely domestic tenant is exactly right → clean.
        InvoiceType::query()->where('company_id', $c->id)->where('mydata_type', '2.2')->delete();
        $this->assertSame('ok', collect(app(MyDataConfigAudit::class)->audit($c)->vatCategories)->first()->status());
    }

    public function test_empty_config_warns_on_the_readiness_row(): void
    {
        // A freshly-provisioned tenant with no invoice types / VAT categories must
        // still warn (the preflight behaviour, folded into the readiness row).
        $c = $this->tenant();
        $result = app(MyDataConfigAudit::class)->audit($c);

        $this->assertSame([], $result->invoiceTypes);
        $this->assertSame([], $result->vatCategories);
        $messages = $result->tenant->messages();
        $this->assertContains('Δεν έχουν οριστεί τύποι παραστατικών.', $messages);
        $this->assertContains('Δεν έχουν οριστεί κατηγορίες ΦΠΑ.', $messages);
        $this->assertFalse($result->isClean());
    }

    public function test_unmapped_payment_method_warns_on_the_readiness_row(): void
    {
        // MYD-4: a payment method with no §8.12 type would be filed as cash — warn.
        $c = $this->tenant();
        PaymentMethod::create(['company_id' => $c->id, 'description' => 'Κάρτα', 'due_days' => 0]); // unmapped
        PaymentMethod::create(['company_id' => $c->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]); // mapped

        $paymentWarning = collect(app(MyDataConfigAudit::class)->audit($c)->tenant->messages())
            ->first(fn ($m) => str_contains($m, 'χωρίς αντιστοίχιση myDATA'));

        $this->assertNotNull($paymentWarning);
        // The UNMAPPED method is named in the listing; the mapped one is not.
        $this->assertStringContainsString('«Κάρτα»', $paymentWarning);
    }

    public function test_all_payment_methods_mapped_gives_no_payment_warning(): void
    {
        $c = $this->tenant();
        PaymentMethod::create(['company_id' => $c->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);

        $messages = implode(' | ', app(MyDataConfigAudit::class)->audit($c)->tenant->messages());

        $this->assertStringNotContainsString('Τρόποι πληρωμής χωρίς αντιστοίχιση', $messages);
    }

    public function test_type_7_pos_method_in_use_by_a_provider_tenant_is_an_error(): void
    {
        // POS-1: a type-7 (POS) method referenced by an invoice on a PROVIDER tenant
        // → ERROR. The provider (InvoSign) rejects a bare type-7 outright («88-007 —
        // η υπογραφή δεν είναι έγκυρη») because ekdosi emits no §5.2 POS signature.
        $c = $this->tenant(['einvoice_provider' => 'gr-provider']);
        $pos = PaymentMethod::create([
            'company_id' => $c->id, 'description' => 'Ηλεκτρονικά μέσα Πληρωμών',
            'due_days' => 0, 'mydata_payment_type' => 7,
        ]);
        $this->invoiceUsing($c, $pos);

        $result = app(MyDataConfigAudit::class)->audit($c);
        $posMsg = collect($result->tenant->messages())->first(fn ($m) => str_contains($m, 'POS'));

        $this->assertNotNull($posMsg, 'a type-7 method in use must be flagged');
        $this->assertStringContainsString('«Ηλεκτρονικά μέσα Πληρωμών»', $posMsg);
        $this->assertStringContainsString('88-007', $posMsg);
        $this->assertGreaterThanOrEqual(1, $result->errorCount(), 'provider → ERROR');
    }

    public function test_type_7_pos_method_in_use_by_a_direct_my_data_tenant_warns_not_errors(): void
    {
        // Direct gr-mydata: the SendInvoices signature fields are optional and AADE
        // rejection is unconfirmed, so this is a WARN — surfaced, but NOT a blocking
        // red that would wrongly push the operator to relabel a card payment as cash.
        $c = $this->tenant(); // default einvoice_provider = gr-mydata
        $pos = PaymentMethod::create([
            'company_id' => $c->id, 'description' => 'Ηλεκτρονικά μέσα Πληρωμών',
            'due_days' => 0, 'mydata_payment_type' => 7,
        ]);
        $this->invoiceUsing($c, $pos);

        $result = app(MyDataConfigAudit::class)->audit($c);
        $posMsg = collect($result->tenant->messages())->first(fn ($m) => str_contains($m, 'τύπου 7 (POS) σε χρήση'));

        $this->assertNotNull($posMsg, 'a type-7 method in use is still surfaced for a direct tenant');
        $this->assertSame(0, $result->errorCount(), 'direct → WARN, not a blocking error');
    }

    public function test_pos_guard_flags_only_the_used_method_not_an_unused_type_7(): void
    {
        // Correlation proof: the tenant HAS an invoice (on a NON-POS method) and a
        // SEPARATE, unused type-7 method. A broken subquery that flagged any type-7
        // whenever the tenant has any invoice would name the unused one — it must not.
        $c = $this->tenant(['einvoice_provider' => 'gr-provider']);
        $used = PaymentMethod::create([
            'company_id' => $c->id, 'description' => 'Επαγγ. Λογαριασμός',
            'due_days' => 0, 'mydata_payment_type' => 1,
        ]);
        PaymentMethod::create([
            'company_id' => $c->id, 'description' => 'POS αχρησιμοποίητο',
            'due_days' => 0, 'mydata_payment_type' => 7,
        ]);
        $this->invoiceUsing($c, $used);

        $messages = implode(' | ', app(MyDataConfigAudit::class)->audit($c)->tenant->messages());

        $this->assertStringNotContainsString('POS αχρησιμοποίητο', $messages, 'an unused type-7 method must not flag');
        $this->assertStringNotContainsString('τύπου 7 (POS) σε χρήση', $messages);
    }

    public function test_type_7_pos_method_not_used_is_not_flagged(): void
    {
        // The seeder ships a type-7 «POS / e-POS» row for every tenant; UNUSED it
        // must not turn the audit red — only actual use is the problem (no noise).
        $c = $this->tenant();
        PaymentMethod::create([
            'company_id' => $c->id, 'description' => 'POS / e-POS',
            'due_days' => 0, 'mydata_payment_type' => 7,
        ]);

        $messages = implode(' | ', app(MyDataConfigAudit::class)->audit($c)->tenant->messages());

        $this->assertStringNotContainsString('τύπου 7 (POS) σε χρήση', $messages);
    }

    private function invoiceUsing(Company $c, PaymentMethod $pm): Invoice
    {
        $customer = Customer::create(['company_id' => $c->id, 'name' => 'Πελάτης', 'afm' => '123456789']);

        return Invoice::create([
            'company_id' => $c->id,
            'invcode' => 'TPY'.uniqid(),
            'code' => 1,
            'invoice_type_id' => $this->type($c)->id,
            'customer_id' => $customer->id,
            'payment_method_id' => $pm->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
        ]);
    }

    public function test_full_audit_rolls_up_counts(): void
    {
        $c = $this->tenant();
        $this->type($c);                                   // ok
        $this->type($c, ['mydata_type' => '99.9']);        // 1 error
        VatCategory::create(['company_id' => $c->id, 'description' => '0%', 'rate' => 0, 'is_default' => false]); // MYD-007: now 1 error (no §8.3 reason)
        VatCategory::create(['company_id' => $c->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]); // ok

        $result = app(MyDataConfigAudit::class)->audit($c);

        $this->assertSame(2, $result->errorCount()); // MYD-007: 99.9 type + reason-less 0%
        $this->assertFalse($result->isClean());
        $this->assertCount(2, $result->invoiceTypes);
        $this->assertCount(2, $result->vatCategories);
    }
}
