<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Customers\MergeCustomers;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A PERMANENT delete of a customer must never take money or legal records with it:
 * the FKs CASCADE payments/payment intents and SET NULL the customer on invoices
 * (which keep no copy of the counterparty). The model refuses on every path; the
 * table's bulk «Οριστική διαγραφή» skips such customers. Soft delete stays allowed.
 */
class CustomerHardDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function customer(string $name = 'Πελάτης'): Customer
    {
        return Customer::create(['company_id' => $this->tenant->id, 'name' => $name]);
    }

    private function invoiceFor(Customer $customer): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ'],
            ['name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'],
        );

        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.random_int(1, 99999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(), 'local_status' => 'active',
        ]);
    }

    public function test_force_delete_is_refused_while_the_customer_has_an_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoiceFor($customer);

        try {
            $customer->forceDelete();
            $this->fail('forceDelete should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('παραστατικά: 1', $e->getMessage());
        }

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertSame($customer->id, $invoice->fresh()->customer_id, 'the invoice keeps its counterparty');
    }

    public function test_force_delete_is_refused_while_the_customer_has_a_payment(): void
    {
        $customer = $this->customer();
        $payment = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'invoice_id' => null, 'amount' => 50, 'pay_date' => now()->toDateString(),
        ]);

        $this->expectException(RuntimeException::class);
        try {
            $customer->forceDelete();
        } finally {
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);   // not cascaded away
        }
    }

    public function test_a_soft_deleted_invoice_still_blocks_it(): void
    {
        // A trashed invoice is still a legal document (and restorable).
        $customer = $this->customer();
        $this->invoiceFor($customer)->delete();

        $this->expectException(RuntimeException::class);
        $customer->forceDelete();
    }

    public function test_a_customer_with_nothing_attached_can_be_force_deleted(): void
    {
        $customer = $this->customer();

        $customer->forceDelete();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_soft_delete_stays_allowed_with_invoices(): void
    {
        $customer = $this->customer();
        $this->invoiceFor($customer);

        $customer->delete();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_merge_repoints_what_the_guard_counts_so_the_loser_can_go(): void
    {
        // customers:merge force-deletes the losing row. A portal payment intent
        // (and a ticket) on it used to be CASCADE-deleted / orphaned; now the merge
        // repoints them to the keeper, so the guard lets the loser go.
        $keep = $this->customer('Κρατάμε');
        $drop = $this->customer('Σβήνουμε');
        $this->invoiceFor($drop);
        $intent = PaymentIntent::create([
            'company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'gateway' => 'manual',
            'amount' => 10, 'currency' => 'EUR', 'status' => PaymentIntent::STATUS_PENDING, 'reference' => 'ΠΛ-'.uniqid(),
        ]);

        $tld = DomainTld::create(['company_id' => $this->tenant->id, 'tld' => 'gr', 'is_active' => true]);
        $domain = Domain::create([
            'company_id' => $this->tenant->id, 'domain_tld_id' => $tld->id, 'customer_id' => $drop->id,
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr', 'status' => 'active',
        ]);
        $ticketId = DB::table('tickets')->insertGetId([
            'company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'reference' => 'T-'.uniqid(),
            'subject' => 'Βοήθεια', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(MergeCustomers::class)($keep, $drop);

        $this->assertDatabaseMissing('customers', ['id' => $drop->id]);
        $this->assertSame($keep->id, $intent->fresh()->customer_id, 'the payment intent followed the keeper');
        $this->assertSame($keep->id, $domain->fresh()->customer_id, 'the domain followed the keeper (RESTRICT FK)');
        $this->assertSame($keep->id, (int) DB::table('tickets')->where('id', $ticketId)->value('customer_id'), 'the ticket was not orphaned');
    }

    public function test_the_bulk_force_delete_skips_customers_in_use_and_deletes_the_rest(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        $inUse = $this->customer('Με τιμολόγιο');
        $this->invoiceFor($inUse);
        $free = $this->customer('Χωρίς τίποτα');
        $inUse->delete();
        $free->delete();

        Livewire::test(ListCustomers::class)
            ->filterTable('trashed', false)   // «only trashed» → permanent delete is visible
            ->callTableBulkAction('forceDelete', [$inUse->getKey(), $free->getKey()]);

        $this->assertDatabaseHas('customers', ['id' => $inUse->id]);
        $this->assertDatabaseMissing('customers', ['id' => $free->id]);
    }
}
