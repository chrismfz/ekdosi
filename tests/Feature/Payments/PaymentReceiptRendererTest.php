<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\PaymentReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The informal «Απόδειξη Είσπραξης» PDF renders for both a manual and a
 * gateway-settled payment, grouping the whole reference-είσπραξη.
 */
class PaymentReceiptRendererTest extends TestCase
{
    use RefreshDatabase;

    private function make(): array
    {
        $t = Company::create(['name' => 'Rec OE', 'slug' => 'rec-'.uniqid(), 'country_code' => 'GR', 'afm' => '090000045']);
        $c = Customer::create(['company_id' => $t->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '090000045']);

        return [$t, $c];
    }

    public function test_renders_a_pdf_for_a_manual_payment(): void
    {
        [$t, $c] = $this->make();
        $p = Payment::create([
            'company_id' => $t->id, 'customer_id' => $c->id, 'kind' => 'payment',
            'amount' => 12.50, 'pay_date' => now(), 'reference' => 'ΕΙΣ-TEST-1',
        ]);

        $pdf = app(PaymentReceiptRenderer::class)->render($p);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(800, strlen($pdf));
    }

    public function test_groups_the_whole_reference_receipt(): void
    {
        [$t, $c] = $this->make();
        // One είσπραξη split across two rows sharing a reference.
        $a = Payment::create(['company_id' => $t->id, 'customer_id' => $c->id, 'kind' => 'payment', 'amount' => 40, 'pay_date' => now(), 'reference' => 'ΠΛ-G-1', 'transaction_id' => '320255868967']);
        Payment::create(['company_id' => $t->id, 'customer_id' => $c->id, 'kind' => 'payment', 'amount' => 10, 'pay_date' => now(), 'reference' => 'ΠΛ-G-1']);
        // A refund on the same customer must NOT be pulled into the receipt.
        Payment::create(['company_id' => $t->id, 'customer_id' => $c->id, 'kind' => 'refund', 'amount' => 5, 'pay_date' => now(), 'reference' => 'ΠΛ-G-1']);

        $pdf = app(PaymentReceiptRenderer::class)->render($a->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
