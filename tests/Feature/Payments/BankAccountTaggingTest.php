<?php

namespace Tests\Feature\Payments;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L2 — a payment can be tagged with the bank account the money landed in, and
 * the έμβασμα allocator stamps it on every row of the group. Informational
 * only: no money/balance behaviour changes.
 */
class BankAccountTaggingTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'BA', 'slug' => 'ba-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->account = BankAccount::create([
            'company_id' => $this->tenant->id,
            'bank_name' => 'Πειραιώς',
            'iban' => 'GR1601100000000000000000001',
            'is_active' => true,
        ]);
    }

    public function test_label_and_active_options(): void
    {
        $this->assertSame('Πειραιώς — GR1601100000000000000000001', $this->account->label());
        $this->assertSame(
            [$this->account->id => 'Πειραιώς — GR1601100000000000000000001'],
            BankAccount::activeOptions($this->tenant->id),
        );

        // inactive accounts are hidden from the pickers
        $this->account->update(['is_active' => false]);
        $this->assertSame([], BankAccount::activeOptions($this->tenant->id));
    }

    public function test_belongs_to_tenant_guards_cross_tenant_ids(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'oth-'.uniqid(), 'country_code' => 'GR']);
        $foreign = BankAccount::create(['company_id' => $other->id, 'bank_name' => 'Alpha', 'is_active' => true]);

        $this->assertTrue(BankAccount::belongsToTenant($this->account->id, $this->tenant->id));
        $this->assertFalse(BankAccount::belongsToTenant($foreign->id, $this->tenant->id), 'foreign tenant account rejected');
        $this->assertFalse(BankAccount::belongsToTenant($this->account->id, null), 'no tenant context rejected');
    }

    public function test_allocator_stamps_bank_account_on_every_row(): void
    {
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30]);

        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $credit->id,
        ]);
        $inv->forceFill(['net_total' => 500, 'gross_total' => 500])->save();

        // €800 over a €500 invoice → 1 allocation + on-account remainder, both
        // carrying the bank account id.
        $res = app(PaymentAllocator::class)->allocate(
            $this->customer, 800, now(), $credit->id, null, null, null, $this->account->id,
        );

        $rows = Payment::where('reference', $res->reference)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($this->account->id, $row->bank_account_id);
        }
    }
}
