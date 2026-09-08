<?php

namespace Tests\Feature\Invoice;

use App\Actions\ReissueInvoiceAsDraft;
use App\Contracts\EInvoiceSubmitter;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\EInvoiceSubmitterFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * PROV-019: separate the LOCAL commercial reduction (isFullyCredited — counts draft
 * credits) from the LEGAL reversal at AADE (isLegallyReversed — requires the credit
 * to be filed VALID, or the original directly CANCELLED). A replacement created off
 * a not-yet-legally-reversed original is flagged (replacementReversalPending) so
 * filing it can soft-warn about the double-turnover it would declare.
 */
class Prov019LegalReversalTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Prov ΑΕ', 'slug' => 'p19-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'afm' => '800561849',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 5, 'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);
    }

    /** A VALID (live-at-AADE) original with money caches populated. */
    private function validOriginal(): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(), 'local_status' => 'active',
            'company_name' => 'Π', 'vat_no' => '997073525',
        ]);
        $inv->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Υ', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);
        $inv->forceFill([
            'mydata_state' => 'VALID', 'mydata_mark' => '400000000000001',
            'gross_total' => 124, 'credited_total' => 124, // fully reduced locally
        ])->save();

        return $inv->fresh('lines');
    }

    /** A correlated credit note against $original, with the given AADE state. */
    private function credit(Invoice $original, ?string $state, string $invcode = 'ΠΙΣ1'): Invoice
    {
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'code' => 90, 'invcode' => $invcode, 'issued_at' => now(),
            'local_status' => 'active', 'credited_invoice_id' => $original->id,
        ]);
        $credit->forceFill(['mydata_state' => $state, 'gross_total' => 124])->save();

        return $credit;
    }

    public function test_draft_credit_does_not_legally_reverse_a_valid_original(): void
    {
        $original = $this->validOriginal();
        $this->credit($original, state: null); // issued but not filed

        $original->refresh();
        $this->assertTrue($original->isFullyCredited(), 'reduced locally');
        $this->assertFalse($original->isLegallyReversed(), 'a draft credit is NOT a legal AADE reversal');
    }

    public function test_valid_credit_legally_reverses_a_valid_original(): void
    {
        $original = $this->validOriginal();
        $this->credit($original, state: 'VALID');

        $this->assertTrue($original->refresh()->isLegallyReversed());
    }

    public function test_direct_aade_cancellation_is_a_legal_reversal_without_a_credit(): void
    {
        $original = $this->validOriginal();
        $original->forceFill(['mydata_state' => 'CANCELLED'])->save();

        $this->assertTrue($original->refresh()->isLegallyReversed());
    }

    public function test_offmode_original_fully_credited_is_legally_reversed(): void
    {
        // Never a live AADE filing (mydata_state null) → local full-credit IS the
        // reversal; there is no standing AADE turnover to contradict it. No false warn.
        $original = $this->validOriginal();
        $original->forceFill(['mydata_state' => null, 'mydata_mark' => null])->save();
        $this->credit($original, state: null); // draft credit, off-mode

        $this->assertTrue($original->refresh()->isLegallyReversed());
    }

    public function test_partial_credit_is_not_legally_reversed(): void
    {
        $original = $this->validOriginal();
        $this->credit($original, state: 'VALID');
        // Force the cache to a partial value LAST — saving the original itself does
        // not recompute (only a credit's save does), so this sticks.
        $original->forceFill(['credited_total' => 50])->save();

        $this->assertFalse($original->refresh()->isFullyCredited());
        $this->assertFalse($original->refresh()->isLegallyReversed());
    }

    public function test_a_credit_note_itself_is_never_legally_reversed(): void
    {
        $original = $this->validOriginal();
        $credit = $this->credit($original, state: 'VALID');

        $this->assertFalse($credit->isLegallyReversed());
    }

    public function test_a_cancelled_credit_does_not_count_toward_legal_reversal(): void
    {
        // A cancelled credit is not a live reversal — the observer's recompute drops
        // it from credited_total, and isLegallyReversed must not treat it as filed.
        $original = $this->validOriginal();
        $credit = $this->credit($original, state: 'VALID'); // recompute → fully credited
        $credit->forceFill(['local_status' => 'cancelled'])->save(); // recompute → 0

        $this->assertFalse($original->refresh()->isFullyCredited());
        $this->assertFalse($original->refresh()->isLegallyReversed());
    }

    public function test_reissue_records_the_link_and_flags_pending_reversal(): void
    {
        $original = $this->validOriginal();
        $this->credit($original, state: null); // draft → original not legally reversed

        $reissue = app(ReissueInvoiceAsDraft::class)($original->refresh());

        $this->assertSame($original->id, $reissue->reissued_from_invoice_id, 'the link is recorded');
        $this->assertTrue($reissue->replacementReversalPending(), 'original still standing at AADE');
    }

    public function test_replacement_pending_is_false_once_the_original_is_legally_reversed(): void
    {
        $original = $this->validOriginal();
        $this->credit($original, state: 'VALID'); // filed → legal reversal

        $reissue = app(ReissueInvoiceAsDraft::class)($original->refresh());

        $this->assertFalse($reissue->replacementReversalPending());
    }

    public function test_replacement_pending_is_false_for_a_plain_invoice(): void
    {
        // A normal invoice (no reissued_from link) never warns.
        $this->assertFalse($this->validOriginal()->replacementReversalPending());
    }

    public function test_bulk_submit_leaves_the_prov019_trace_for_a_standing_replacement(): void
    {
        // The per-row soft-warn modal can't show in a bulk run, so the bulk
        // submit_mydata action must still leave the durable PROV-019 trace — else
        // a replacement swept into a bulk selection files double-turnover silently.
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]));
        // Bulk submit_mydata is visible on a DIRECT-myDATA tenant (mydata_mode).
        $this->tenant->forceFill(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox'])->save();
        Filament::setTenant($this->tenant);

        $original = $this->validOriginal();
        $this->credit($original, state: null); // draft → original still standing
        $reissue = app(ReissueInvoiceAsDraft::class)($original->refresh());
        $this->assertTrue($reissue->replacementReversalPending());

        // Stub the submitter so the bulk loop takes no network (the trace fires
        // BEFORE the submit call, so a throw here doesn't hide it). Mockery, not an
        // anonymous class, so Pint's php_unit_method_casing can't rename the
        // interface's testConnection() into a "test method".
        $submitter = \Mockery::mock(EInvoiceSubmitter::class);
        $submitter->shouldReceive('submit')->andThrow(new RuntimeException('stub — no real filing in test'));
        $factory = \Mockery::mock(EInvoiceSubmitterFactory::class);
        $factory->shouldReceive('for')->andReturn($submitter);
        $this->app->instance(EInvoiceSubmitterFactory::class, $factory);
        Log::spy();

        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('submit_mydata', [$reissue->getKey()]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'PROV-019'))
            ->once();
    }
}
