<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use App\Services\InvoiceNumberer;
use App\Support\ProvisionalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gapless-at-send numbering for δελτία αποστολής (Phase 2, reverses MON-4): a draft
 * carries a provisional identity and consumes no ΑΑ; the real number is allocated
 * only at transmission (InvoiceNumberer::assignDelivery) and returned to the pool on
 * a definitive rejection (::releaseDelivery). So the 9.x sequence the ΑΑΔΕ sees is
 * always continuous, whatever is drafted or abandoned. Twin of Numbering\GaplessAtSendTest.
 * MariaDB-only for the concurrency guarantee (assign/release take a row lock).
 */
class DeliveryGaplessAtSendTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800000000',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΔΑΠ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
    }

    private function draft(): DeliveryNote
    {
        // The draft flow: create WITHOUT a code — the created hook fills a provisional.
        return DeliveryNote::create([
            'company_id' => $this->tenant->id,
            'delivery_type_id' => $this->type->id,
            'issued_at' => now(),
            'move_purpose' => 8,
        ]);
    }

    public function test_a_draft_is_provisional_and_consumes_no_number(): void
    {
        $draft = $this->draft();

        $this->assertNull($draft->code, 'no ΑΑ on a draft');
        $this->assertSame('ΠΡΟΣ-ΔΑΠ-'.$draft->id, $draft->invcode);
        $this->assertTrue(ProvisionalCode::is($draft->invcode));
        $this->assertNull($draft->series, 'series not frozen until send');
        $this->assertSame(1, $this->type->fresh()->invcount, 'counter untouched by a draft');
    }

    public function test_assign_delivery_allocates_the_real_number_and_freezes_series(): void
    {
        $draft = $this->draft();

        app(InvoiceNumberer::class)->assignDelivery($draft);

        $draft->refresh();
        $this->assertSame(1, (int) $draft->code);
        $this->assertSame('ΔΑΠ1', $draft->invcode);
        $this->assertSame('ΔΑΠ', $draft->series);
        $this->assertSame(2, $this->type->fresh()->invcount, 'counter advanced once');
    }

    public function test_assign_delivery_is_idempotent_on_an_already_numbered_note(): void
    {
        $draft = $this->draft();
        app(InvoiceNumberer::class)->assignDelivery($draft);
        app(InvoiceNumberer::class)->assignDelivery($draft->fresh()); // retry after ambiguous

        $this->assertSame(1, (int) $draft->fresh()->code);
        $this->assertSame(2, $this->type->fresh()->invcount, 'no second number burned');
    }

    public function test_release_delivery_returns_the_top_number_to_the_pool(): void
    {
        $draft = $this->draft();
        app(InvoiceNumberer::class)->assignDelivery($draft);
        $this->assertSame(2, $this->type->fresh()->invcount);

        app(InvoiceNumberer::class)->releaseDelivery($draft->fresh());

        $draft->refresh();
        $this->assertNull($draft->code, 'reverted to provisional');
        $this->assertSame('ΠΡΟΣ-ΔΑΠ-'.$draft->id, $draft->invcode);
        $this->assertNull($draft->series);
        $this->assertSame(1, $this->type->fresh()->invcount, 'counter rolled back — no gap');
    }

    public function test_assign_delivery_adopts_a_concurrently_assigned_number_instead_of_burning_one(): void
    {
        // finding 2 (holistic review): two reservations of the SAME ΔΑ draft must not each
        // allocate (which would burn the first number → a gap). reserve() re-reads the
        // row's code under a lock: the loser adopts the winner's number, counter bumps once.
        // Asserts the ADOPT path; the lockForUpdate itself is MariaDB-only (no-op on sqlite).
        $draft = $this->draft();                            // code=null in this instance

        $winner = DeliveryNote::findOrFail($draft->id);      // a separate instance
        $this->assertTrue(app(InvoiceNumberer::class)->assignDelivery($winner), 'winner allocated ΔΑΠ1');

        $reserved = app(InvoiceNumberer::class)->assignDelivery($draft);

        $this->assertFalse($reserved, 'did not reserve — adopted the existing number');
        $this->assertSame(1, (int) $draft->code, 'in-memory model synced to the winner\'s ΑΑ');
        $this->assertSame('ΔΑΠ1', $draft->invcode);
        $this->assertSame(2, $this->type->fresh()->invcount, 'counter bumped once — no gap');
    }

    public function test_abandoned_drafts_leave_the_transmitted_sequence_gapless(): void
    {
        // Three drafts made; only two are ever "sent", in a different order.
        $a = $this->draft();
        $b = $this->draft();
        $c = $this->draft();

        app(InvoiceNumberer::class)->assignDelivery($b);
        app(InvoiceNumberer::class)->assignDelivery($a);

        // The two SENT δελτία are 1 and 2 (issuance order), not 1 and 3 — the
        // never-sent $c burned nothing.
        $this->assertSame(1, (int) $b->fresh()->code);
        $this->assertSame(2, (int) $a->fresh()->code);
        $this->assertNull($c->fresh()->code);
    }
}
