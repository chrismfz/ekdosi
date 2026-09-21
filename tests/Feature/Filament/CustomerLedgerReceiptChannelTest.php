<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The operator Καρτέλα's «Κατανομή» modal now surfaces «από πού ήρθε» for a grouped
 * receipt (channel / method / transaction), resolved lazily from the group's
 * payment_ids — sharing the Payment::channelLabel seam. Only fields UNIFORM across
 * the group are shown (a differing one is hidden), and the channel collapses to
 * «Πολλαπλά κανάλια» when members differ.
 */
class CustomerLedgerReceiptChannelTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'lrc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.l', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'K', 'afm' => '1']);
    }

    /** @return array{channel: ?string, transaction_id: ?string, method: ?string} */
    private function channelInfo(array $paymentIds): array
    {
        $page = new CustomerLedger;
        $page->record = $this->customer;
        $m = new ReflectionMethod($page, 'receiptChannelInfo');
        $m->setAccessible(true);

        return $m->invoke($page, $paymentIds);
    }

    private function payment(string $reference, ?string $txn, float $amount = 10, ?int $methodId = null, ?int $intentId = null): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'kind' => 'payment', 'amount' => $amount, 'pay_date' => now(),
            'reference' => $reference, 'transaction_id' => $txn,
            'payment_method_id' => $methodId, 'payment_intent_id' => $intentId,
        ]);
    }

    #[Test]
    public function it_shows_uniform_channel_transaction_and_method_for_a_group(): void
    {
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Κάρτα', 'due_days' => 0]);
        $p1 = $this->payment('R1', 'TXN-1', 10, $pm->id);
        $p2 = $this->payment('R1', 'TXN-1', 20, $pm->id);

        $info = $this->channelInfo([$p1->id, $p2->id]);

        $this->assertSame('Χειροκίνητα', $info['channel']);   // manual (no gateway intent)
        $this->assertSame('TXN-1', $info['transaction_id']);
        $this->assertSame('Κάρτα', $info['method']);
    }

    #[Test]
    public function it_collapses_to_multiple_channels_when_members_differ(): void
    {
        $intent = PaymentIntent::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'gateway' => 'eurobank', 'amount' => 20, 'currency' => 'EUR', 'status' => 'settled',
            'reference' => 'PI-TEST-1',
        ]);
        $manual = $this->payment('R3', null, 10);                       // → Χειροκίνητα
        $portal = $this->payment('R3', null, 20, null, $intent->id);    // → Πύλη · …

        $info = $this->channelInfo([$manual->id, $portal->id]);

        $this->assertSame('Πολλαπλά κανάλια', $info['channel']);
    }

    #[Test]
    public function it_excludes_a_foreign_customer_payment(): void
    {
        // Tenant/customer scoping: a payment id of ANOTHER customer must not resolve.
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Other', 'afm' => '2']);
        $foreign = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $other->id,
            'kind' => 'payment', 'amount' => 10, 'pay_date' => now(),
            'reference' => 'RX', 'transaction_id' => 'LEAK',
        ]);

        $info = $this->channelInfo([$foreign->id]);

        $this->assertNull($info['channel']);          // not resolved → nothing shown
        $this->assertNull($info['transaction_id']);   // never leaks 'LEAK'
    }

    #[Test]
    public function it_hides_a_transaction_id_that_differs_across_the_group(): void
    {
        $p1 = $this->payment('R2', 'AAA', 10);
        $p2 = $this->payment('R2', 'BBB', 20);

        $info = $this->channelInfo([$p1->id, $p2->id]);

        $this->assertSame('Χειροκίνητα', $info['channel']);   // channel still uniform
        $this->assertNull($info['transaction_id']);           // differs → hidden
    }

    #[Test]
    public function it_returns_nulls_for_no_payment_ids(): void
    {
        $info = $this->channelInfo([]);

        $this->assertNull($info['channel']);
        $this->assertNull($info['transaction_id']);
        $this->assertNull($info['method']);
    }
}
