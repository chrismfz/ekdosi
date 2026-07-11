<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * #6 — due date + Ληξιπρόθεσμο. dueDate()/isOverdue()/scopeOverdue must agree
 * and never flag cash-term, paid, draft, cancelled or credit-note invoices.
 */
class OverdueInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private int $creditMethodId;

    private int $cashMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Due', 'slug' => 'due-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->typeId = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
        $this->cashMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0])->id;
    }

    private function make(string $code, Carbon $issued, int $methodId, string $status = 'unpaid', string $local = 'active', array $extra = []): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => $issued,
            'local_status' => $local, 'payment_method_id' => $methodId,
        ]);
        // net/gross/payment_status/mydata_state are money/AADE-cache columns —
        // not fillable, written via forceFill (as the real services do).
        $inv->forceFill(['net_total' => 100, 'gross_total' => 100, 'payment_status' => $status] + $extra)->save();

        return $inv->fresh(['paymentMethod']);
    }

    public function test_credit_term_past_due_with_open_balance_is_overdue(): void
    {
        $inv = $this->make('ΤΙΜ1', now()->subDays(40), $this->creditMethodId);

        $this->assertNotNull($inv->dueDate());
        $this->assertTrue($inv->isOverdue());
        $this->assertTrue(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }

    public function test_cash_term_never_overdue(): void
    {
        $inv = $this->make('ΤΙΜ2', now()->subDays(40), $this->cashMethodId, status: 'paid');

        $this->assertNull($inv->dueDate());
        $this->assertFalse($inv->isOverdue());
        $this->assertFalse(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }

    public function test_within_term_not_overdue(): void
    {
        $inv = $this->make('ΤΙΜ3', now()->subDays(5), $this->creditMethodId);

        $this->assertFalse($inv->isOverdue());
        $this->assertFalse(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }

    public function test_paid_past_due_not_overdue(): void
    {
        $inv = $this->make('ΤΙΜ4', now()->subDays(40), $this->creditMethodId, status: 'paid');

        $this->assertFalse($inv->isOverdue());
        $this->assertFalse(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }

    public function test_partial_past_due_is_overdue(): void
    {
        $inv = $this->make('ΤΙΜ5', now()->subDays(40), $this->creditMethodId, status: 'partial');

        $this->assertTrue($inv->isOverdue());
        $this->assertTrue(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }

    public function test_standalone_legacy_credit_note_not_overdue(): void
    {
        // MON-9: a standalone legacy ΠΙΣ (is_credit type, no credited_invoice_id),
        // credit-term + past due + unpaid, must NOT read as an overdue receivable —
        // else it gets dunned (invoices:notify-overdue).
        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'ΠΙΣ',
            'invcount' => 1, 'mydata_type' => '5.2', 'is_credit' => true,
        ]);
        $inv = $this->make('ΠΙΣ1', now()->subDays(40), $this->creditMethodId, extra: []);
        $inv->update(['invoice_type_id' => $creditType->id]);

        $this->assertFalse(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }

    public function test_draft_and_cancelled_not_overdue(): void
    {
        $draft = $this->make('ΤΙΜ6', now()->subDays(40), $this->creditMethodId, local: 'draft');
        $cancelled = $this->make('ΤΙΜ7', now()->subDays(40), $this->creditMethodId, local: 'cancelled');

        $this->assertFalse($draft->isOverdue());
        $this->assertFalse($cancelled->isOverdue());
        $this->assertSame(0, Invoice::query()->whereIn('id', [$draft->id, $cancelled->id])->overdue()->count());
    }

    public function test_aade_cancelled_not_overdue(): void
    {
        $inv = $this->make('ΤΙΜ8', now()->subDays(40), $this->creditMethodId, extra: ['mydata_state' => 'CANCELLED']);

        $this->assertFalse($inv->isOverdue());
        $this->assertFalse(Invoice::query()->whereKey($inv->id)->overdue()->exists());
    }
}
