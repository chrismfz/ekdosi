<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Note;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Καρτέλα «Σημειώσεις (εσωτερικές)» panel now lets the operator open a single
 * note whole and edit it inline — no trip to the notes page or the edit-customer
 * tab. «Άνοιγμα» is a read-only modal (full markdown body); «Επεξεργασία» reuses
 * the same note form + write path the «Σημειώσεις» page uses (ManagesCustomerNotes),
 * and is hidden for imported («backup») notes.
 */
class CustomerLedgerNoteActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'cl-notes-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user = User::create(['name' => 'Όπερ', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function customer(string $name = 'K'): Customer
    {
        return Customer::create(['company_id' => $this->tenant->id, 'name' => $name]);
    }

    private function note(Customer $c, array $attrs = []): Note
    {
        return Note::create(array_merge([
            'company_id' => $this->tenant->id,
            'notable_type' => Customer::class,
            'notable_id' => $c->id,
            'body' => 'σημείωση',
        ], $attrs));
    }

    #[Test]
    public function the_kartela_renders_per_note_open_and_edit_triggers(): void
    {
        $c = $this->customer();
        $this->note($c, ['title' => 'Δίκτυο', 'body' => 'IP 10.0.0.1']);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->assertStatus(200)
            // Both mounted-action triggers are wired on the note.
            ->assertSee('viewNote')
            ->assertSee('editNote');
    }

    #[Test]
    public function an_imported_note_shows_open_but_not_edit(): void
    {
        $c = $this->customer();
        $this->note($c, ['body' => 'από backup', 'source' => Note::SOURCE_BACKUP]);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->assertStatus(200)
            ->assertSee('viewNote')
            ->assertDontSee('editNote');
    }

    #[Test]
    public function open_action_mounts_the_note_without_error(): void
    {
        $c = $this->customer();
        // A titled note whose body is longer than the panel excerpt (100 chars):
        // the tail marker is NOT in the excerpt, so the panel must not show it —
        // it lives only in the full note the modal opens.
        $note = $this->note($c, [
            'title' => 'Μεγάλη σημείωση',
            'body' => str_repeat('α', 130).' TAILMARKER_XYZ',
        ]);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->assertStatus(200)
            ->assertDontSee('TAILMARKER_XYZ')
            ->mountAction('viewNote', ['note' => $note->id])
            ->assertActionMounted('viewNote')
            ->assertHasNoActionErrors();
    }

    #[Test]
    public function the_note_view_renders_the_full_body_not_an_excerpt(): void
    {
        // The modal renders this blade with the resolved note; it shows the WHOLE
        // markdown body (the tail marker beyond the 100-char panel excerpt).
        $c = $this->customer();
        $note = $this->note($c, [
            'title' => 'Μεγάλη σημείωση',
            'body' => str_repeat('α', 130).' TAILMARKER_XYZ',
        ]);

        $html = view('filament.customers.note-view', ['note' => $note])->render();

        $this->assertStringContainsString('TAILMARKER_XYZ', $html);
    }

    #[Test]
    public function edit_action_updates_the_note_and_syncs_tags(): void
    {
        $c = $this->customer();
        $t1 = Tag::create(['company_id' => $this->tenant->id, 'name' => 'VPN']);
        $t2 = Tag::create(['company_id' => $this->tenant->id, 'name' => 'VIP']);
        $note = $this->note($c, ['title' => 'Παλιό', 'body' => 'παλιό']);
        $note->tags()->sync([$t1->id]);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->mountAction('editNote', ['note' => $note->id])
            ->setActionData([
                'title' => 'Νέο',
                'kind' => Note::KIND_GENERAL,
                'is_pinned' => false,
                'tags' => [$t2->id],
                'body' => 'νέο κείμενο',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $note->refresh();
        $this->assertSame('Νέο', $note->title);
        $this->assertSame('νέο κείμενο', $note->body);
        $this->assertFalse($note->tags->contains($t1->id));
        $this->assertTrue($note->tags->contains($t2->id));
    }

    #[Test]
    public function edit_action_cannot_reach_another_customers_note(): void
    {
        // The note id is a client-supplied action argument; requireCustomerNote()
        // scopes it to THIS customer, so a tampered mount at another customer's
        // note must NEVER write. Assert the OUTCOME (the foreign note is untouched)
        // — robust whether the guard 404s on mount or on submit.
        $a = $this->customer('Alpha');
        $b = $this->customer('Beta');
        $foreign = $this->note($b, ['title' => 'Ξένη', 'body' => 'άλλου πελάτη']);

        try {
            Livewire::test(CustomerLedger::class, ['record' => $a->id])
                ->mountAction('editNote', ['note' => $foreign->id])
                ->setActionData(['title' => 'ΧΑΚ', 'kind' => Note::KIND_GENERAL, 'body' => 'tampered'])
                ->callMountedAction();
        } catch (\Throwable) {
            // A fail-closed 404/403 is the expected guard — swallow and assert below.
        }

        $foreign->refresh();
        $this->assertSame('Ξένη', $foreign->title);
        $this->assertSame('άλλου πελάτη', $foreign->body);
    }
}
