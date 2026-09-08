<?php

namespace Tests\Feature\Payments;

use App\Filament\Resources\Invoices\RelationManagers\InvoicePaymentsRelationManager;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Guard: «Καταχώριση πληρωμής» must be UNAVAILABLE on a credit note — both one
 * issued against an original (credited_invoice_id) AND a standalone credit-TYPE
 * invoice (invoice_types.is_credit). Recording a payment on a πιστωτικό would
 * push it into the receivables owed base while the ledger treats it as a credit
 * → dashboard ≠ ledger by 2×gross. We test the canRecordPayment() decision.
 */
class CreditNotePaymentGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30]);
    }

    private function invoice(InvoiceType $type, array $extra = []): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'X'.random_int(1, 99999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $this->credit->id,
        ] + $extra);
        $inv->forceFill(['net_total' => 100, 'gross_total' => 100])->save();

        return $inv->fresh(['invoiceType']);
    }

    private function canRecordPayment(Invoice $invoice): bool
    {
        $rm = new InvoicePaymentsRelationManager;
        $owner = new ReflectionProperty($rm, 'ownerRecord');
        $owner->setAccessible(true);
        $owner->setValue($rm, $invoice);

        $method = new ReflectionMethod($rm, 'canRecordPayment');
        $method->setAccessible(true);

        return (bool) $method->invoke($rm);
    }

    public function test_normal_sale_can_record_payment(): void
    {
        $sale = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $this->assertTrue($this->canRecordPayment($this->invoice($sale)));
    }

    public function test_standalone_credit_type_invoice_cannot_record_payment(): void
    {
        $creditType = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΤ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'is_credit' => true]);
        $this->assertFalse($this->canRecordPayment($this->invoice($creditType)));
    }

    public function test_credit_note_against_original_cannot_record_payment(): void
    {
        $sale = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $original = $this->invoice($sale);
        $this->assertFalse($this->canRecordPayment($this->invoice($sale, ['credited_invoice_id' => $original->id])));
    }

    public function test_cancelled_invoice_cannot_record_payment(): void
    {
        $sale = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $inv = $this->invoice($sale);
        $inv->forceFill(['mydata_state' => 'CANCELLED'])->save();
        $this->assertFalse($this->canRecordPayment($inv->fresh(['invoiceType'])));
    }
}
