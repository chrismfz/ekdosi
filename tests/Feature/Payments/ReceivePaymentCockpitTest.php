<?php

namespace Tests\Feature\Payments;

use App\Filament\Resources\Payments\Pages\ReceivePayment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\InvoiceBalance;
use App\Services\Payments\PaymentAllocator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Νέα είσπραξη» cockpit: PaymentAllocator::allocateSelected / openInvoiceRows
 * (the write + the read the screen is built on) and the Livewire page itself.
 */
class ReceivePaymentCockpitTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private int $creditMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'RC', 'slug' => 'rc-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'NEW MOTION', 'afm' => '997674122']);
        $this->typeId = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '2.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
    }

    private function invoice(string $code, float $gross, int $daysAgo = 5, ?Customer $customer = null, string $status = 'active'): Invoice
    {
        $customer ??= $this->customer;
        $inv = Invoice::create([
            'company_id' => $customer->company_id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $customer->id, 'issued_at' => now()->subDays($daysAgo),
            'local_status' => $status, 'payment_method_id' => $this->creditMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function balance(Invoice $inv): float
    {
        return (float) app(InvoiceBalance::class)->for($inv->fresh())->balance;
    }

    private function bootPanel(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    /* ---------------- allocator ---------------- */

    public function test_open_invoice_rows_are_fifo_with_live_balances(): void
    {
        $new = $this->invoice('ΤΠΥ2', 200, 2);
        $old = $this->invoice('ΤΠΥ1', 300, 40);
        $paid = $this->invoice('ΤΠΥ3', 50, 10);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $paid->id, 'kind' => 'payment', 'amount' => 50, 'pay_date' => now()]);
        $this->invoice('ΤΠΥ4', 99, 1, null, 'draft');

        $rows = app(PaymentAllocator::class)->openInvoiceRows($this->customer);

        $this->assertSame([$old->id, $new->id], array_keys($rows), 'oldest first; paid + draft excluded');
        $this->assertSame(300.0, $rows[$old->id]['balance']);
    }

    public function test_allocate_selected_splits_and_parks_rest_on_account(): void
    {
        $a = $this->invoice('ΤΠΥ1', 300, 40);
        $b = $this->invoice('ΤΠΥ2', 200, 2);

        $res = app(PaymentAllocator::class)->allocateSelected(
            $this->customer, 450, [['invoice_id' => $b->id, 'amount' => 200], ['invoice_id' => $a->id, 'amount' => 100]], now()
        );

        $this->assertSame(0.0, $this->balance($b));
        $this->assertSame(200.0, $this->balance($a));
        $this->assertSame(150.0, $res->onAccount);
        $this->assertSame(450.0, (float) Payment::where('reference', $res->reference)->sum('amount'), 'one shared reference');
        $this->assertSame(['ΤΠΥ1', 'ΤΠΥ2'], array_column($res->allocations, 'invcode'), 'written in FIFO order');
    }

    public function test_allocate_selected_caps_at_live_balance(): void
    {
        $a = $this->invoice('ΤΠΥ1', 100);
        // Paid meanwhile by someone else → only €40 still open.
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $a->id, 'kind' => 'payment', 'amount' => 60, 'pay_date' => now()]);

        $res = app(PaymentAllocator::class)->allocateSelected($this->customer, 100, [['invoice_id' => $a->id, 'amount' => 100]], now());

        $this->assertSame(0.0, $this->balance($a), 'never overpaid');
        $this->assertSame(60.0, $res->onAccount, 'the excess becomes credit');
    }

    public function test_allocate_selected_rejects_foreign_or_ineligible_invoice(): void
    {
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλλος']);
        $foreign = $this->invoice('ΤΠΥ9', 100, 5, $other);
        $draft = $this->invoice('ΤΠΥ8', 100, 5, null, 'draft');

        foreach ([$foreign, $draft] as $inv) {
            try {
                app(PaymentAllocator::class)->allocateSelected($this->customer, 100, [['invoice_id' => $inv->id, 'amount' => 100]], now());
                $this->fail('expected rejection for '.$inv->invcode);
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, Payment::count(), 'nothing written');
    }

    public function test_allocate_selected_rejects_lines_above_total(): void
    {
        $a = $this->invoice('ΤΠΥ1', 300);

        $this->expectException(InvalidArgumentException::class);
        app(PaymentAllocator::class)->allocateSelected($this->customer, 100, [['invoice_id' => $a->id, 'amount' => 200]], now());
    }

    /* ---------------- page ---------------- */

    public function test_page_lists_debtors_biggest_first(): void
    {
        $small = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Μικρός']);
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Μηδενικός']);
        $this->invoice('ΤΠΥ1', 500);
        $this->invoice('ΤΠΥ2', 50, 5, $small);
        $this->bootPanel();

        $rows = Livewire::test(ReceivePayment::class)->instance()->customerRows();

        $this->assertSame(['NEW MOTION', 'Μικρός'], array_column($rows, 'name'), 'only debtors, by balance desc');
    }

    public function test_page_search_finds_non_debtors_too(): void
    {
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Μηδενικός', 'afm' => '111222333']);
        $this->bootPanel();

        $rows = Livewire::test(ReceivePayment::class)->set('search', '111222')->instance()->customerRows();

        $this->assertSame(['Μηδενικός'], array_column($rows, 'name'));
    }

    public function test_selecting_customer_prefills_balance_and_fifo_split(): void
    {
        $old = $this->invoice('ΤΠΥ1', 300, 40);
        $new = $this->invoice('ΤΠΥ2', 200, 2);
        $this->bootPanel();

        $page = Livewire::test(ReceivePayment::class)
            ->call('selectCustomer', $this->customer->id)
            ->assertSet('amount', '500,00')
            ->assertSee('ΤΠΥ1')
            ->set('amount', '350');

        $this->assertSame('300,00', $page->get('lines')[$old->id]['amount']);
        $this->assertSame('50,00', $page->get('lines')[$new->id]['amount']);

        $page->call('save');

        $this->assertSame(0.0, $this->balance($old));
        $this->assertSame(150.0, $this->balance($new));
        $this->assertSame(0, Payment::whereNull('invoice_id')->count(), 'no on-account remainder');
    }

    public function test_unticked_invoice_is_skipped_and_rest_goes_on_account(): void
    {
        $old = $this->invoice('ΤΠΥ1', 300, 40);
        $new = $this->invoice('ΤΠΥ2', 200, 2);
        $this->bootPanel();

        Livewire::test(ReceivePayment::class, ['customerId' => $this->customer->id])
            ->set("lines.{$old->id}.on", false)
            ->set('amount', '250')
            ->call('save');

        $this->assertSame(300.0, $this->balance($old), 'untouched');
        $this->assertSame(0.0, $this->balance($new));
        $this->assertSame(50.0, (float) Payment::whereNull('invoice_id')->sum('amount'));
    }

    public function test_line_above_balance_is_refused(): void
    {
        $a = $this->invoice('ΤΠΥ1', 100);
        $this->bootPanel();

        Livewire::test(ReceivePayment::class, ['customerId' => $this->customer->id])
            ->set('amount', '150')
            ->set("lines.{$a->id}.amount", '150')
            ->call('save');

        $this->assertSame(0, Payment::count());
    }

    public function test_other_tenants_customer_cannot_be_selected(): void
    {
        $otherTenant = Company::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'country_code' => 'GR']);
        $stranger = Customer::create(['company_id' => $otherTenant->id, 'name' => 'Ξένος']);
        $this->bootPanel();

        Livewire::test(ReceivePayment::class)
            ->call('selectCustomer', $stranger->id)
            ->assertSet('customerId', null)
            ->call('save');

        $this->assertSame(0, Payment::count());
    }

    public function test_apply_credit_fifo_uses_on_account_money(): void
    {
        $old = $this->invoice('ΤΠΥ1', 100, 40);
        $new = $this->invoice('ΤΠΥ2', 100, 2);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => null, 'kind' => 'payment', 'amount' => 150, 'pay_date' => now()]);
        $this->bootPanel();

        Livewire::test(ReceivePayment::class, ['customerId' => $this->customer->id])->call('applyCreditFifo');

        $this->assertSame(0.0, $this->balance($old));
        $this->assertSame(50.0, $this->balance($new));
        $this->assertSame(0.0, app(PaymentAllocator::class)->availableCredit($this->customer));
    }

    public function test_amount_parsing_handles_greek_thousands(): void
    {
        $this->bootPanel();
        $page = Livewire::test(ReceivePayment::class)->instance();

        $this->assertSame(1234.0, $page->parseAmount('1.234'));
        $this->assertSame(1234.56, $page->parseAmount('1.234,56'));
        $this->assertSame(430.0, $page->parseAmount('430.00'));
        $this->assertSame(1.5, $page->parseAmount('1.5'));
        $this->assertNull($page->parseAmount('abc'));
    }

    public function test_manual_line_survives_amount_change(): void
    {
        $old = $this->invoice('ΤΠΥ1', 300, 40);
        $new = $this->invoice('ΤΠΥ2', 200, 2);
        $this->bootPanel();

        $page = Livewire::test(ReceivePayment::class, ['customerId' => $this->customer->id])
            ->set("lines.{$new->id}.amount", '200')
            ->set('amount', '250');

        $this->assertSame('200', $page->get('lines')[$new->id]['amount'], 'operator override kept');
        $this->assertSame('50,00', $page->get('lines')[$old->id]['amount'], 'the rest refilled FIFO');

        $page->call('redistribute');
        $this->assertSame('250,00', $page->get('lines')[$old->id]['amount']);
    }

    public function test_save_asks_confirmation_only_when_money_goes_on_account(): void
    {
        $this->invoice('ΤΠΥ1', 100);
        $this->bootPanel();

        Livewire::test(ReceivePayment::class, ['customerId' => $this->customer->id])
            ->assertDontSeeHtml('θα καταχωριστούν ως πίστωση')
            ->set('amount', '150')
            ->assertSeeHtml('θα καταχωριστούν ως πίστωση');
    }

    public function test_list_says_when_more_debtors_exist(): void
    {
        for ($i = 0; $i < 41; $i++) {
            $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π'.$i]);
            $this->invoice('ΤΠΥX'.$i, 10 + $i, 5, $c);
        }
        $this->bootPanel();

        $page = Livewire::test(ReceivePayment::class)->assertSee('αναζήτησε για τους υπόλοιπους');
        $this->assertCount(40, $page->instance()->customerRows());
    }

    public function test_page_requires_create_permission(): void
    {
        $this->actingAs(User::create(['name' => 'N', 'email' => 'n-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        $this->assertFalse(ReceivePayment::canAccess());
    }
}
