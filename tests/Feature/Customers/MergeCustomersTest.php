<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\Tag;
use App\Models\User;
use App\Services\Customers\MergeCustomers;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * «Συγχώνευση πελατών» — the resolution the ΑΦΜ-unique constraint always
 * needed: everything moves to the survivor, the differing fields are kept as a
 * note, the loser is force-deleted (so it stops holding the ΑΦΜ), and the whole
 * thing refuses rather than half-merging.
 */
class MergeCustomersTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    /** @var list<string> */
    private array $deny = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Merge Co', 'slug' => 'mg-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user);

        Gate::before(fn ($user, string $ability): bool => ! in_array($ability, $this->deny, true));
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge(['company_id' => $this->tenant->id, 'name' => 'Πελάτης'], $attributes));
    }

    private function invoiceFor(Customer $customer, string $code): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ'],
            ['name' => 'ΤΠΥ', 'invcount' => 1],
        );

        return Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id,
            'customer_id' => $customer->id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'issued_at' => now(),
        ]);
    }

    public function test_everything_moves_to_the_survivor_and_the_loser_is_gone(): void
    {
        $keep = $this->customer(['name' => 'ΜΑΡΑΚΗΣ ΑΕ', 'afm' => '997882771', 'email' => 'a@x.gr', 'city' => 'ΑΘΗΝΑ']);
        $drop = $this->customer(['name' => 'ΜΑΡΑΚΗΣ ΜΟΝ. ΕΠΕ', 'email' => 'b@y.gr', 'city' => 'Ν. ΚΟΣΜΟΣ', 'whmcs_client_id' => 77]);

        $invoice = $this->invoiceFor($drop, 'ΤΠΥ1');
        $method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $payment = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'invoice_id' => $invoice->id,
            'payment_method_id' => $method->id, 'pay_date' => now(), 'amount' => 10,
        ]);
        $quote = Quote::create(['company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'code' => 'ΠΡ-1', 'status' => 'draft']);
        $contact = CustomerContact::create([
            'company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'name' => 'Νίκος', 'is_primary' => true,
        ]);
        $tag = Tag::create(['company_id' => $this->tenant->id, 'name' => 'vip']);
        $drop->tags()->attach($tag);
        $note = Note::create([
            'company_id' => $this->tenant->id, 'notable_type' => Customer::class, 'notable_id' => $drop->id,
            'body' => 'παλιά σημείωση',
        ]);

        $result = app(MergeCustomers::class)($keep, $drop);

        // The documents followed the party.
        $this->assertSame($keep->id, $invoice->fresh()->customer_id);
        $this->assertSame($keep->id, $payment->fresh()->customer_id);
        $this->assertSame($keep->id, $quote->fresh()->customer_id);
        $this->assertSame($keep->id, $contact->fresh()->customer_id);
        $this->assertSame($keep->id, $note->fresh()->notable_id);
        $this->assertSame(['vip'], $keep->fresh()->tags()->pluck('name')->all());

        // …and the loser is gone for good (a soft-deleted twin would still
        // hold the ΑΦΜ under UNIQUE(company_id, afm_key)).
        $this->assertNull(Customer::withTrashed()->find($drop->id));
        $this->assertSame(1, $result->moves['invoices']);
        $this->assertSame(1, $result->moves['payments']);
        $this->assertSame(1, $result->moves['customer_contacts']);
        $this->assertGreaterThanOrEqual(1, $result->moves['notes']);
    }

    public function test_reported_counts_match_what_moved(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται']);
        $this->invoiceFor($drop, 'ΤΠΥ10');
        $this->invoiceFor($drop, 'ΤΠΥ11');
        Quote::create(['company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'code' => 'ΠΡ-2', 'status' => 'draft']);

        $preview = app(MergeCustomers::class)->preview($keep, $drop);
        $this->assertSame(2, $preview->moves['invoices']);
        $this->assertSame(1, $preview->moves['quotes']);
        $this->assertStringContainsString('2 παραστατικά', $preview->movesLabel());

        $result = app(MergeCustomers::class)($keep, $drop);
        $this->assertSame(2, $result->moves['invoices']);
        $this->assertSame(2, Invoice::where('customer_id', $keep->id)->count());
        $this->assertNull(Customer::withTrashed()->find($drop->id), 'force-deleted: it must not keep holding the ΑΦΜ');
    }

    public function test_the_differing_fields_land_as_a_pinned_note(): void
    {
        $keep = $this->customer(['name' => 'ΜΑΡΑΚΗΣ ΑΕ', 'email' => 'accounting@dent-master.gr', 'city' => 'ΑΘΗΝΑ']);
        $drop = $this->customer(['name' => 'ΜΑΡΑΚΗΣ ΜΝ/ΠΗ ΕΠΕ', 'email' => 'emarakis@smartmove.gr', 'city' => 'ΑΘΗΝΑ']);

        app(MergeCustomers::class)($keep, $drop);

        $note = $keep->internalNotes()->first();
        $this->assertNotNull($note);
        $this->assertTrue((bool) $note->is_pinned);
        $this->assertSame($this->user->id, $note->author_user_id);
        $this->assertStringContainsString('Συγχώνευση πελάτη #'.$drop->id, $note->body);
        $this->assertStringContainsString('ΜΑΡΑΚΗΣ ΜΝ/ΠΗ ΕΠΕ', $note->body, 'the lost name is preserved');
        $this->assertStringContainsString('emarakis@smartmove.gr', $note->body, 'the lost email is preserved');
        $this->assertStringNotContainsString('Πόλη', $note->body, 'a field that agrees is not noise');
    }

    public function test_refuses_a_second_company_itself_a_trashed_survivor_and_two_origin_leads(): void
    {
        $keep = $this->customer(['name' => 'Α']);

        // Itself.
        try {
            app(MergeCustomers::class)($keep, $keep);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('εαυτό', $e->getMessage());
        }

        // Another company.
        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        $foreign = Customer::create(['company_id' => $other->id, 'name' => 'Ξένος']);
        try {
            app(MergeCustomers::class)($keep, $foreign);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('διαφορετικές εταιρείες', $e->getMessage());
        }
        $this->assertNotNull(Customer::withoutGlobalScopes()->find($foreign->id), 'the other tenant\'s row is untouched');

        // A trashed survivor.
        $trashed = $this->customer(['name' => 'Σβησμένος']);
        $trashed->delete();
        $live = $this->customer(['name' => 'Ζωντανός']);
        try {
            app(MergeCustomers::class)($trashed, $live);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('διαγραμμένος', $e->getMessage());
        }

        // Both sides came from a lead (leads.converted_customer_id is unique).
        $a = $this->customer(['name' => 'Από lead A']);
        $b = $this->customer(['name' => 'Από lead B']);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'L1', 'converted_customer_id' => $a->id, 'converted_at' => now()]);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'L2', 'converted_customer_id' => $b->id, 'converted_at' => now()]);
        try {
            app(MergeCustomers::class)($a, $b);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lead', $e->getMessage());
        }
        $this->assertNotNull(Customer::find($b->id), 'nothing was merged');
    }

    public function test_one_origin_lead_follows_the_party(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται']);
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Το lead', 'converted_customer_id' => $drop->id, 'converted_at' => now()]);

        app(MergeCustomers::class)($keep, $drop);

        $this->assertSame($keep->id, $lead->fresh()->converted_customer_id);
        $this->assertSame($lead->id, $keep->fresh()->originLead?->id);
    }

    public function test_only_one_primary_contact_and_no_duplicate_tag_survive(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται']);
        CustomerContact::create(['company_id' => $this->tenant->id, 'customer_id' => $keep->id, 'name' => 'Κύρια', 'is_primary' => true]);
        CustomerContact::create(['company_id' => $this->tenant->id, 'customer_id' => $drop->id, 'name' => 'Άλλη κύρια', 'is_primary' => true]);
        $tag = Tag::create(['company_id' => $this->tenant->id, 'name' => 'κοινό']);
        $keep->tags()->attach($tag);
        $drop->tags()->attach($tag);

        app(MergeCustomers::class)($keep, $drop);

        $this->assertSame(2, $keep->fresh()->contacts()->count(), 'both contacts survive');
        $this->assertSame(1, DB::table('customer_contacts')->where('customer_id', $keep->id)->where('is_primary', true)->count());
        $this->assertSame(1, DB::table('taggables')->where('taggable_type', Customer::class)->where('taggable_id', $keep->id)->count());
    }

    public function test_suggest_keeper_picks_the_fuller_row_then_the_older_id(): void
    {
        $thin = $this->customer(['name' => 'Άδειος']);
        $fat = $this->customer(['name' => 'Γεμάτος']);
        $this->invoiceFor($fat, 'ΤΠΥ20');

        $merger = app(MergeCustomers::class);
        $this->assertTrue($merger->suggestKeeper($thin, $fat)->is($fat));
        $this->assertTrue($merger->suggestKeeper($fat, $thin)->is($fat), 'order does not matter');

        $a = $this->customer(['name' => 'Ίσος Α']);
        $b = $this->customer(['name' => 'Ίσος Β']);
        $this->assertTrue($merger->suggestKeeper($b, $a)->is($a), 'a tie goes to the older id');
    }

    public function test_the_survivor_adopts_the_identity_keys_that_would_recreate_the_duplicate(): void
    {
        // legacy_id (the Firebird ETL's re-run key) and whmcs_client_id are how
        // the OTHER systems find this party. Dropping them with the loser would
        // let the ETL / the WHMCS matcher re-insert the very duplicate we merged.
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται', 'whmcs_client_id' => 793]);
        DB::table('customers')->where('id', $drop->id)->update(['legacy_id' => 41]);

        app(MergeCustomers::class)($keep, $drop->fresh());

        $fresh = $keep->fresh();
        $this->assertSame(41, (int) $fresh->legacy_id);
        $this->assertSame(793, (int) $fresh->whmcs_client_id);
    }

    public function test_the_survivors_own_identity_keys_win(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε', 'whmcs_client_id' => 1]);
        DB::table('customers')->where('id', $keep->id)->update(['legacy_id' => 7]);
        $drop = $this->customer(['name' => 'Χάνεται', 'whmcs_client_id' => 2]);
        DB::table('customers')->where('id', $drop->id)->update(['legacy_id' => 8]);

        app(MergeCustomers::class)($keep->fresh(), $drop->fresh());

        $fresh = $keep->fresh();
        $this->assertSame(7, (int) $fresh->legacy_id, 'never overwritten');
        $this->assertSame(1, (int) $fresh->whmcs_client_id);
        $this->assertStringContainsString('legacy_id', $fresh->internalNotes()->first()->body, 'the lost one is on record');
    }

    public function test_a_referral_never_points_the_survivor_at_itself(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται', 'referred_by_customer_id' => $keep->id]);
        $referred = $this->customer(['name' => 'Συστημένος', 'referred_by_customer_id' => $drop->id]);

        app(MergeCustomers::class)($keep, $drop);

        $this->assertNull($keep->fresh()->referred_by_customer_id, 'the survivor is not referred by itself');
        $this->assertSame($keep->id, $referred->fresh()->referred_by_customer_id, 'the third party follows');
    }

    public function test_preview_counts_the_origin_lead_that_the_merge_relinks(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται']);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Το lead', 'converted_customer_id' => $drop->id, 'converted_at' => now()]);

        $this->assertSame(1, app(MergeCustomers::class)->preview($keep, $drop)->moves['leads'] ?? 0);
    }

    public function test_a_trashed_row_is_never_the_suggested_survivor(): void
    {
        $trashed = $this->customer(['name' => 'Σβησμένος']);
        $this->invoiceFor($trashed, 'ΤΠΥ60');
        $this->invoiceFor($trashed, 'ΤΠΥ61');
        $trashed->delete();
        $live = $this->customer(['name' => 'Ζωντανός']);

        // …even though it carries more documents (a trashed survivor is refused).
        $this->assertTrue(app(MergeCustomers::class)->suggestKeeper($trashed, $live)->is($live));
    }

    public function test_it_works_on_the_pre_migration_schema_where_the_operator_actually_runs_it(): void
    {
        // The merge is what unblocks the UNIQUE(company_id, afm_key) migration,
        // so it must run BEFORE that column exists — the model's saving hook
        // (which always writes afm_key) must never be in the write path.
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται', 'whmcs_client_id' => 793]);
        $this->invoiceFor($drop, 'ΤΠΥ70');

        Schema::table('customers', function (Blueprint $t): void {
            $t->dropUnique('customers_company_afm_key_unique');
            $t->dropColumn('afm_key');
        });

        $result = app(MergeCustomers::class)($keep->fresh(), $drop->fresh());

        $this->assertSame(1, $result->moves['invoices']);
        $this->assertNull(Customer::withTrashed()->find($drop->id));
        $this->assertSame(793, (int) DB::table('customers')->where('id', $keep->id)->value('whmcs_client_id'));
    }

    public function test_the_panel_refreshes_the_adopted_keys_so_a_later_save_keeps_them(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται', 'whmcs_client_id' => 793]);

        Livewire::test(EditCustomer::class, ['record' => $keep->id])
            ->callAction('merge_customer', ['drop_id' => $drop->id])
            ->assertHasNoActionErrors()
            // A plain Save on the still-open form must not write the adoption back to null.
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(793, (int) $keep->fresh()->whmcs_client_id);
    }

    public function test_every_column_is_compared_so_a_behavioural_flag_never_dies_silently(): void
    {
        // The WHMCS «άμεση τιμολόγηση» flag used to vanish with the loser: the
        // note is now built from the REAL schema, not a hand-kept list.
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer([
            'name' => 'Χάνεται', 'needs_immediate_invoice' => true,
            'discount' => 12.5, 'kad_primary' => '62.01',
        ]);

        app(MergeCustomers::class)($keep, $drop);

        $body = $keep->fresh()->internalNotes()->first()->body;
        $this->assertStringContainsString('Άμεση τιμολόγηση', $body);
        $this->assertStringContainsString('Έκπτωση %', $body);
        $this->assertStringContainsString('62.01', $body);
    }

    public function test_an_adopted_key_is_recorded_as_adopted_not_as_lost(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται', 'whmcs_client_id' => 793]);

        app(MergeCustomers::class)($keep, $drop);

        $body = $keep->fresh()->internalNotes()->first()->body;
        $this->assertStringContainsString('ΥΙΟΘΕΤΗΘΗΚΕ', $body);
        $this->assertStringContainsString('793', $body);
    }

    public function test_the_command_previews_refuses_and_merges(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε', 'afm' => '123456789']);
        $drop = $this->customer(['name' => 'Χάνεται']);
        $this->invoiceFor($drop, 'ΤΠΥ30');

        // Dry-run changes nothing.
        $this->artisan("customers:merge {$keep->id} {$drop->id} --keep={$keep->id} --dry-run")
            ->expectsOutputToContain('Dry-run')
            ->assertExitCode(0);
        $this->assertNotNull(Customer::find($drop->id));

        // An unknown id is an input error.
        $this->artisan('customers:merge 999999 '.$drop->id)->assertExitCode(2);
        // --keep must be one of the two.
        $this->artisan("customers:merge {$keep->id} {$drop->id} --keep=999999")->assertExitCode(2);

        $this->artisan("customers:merge {$keep->id} {$drop->id} --keep={$keep->id} --force")
            ->expectsOutputToContain('Συγχωνεύτηκαν')
            ->assertExitCode(0);

        $this->assertNull(Customer::withTrashed()->find($drop->id));
        $this->assertSame(1, Invoice::where('customer_id', $keep->id)->count());
    }

    public function test_the_duplicates_report_shows_counts_and_the_suggested_keeper(): void
    {
        // The report exists for the state BEFORE UNIQUE(company_id, afm_key)
        // lands (that is when the migration runs it) — with the index in place
        // a duplicate cannot be created at all, which is the whole point.
        Schema::table('customers', fn (Blueprint $t) => $t->dropUnique('customers_company_afm_key_unique'));

        $thin = $this->customer(['name' => 'Λίγος', 'afm' => '997882771']);
        $fat = $this->customer(['name' => 'Πολύς', 'afm' => 'EL 997882771']);
        $this->invoiceFor($fat, 'ΤΠΥ40');

        $this->artisan('customers:afm-duplicates')
            ->expectsOutputToContain('1 παραστατικά')
            ->expectsOutputToContain('customers:merge '.$fat->id.' '.$thin->id.' --dry-run')
            ->assertExitCode(1);
    }

    public function test_the_panel_action_merges_and_is_gated_on_delete(): void
    {
        $keep = $this->customer(['name' => 'Κρατάμε']);
        $drop = $this->customer(['name' => 'Χάνεται', 'email' => 'lost@x.gr']);
        $this->invoiceFor($drop, 'ΤΠΥ50');

        Livewire::test(EditCustomer::class, ['record' => $keep->id])
            ->callAction('merge_customer', ['drop_id' => $drop->id])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertNull(Customer::withTrashed()->find($drop->id));
        $this->assertSame(1, Invoice::where('customer_id', $keep->id)->count());
        $this->assertStringContainsString('lost@x.gr', $keep->internalNotes()->first()->body);

        // Without Delete:Customer the action is not offered.
        $this->deny = ['delete'];
        Livewire::test(EditCustomer::class, ['record' => $keep->id])
            ->assertActionHidden('merge_customer');
    }
}
