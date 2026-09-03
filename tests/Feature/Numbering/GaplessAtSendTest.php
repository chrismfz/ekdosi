<?php

namespace Tests\Feature\Numbering;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\InvoiceNumberer;
use App\Support\ProvisionalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gapless-at-send numbering (reverses MON-4): a draft carries a provisional
 * identity and consumes no ΑΑ; the real number is allocated only at transmission
 * (InvoiceNumberer::assign) and returned to the pool on a definitive rejection
 * (::release). So the sequence the ΑΑΔΕ sees is always continuous, whatever is
 * drafted or abandoned. MariaDB-only (assign/release take a row lock).
 */
class GaplessAtSendTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800000000',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
    }

    private function draft(): Invoice
    {
        // The draft flow: create WITHOUT a code — the created hook fills a provisional.
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
        ]);
    }

    public function test_a_draft_is_provisional_and_consumes_no_number(): void
    {
        $draft = $this->draft();

        $this->assertNull($draft->code, 'no ΑΑ on a draft');
        $this->assertSame('ΠΡΟΣ-ΤΠΥ-'.$draft->id, $draft->invcode);
        $this->assertTrue(ProvisionalCode::is($draft->invcode));
        $this->assertNull($draft->series, 'series not frozen until send');
        $this->assertSame(1, $this->type->fresh()->invcount, 'counter untouched by a draft');
    }

    public function test_assign_allocates_the_real_number_and_freezes_series(): void
    {
        $draft = $this->draft();

        app(InvoiceNumberer::class)->assign($draft);

        $draft->refresh();
        $this->assertSame(1, (int) $draft->code);
        $this->assertSame('ΤΠΥ1', $draft->invcode);
        $this->assertSame('ΤΠΥ', $draft->series);
        $this->assertSame(2, $this->type->fresh()->invcount, 'counter advanced once');
    }

    public function test_assign_is_idempotent_on_an_already_numbered_invoice(): void
    {
        $draft = $this->draft();
        app(InvoiceNumberer::class)->assign($draft);
        app(InvoiceNumberer::class)->assign($draft->fresh()); // retry after ambiguous

        $this->assertSame(1, (int) $draft->fresh()->code);
        $this->assertSame(2, $this->type->fresh()->invcount, 'no second number burned');
    }

    public function test_release_returns_the_top_number_to_the_pool(): void
    {
        $draft = $this->draft();
        app(InvoiceNumberer::class)->assign($draft);
        $this->assertSame(2, $this->type->fresh()->invcount);

        app(InvoiceNumberer::class)->release($draft->fresh());

        $draft->refresh();
        $this->assertNull($draft->code, 'reverted to provisional');
        $this->assertSame('ΠΡΟΣ-ΤΠΥ-'.$draft->id, $draft->invcode);
        $this->assertSame(1, $this->type->fresh()->invcount, 'counter rolled back — no gap');
    }

    public function test_abandoned_drafts_leave_the_transmitted_sequence_gapless(): void
    {
        // Three drafts made; only the middle-created one is ever "sent".
        $a = $this->draft();
        $b = $this->draft();
        $c = $this->draft();

        app(InvoiceNumberer::class)->assign($b);
        app(InvoiceNumberer::class)->assign($a);

        // The two SENT documents are 1 and 2 (issuance order), not 1 and 3 —
        // the never-sent $c burned nothing.
        $this->assertSame(1, (int) $b->fresh()->code);
        $this->assertSame(2, (int) $a->fresh()->code);
        $this->assertNull($c->fresh()->code);
    }
}
