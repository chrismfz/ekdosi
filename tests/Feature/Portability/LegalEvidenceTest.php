<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Services\Portability\CompanyDataWiper;
use App\Support\LegalEvidence;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * MYD-025 — the wipe/delete gate must see everything that was actually filed.
 *
 * The old gate counted invoices with `mydata_state = VALID` and nothing else, so
 * three whole classes of evidence were invisible to it: a document carrying a real
 * MARK whose state cache was never written, every CANCELLED invoice, and every
 * delivery note. A tenant made of those could be wiped with no warning at all.
 *
 * Deletion itself stays possible — a tenant that outgrows a shared host is
 * exported, restored elsewhere and then legitimately removed. What these pin is
 * that it can no longer happen SILENTLY.
 */
class LegalEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug = 'ev'): Company
    {
        return Company::create([
            'name' => 'Evidence OE', 'slug' => $slug.'-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
    }

    private function invoice(Company $c, array $override = []): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $c->id, 'code' => 'ΤΠΥ'],
            ['name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1'],
        );
        $customer = Customer::firstOrCreate(
            ['company_id' => $c->id, 'afm' => '997073525'],
            ['name' => 'Πελάτης'],
        );

        static $n = 0;
        $n++;

        $invoice = Invoice::create([
            'company_id' => $c->id, 'invcode' => 'ΤΠΥ'.$n, 'code' => $n,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);

        // The mydata_* cache columns are GUARDED — deliberately absent from
        // $fillable so no form can spoof them, and written only by the submitter.
        // Passing them to create() drops them SILENTLY, which is how the first cut
        // of these tests asserted against a state that was never stored.
        if ($override !== []) {
            $invoice->forceFill($override)->save();
        }

        return $invoice->fresh();
    }

    public function test_a_company_that_never_filed_has_no_evidence(): void
    {
        $c = $this->company();
        $this->invoice($c); // a draft — never filed

        $evidence = LegalEvidence::for($c);

        $this->assertFalse($evidence->exists());
        $this->assertSame(0, $evidence->totalMarks());
        $this->assertStringContainsString('Δεν έχει υποβληθεί', $evidence->describe());
    }

    public function test_a_dry_run_or_rejection_is_not_evidence(): void
    {
        // Forensic rows carry a NULL mark. Counting them would block a clean-slate
        // re-import over a dry run — exactly the workflow the wiper exists for.
        $c = $this->company();
        $inv = $this->invoice($c);

        MyDataMark::create([
            'company_id' => $c->id, 'invoice_id' => $inv->id,
            'mark' => null, 'mydata_action' => 'DRY_RUN',
        ]);
        MyDataMark::create([
            'company_id' => $c->id, 'invoice_id' => $inv->id,
            'mark' => '', 'mydata_action' => 'REJECTED',
        ]);

        $this->assertFalse(LegalEvidence::for($c)->exists());
    }

    public function test_a_real_mark_without_a_state_cache_is_evidence(): void
    {
        // The exact hole: the old gate read mydata_state, so a document whose MARK
        // exists but whose cache column was never written counted as "nothing
        // filed" and was wiped with no warning.
        $c = $this->company();
        $inv = $this->invoice($c);
        $this->assertNull($inv->mydata_state);

        MyDataMark::create([
            'company_id' => $c->id, 'invoice_id' => $inv->id,
            'mark' => '400000000000001', 'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(),
        ]);

        $evidence = LegalEvidence::for($c);
        $this->assertTrue($evidence->exists());
        $this->assertSame(1, $evidence->totalMarks());
    }

    public function test_a_cancelled_invoice_is_still_evidence(): void
    {
        // A cancellation is itself a filing, and AADE keeps both records.
        $c = $this->company();
        $this->invoice($c, ['mydata_state' => 'CANCELLED', 'local_status' => 'cancelled']);

        $evidence = LegalEvidence::for($c);
        $this->assertTrue($evidence->exists());
        $this->assertSame(1, $evidence->filedInvoices);
    }

    public function test_delivery_notes_count_even_with_no_invoices(): void
    {
        $c = $this->company();
        $type = InvoiceType::create([
            'company_id' => $c->id, 'code' => 'ΔΑ', 'name' => 'ΔΑ',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $note = DeliveryNote::create([
            'company_id' => $c->id, 'delivery_type_id' => $type->id,
            'invcode' => 'ΔΑ1', 'code' => 1, 'issued_at' => now(),
            'mydata_type' => '9.3', 'move_purpose' => 1, 'local_status' => 'active',
        ]);
        $note->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400000000000002'])->save();
        DeliveryMark::create([
            'company_id' => $c->id, 'delivery_note_id' => $note->id,
            'mark' => '400000000000002', 'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(),
        ]);

        $evidence = LegalEvidence::for($c);
        $this->assertTrue($evidence->exists());
        $this->assertSame(1, $evidence->deliveryMarks);
        $this->assertSame(1, $evidence->filedDeliveryNotes);
        $this->assertStringContainsString('δελτίο αποστολής', $evidence->describe());
    }

    public function test_another_tenants_filings_are_not_counted(): void
    {
        $mine = $this->company('mine');
        $theirs = $this->company('theirs');
        $inv = $this->invoice($theirs);
        MyDataMark::create([
            'company_id' => $theirs->id, 'invoice_id' => $inv->id,
            'mark' => '400000000000003', 'mydata_action' => 'INSERT',
        ]);

        $this->assertFalse(LegalEvidence::for($mine)->exists());
        $this->assertTrue(LegalEvidence::for($theirs)->exists());
    }

    public function test_the_count_is_not_zeroed_by_the_ambient_tenant_context(): void
    {
        // The mark models carry CompanyScope, and this runs from a super_admin
        // panel action on a company other than the selected tenant, and from a
        // CLI where the scope is a no-op. Reading through Eloquent would have
        // returned 0 and warned about nothing.
        $edited = $this->company('edited');
        $ambient = $this->company('ambient');
        $inv = $this->invoice($edited);
        MyDataMark::create([
            'company_id' => $edited->id, 'invoice_id' => $inv->id,
            'mark' => '400000000000004', 'mydata_action' => 'INSERT',
        ]);

        $counted = app(CompanyContext::class)->actAs(
            $ambient,
            fn (): bool => LegalEvidence::for($edited)->exists(),
        );

        $this->assertTrue($counted);
    }

    public function test_a_suppliers_mark_is_not_our_filing(): void
    {
        // expense_marks mostly hold the SUPPLIER's MARK, pulled down by
        // SyncExpenseStateFromAade (action STATE_SYNC). Counting those told a
        // pull-only tenant it had «filed expense classifications» and made the wipe
        // refuse over data it never submitted.
        $c = $this->company();

        DB::table('expense_marks')->insert([
            'company_id' => $c->id, 'mark' => '400000000000009',
            'mydata_action' => 'STATE_SYNC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(LegalEvidence::for($c)->exists(), 'their filing, not ours');

        // Our OWN classification submission is evidence.
        DB::table('expense_marks')->insert([
            'company_id' => $c->id, 'mark' => '400000000000010',
            'mydata_action' => 'SendExpensesClassification',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $evidence = LegalEvidence::for($c);
        $this->assertTrue($evidence->exists());
        $this->assertSame(1, $evidence->expenseMarks);
    }

    // ─────────────────────────── the wiper gate ───────────────────────────

    public function test_the_wiper_refuses_a_cancelled_only_tenant_without_force(): void
    {
        // Under the old gate this wiped silently: no VALID invoice existed.
        $c = $this->company();
        $this->invoice($c, ['mydata_state' => 'CANCELLED', 'local_status' => 'cancelled']);

        $wiper = app(CompanyDataWiper::class);
        $this->assertSame(0, $wiper->filedAtAadeCount($c), 'the old, narrow number really is 0');

        try {
            $wiper->wipe($c, keepParties: false, resetCounter: false);
            $this->fail('expected the widened gate to refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('υποβεβλημένα στοιχεία', $e->getMessage());
        }

        $this->assertSame(1, DB::table('invoices')->where('company_id', $c->id)->count());
    }

    public function test_the_wiper_still_allows_a_forced_wipe(): void
    {
        // Deletion stays possible — this is a clean-slate tool and must remain one.
        $c = $this->company();
        $this->invoice($c, ['mydata_state' => 'CANCELLED', 'local_status' => 'cancelled']);

        app(CompanyDataWiper::class)->wipe($c, keepParties: false, resetCounter: false, force: true);

        $this->assertSame(0, DB::table('invoices')->where('company_id', $c->id)->count());
    }

    public function test_the_wiper_names_what_it_refuses_over(): void
    {
        // The message is the deliverable: «χρειάζεται force» without saying what
        // is at stake is the silence this change exists to remove.
        $c = $this->company();
        $inv = $this->invoice($c, ['mydata_state' => 'VALID']);
        MyDataMark::create([
            'company_id' => $c->id, 'invoice_id' => $inv->id,
            'mark' => '400000000000005', 'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(),
        ]);

        try {
            app(CompanyDataWiper::class)->wipe($c, keepParties: false, resetCounter: false);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 ΜΑΡΚ', $e->getMessage());
            $this->assertStringContainsString('υποβληθέν τιμολόγιο', $e->getMessage());
            $this->assertStringContainsString('ΔΕΝ αναιρεί', $e->getMessage());
        }
    }
}
