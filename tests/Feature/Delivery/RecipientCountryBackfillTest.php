<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The MYD-011 migration's data backfill (`recipient_country`), exercised directly:
 * the schema half has already run by the time a test boots, so the backfill query
 * is re-invoked on rows the test controls.
 *
 * What it must and must not touch is the whole point — `recipient_country` is a
 * SNAPSHOT of what was filed, and `customers.country` is live.
 */
class RecipientCountryBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Backfill ΔΑ',
            'slug' => 'dabf-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'DA',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
            'is_delivery_note' => true,
        ]);
    }

    private function runBackfill(): void
    {
        $migration = require base_path(
            'tests/Fixtures/migrations/2026_09_01_000001_add_recipient_country_to_delivery_notes.php'
        );

        $method = new ReflectionMethod($migration, 'backfillFromCustomers');
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    private function makeNote(array $overrides): DeliveryNote
    {
        static $n = 0;
        $n++;

        return DeliveryNote::create(array_merge([
            'company_id' => $this->tenant->id,
            'invcode' => 'BF'.$n,
            'code' => $n,
            'delivery_type_id' => $this->type->id,
            'issued_at' => now(),
            'local_status' => 'draft',
            'recipient_country' => null,
        ], $overrides));
    }

    private function customerFrom(?string $country): Customer
    {
        static $n = 0;
        $n++;

        return Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Πελάτης '.$n,
            'afm' => '80056184'.($n % 10),
            'country' => $country,
        ]);
    }

    public function test_backfills_a_draft_from_its_customers_country(): void
    {
        $note = $this->makeNote(['customer_id' => $this->customerFrom('Germany')->id]);

        $this->runBackfill();

        $this->assertSame('DE', $note->fresh()->recipient_country);
    }

    public function test_resolves_legacy_free_text_country_names(): void
    {
        // The submitter REFUSES an unresolvable country, so whatever the backfill
        // can't map becomes an operator task — the legacy Greek spellings are the
        // bulk of the real data and must land here, not there.
        $note = $this->makeNote(['customer_id' => $this->customerFrom('ΙΤΑΛΙΑ')->id]);

        $this->runBackfill();

        $this->assertSame('IT', $note->fresh()->recipient_country);
    }

    public function test_never_touches_an_already_filed_note(): void
    {
        // THE POINT: recipient_country is a snapshot of what was SUBMITTED, and
        // customers.country is live. A customer who has moved since would otherwise
        // have us write a country the AADE record never carried — and the PDF, the
        // infolist and the CMR would then all display that wrong value as if it were
        // the filed one. A filed note is never re-submitted, so null is honest.
        $note = $this->makeNote([
            'customer_id' => $this->customerFrom('Germany')->id,
            'local_status' => 'active',
        ]);
        // mydata_mark/mydata_state are guarded — the submitter writes them the same
        // way, so forceFill is the faithful reproduction of a filed note.
        $note->forceFill(['mydata_mark' => '400000000000777', 'mydata_state' => 'VALID'])->save();

        $this->runBackfill();

        $this->assertNull($note->fresh()->recipient_country);
    }

    public function test_a_filed_note_does_not_read_the_live_customer_country_either(): void
    {
        // Guarding only the migration's WRITE left the READ wide open: the PDF, the
        // infolist and the CMR all go through recipientCountryIso(), so a customer
        // whose country was filled in AFTER the note was filed would have been
        // displayed as though it were the submitted value.
        $customer = $this->customerFrom(null);
        $note = $this->makeNote(['customer_id' => $customer->id, 'local_status' => 'active']);
        $note->forceFill(['mydata_sent' => true, 'mydata_mark' => '400000000000777'])->save();

        // The operator fills the country in later — nothing to do with what was filed.
        $customer->forceFill(['country' => 'DE'])->save();

        $this->assertNull($note->fresh()->recipientCountryIso());

        // …while the same note before transmission still resolves it.
        $draft = $this->makeNote(['customer_id' => $customer->id]);
        $this->assertSame('DE', $draft->fresh()->recipientCountryIso());
    }

    public function test_the_filed_predicate_also_catches_a_sent_note_with_no_mark(): void
    {
        // A rejected-then-repaired note can carry the flag without a MARK.
        $customer = $this->customerFrom('Germany');
        $note = $this->makeNote(['customer_id' => $customer->id]);
        $note->forceFill(['mydata_sent' => true])->save();

        $this->assertTrue($note->fresh()->hasBeenFiled());

        $this->runBackfill();

        $this->assertNull($note->fresh()->recipient_country);
    }

    public function test_leaves_an_unresolvable_country_null_rather_than_guessing(): void
    {
        $note = $this->makeNote(['customer_id' => $this->customerFrom('Neverland')->id]);

        $this->runBackfill();

        $this->assertNull($note->fresh()->recipient_country);
    }

    public function test_does_not_overwrite_a_country_already_on_the_note(): void
    {
        $note = $this->makeNote([
            'customer_id' => $this->customerFrom('Germany')->id,
            'recipient_country' => 'FR',
        ]);

        $this->runBackfill();

        $this->assertSame('FR', $note->fresh()->recipient_country);
    }

    public function test_never_freezes_a_customers_country_onto_a_different_party(): void
    {
        // `customer_id` is a Hidden the form never clears, so an operator can pick a
        // customer and then overtype the recipient with someone else. Inheriting the
        // stale link's country there files a foreign party as Greek — and freezing it
        // into the snapshot makes it permanently invisible, because the column then
        // wins over every later repair.
        $note = $this->makeNote([
            'customer_id' => $this->customerFrom('ΕΛΛΑΔΑ')->id,
            'recipient_name' => 'Müller GmbH',
            'recipient_afm' => 'DE811234567',
        ]);

        $this->assertFalse($note->recipientIsTheLinkedCustomer());

        $this->runBackfill();

        $this->assertNull($note->fresh()->recipient_country);
        $this->assertNull($note->fresh()->recipientCountryIso());
    }

    public function test_still_backfills_when_the_recipient_fields_echo_the_customer(): void
    {
        // The narrowing must not strand the normal case: the form copies the picked
        // party's name/ΑΦΜ into those very fields, so they routinely MATCH.
        $customer = $this->customerFrom('Germany');
        $note = $this->makeNote([
            'customer_id' => $customer->id,
            'recipient_name' => $customer->name,
            'recipient_afm' => $customer->afm,
        ]);

        $this->assertTrue($note->recipientIsTheLinkedCustomer());

        $this->runBackfill();

        $this->assertSame('DE', $note->fresh()->recipient_country);
    }

    public function test_the_sentinel_afm_still_reads_as_the_linked_customer(): void
    {
        // «000000000» means "this party has no ΑΦΜ", not "a different party".
        $note = $this->makeNote([
            'customer_id' => $this->customerFrom('Germany')->id,
            'recipient_afm' => '000000000',
        ]);

        $this->runBackfill();

        $this->assertSame('DE', $note->fresh()->recipient_country);
    }

    public function test_leaves_supplier_and_manual_recipients_alone(): void
    {
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Lieferant GmbH',
            'recipient_afm' => 'DE811234567',
        ]);

        $this->runBackfill();

        $this->assertNull($note->fresh()->recipient_country);
    }

    public function test_is_re_runnable_and_groups_updates_by_iso_code(): void
    {
        $de = $this->makeNote(['customer_id' => $this->customerFrom('Germany')->id]);
        $it = $this->makeNote(['customer_id' => $this->customerFrom('Ιταλία')->id]);
        $gr = $this->makeNote(['customer_id' => $this->customerFrom('ΕΛΛΑΔΑ')->id]);

        $this->runBackfill();
        $this->runBackfill();   // idempotent — the whereNull filter is the guard

        $this->assertSame('DE', $de->fresh()->recipient_country);
        $this->assertSame('IT', $it->fresh()->recipient_country);
        $this->assertSame('GR', $gr->fresh()->recipient_country);

        // Nothing eligible is left behind. NOTE: this does not exercise the
        // chunkById-vs-chunk offset skew — that needs more than one page (>500 rows),
        // which is not worth the runtime here; the cursor choice is argued in the
        // migration's own comment.
        $this->assertSame(0, DB::table('delivery_notes')
            ->join('customers', 'customers.id', '=', 'delivery_notes.customer_id')
            ->whereNull('delivery_notes.recipient_country')
            ->whereNull('delivery_notes.mydata_mark')
            ->whereIn('customers.country', ['Germany', 'Ιταλία', 'ΕΛΛΑΔΑ'])
            ->count());
    }
}
