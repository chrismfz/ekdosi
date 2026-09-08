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

    public function test_reserve_adopts_a_concurrently_assigned_number_instead_of_burning_one(): void
    {
        // finding 2 (holistic review): the local-issuance paths (ViewInvoice finalize /
        // NullSubmitter) hold no per-document single-flight lock, so two reservations of
        // the SAME draft could each read code===null and each allocate — the later save
        // overwriting the earlier and BURNING its number → a gap. reserve() re-reads the
        // row's code under a lock: the loser adopts the winner's number, bumping the
        // counter only ONCE.
        //
        // This asserts the ADOPT path (the loser sees the committed winner code and
        // no-ops): it numbers a fresh instance first, then reserves through the stale one.
        // The lockForUpdate that makes it safe under GENUINE concurrency (two overlapping
        // open transactions) is MariaDB-only — a no-op on sqlite — and is exercised by the
        // `test:invoice-numbering-concurrent` MariaDB CI job, per CLAUDE.md's row-lock note.
        $draft = $this->draft();                       // code=null in this instance

        $winner = Invoice::findOrFail($draft->id);     // a separate instance = the "winner"
        $this->assertTrue(app(InvoiceNumberer::class)->assign($winner), 'winner allocated ΤΠΥ1');

        // A pending edit on the stale instance (finding 1, round 5): the adopt path must
        // sync ONLY the three number columns, never clobber other dirty attributes.
        $draft->company_name = 'ΕΠΩΝΥΜΙΑ ΠΡΙΝ ΤΟ ADOPT';

        // The stale $draft still has code===null in memory; reserve must NOT allocate a 2nd.
        $reserved = app(InvoiceNumberer::class)->assign($draft);

        $this->assertFalse($reserved, 'did not reserve — adopted the existing number');
        $this->assertSame(1, (int) $draft->code, 'in-memory model synced to the winner\'s ΑΑ');
        $this->assertSame('ΤΠΥ1', $draft->invcode);
        $this->assertSame(2, $this->type->fresh()->invcount, 'counter bumped once — no gap');
        $this->assertTrue($draft->isDirty('company_name'), 'the caller\'s pending edit survives the adopt');
    }

    public function test_reserve_throws_and_burns_no_number_for_a_vanished_row(): void
    {
        // findings 2 (round 4 + round 5): if the row is hard-deleted between the outer
        // code===null check and the locked re-read, reserve() must NOT allocate (that would
        // bump the counter into a 0-row UPDATE — a gap with no document) AND must fail
        // loudly/distinctly rather than return the "already numbered, carry on" false.
        $draft = $this->draft();
        Invoice::whereKey($draft->id)->forceDelete();     // row is gone

        try {
            app(InvoiceNumberer::class)->assign($draft);  // stale in-memory model
            $this->fail('expected a loud failure for a vanished row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer exists', $e->getMessage());
        }

        $this->assertSame(1, $this->type->fresh()->invcount, 'counter NOT bumped — no gap');
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
