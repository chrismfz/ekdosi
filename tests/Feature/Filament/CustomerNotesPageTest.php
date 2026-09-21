<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Customers\Pages\CustomerNotes;
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
 * The «Σημειώσεις πελάτη» page: it lists only this customer's notes, its
 * create/edit writes are tenant/owner-stamped and sync tags, imported (backup)
 * notes are read-only, and the markdown body renders to SAFE HTML.
 */
class CustomerNotesPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'cn-'.uniqid(),
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
    public function it_lists_only_this_customers_notes(): void
    {
        $a = $this->customer('Alpha');
        $b = $this->customer('Beta');
        $mine1 = $this->note($a, ['title' => 'Δίκτυο', 'body' => 'IP 10.0.0.1']);
        $mine2 = $this->note($a, ['body' => 'AnyDesk 123 456 789']);
        $other = $this->note($b, ['body' => 'άλλου πελάτη']);

        Livewire::test(CustomerNotes::class, ['record' => $a->id])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine1, $mine2])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[Test]
    public function it_creates_a_note_stamped_to_owner_tenant_author_and_syncs_tags(): void
    {
        $c = $this->customer();
        $tag = Tag::create(['company_id' => $this->tenant->id, 'name' => 'RouterOS']);

        Livewire::test(CustomerNotes::class, ['record' => $c->id])
            ->callTableAction('create', data: [
                'title' => 'Δίκτυο γραφείου',
                'kind' => Note::KIND_TECHNICAL,
                'is_pinned' => true,
                'tags' => [$tag->id],
                'body' => "Router\n```\n/ip address print\n```",
            ])
            ->assertHasNoTableActionErrors();

        $note = Note::where('notable_type', Customer::class)->where('notable_id', $c->id)->first();
        $this->assertNotNull($note);
        $this->assertSame($this->tenant->id, $note->company_id);
        $this->assertSame($this->user->id, $note->author_user_id);
        $this->assertSame('Δίκτυο γραφείου', $note->title);
        $this->assertSame(Note::KIND_TECHNICAL, $note->kind);
        $this->assertTrue($note->is_pinned);
        $this->assertTrue($note->tags->contains($tag->id));
    }

    #[Test]
    public function it_edits_a_note_and_resyncs_tags(): void
    {
        $c = $this->customer();
        $t1 = Tag::create(['company_id' => $this->tenant->id, 'name' => 'VPN']);
        $t2 = Tag::create(['company_id' => $this->tenant->id, 'name' => 'VIP']);
        $note = $this->note($c, ['title' => 'Παλιό', 'body' => 'παλιό']);
        $note->tags()->sync([$t1->id]);

        Livewire::test(CustomerNotes::class, ['record' => $c->id])
            ->callTableAction('edit', $note, data: [
                'title' => 'Νέο',
                'kind' => Note::KIND_GENERAL,
                'is_pinned' => false,
                'tags' => [$t2->id],
                'body' => 'νέο κείμενο',
            ])
            ->assertHasNoTableActionErrors();

        $note->refresh();
        $this->assertSame('Νέο', $note->title);
        $this->assertSame('νέο κείμενο', $note->body);
        $this->assertFalse($note->tags->contains($t1->id));
        $this->assertTrue($note->tags->contains($t2->id));
        // Author is stamped once (on create) — an edit never rewrites it.
        $this->assertNull($note->fresh()->author_user_id);
    }

    #[Test]
    public function a_foreign_tenants_tag_id_is_never_synced(): void
    {
        $c = $this->customer();
        // A tag owned by ANOTHER tenant — the picker would never offer it, but a
        // tampered request could POST its id. It must be dropped before sync.
        $other = Company::create([
            'name' => 'B', 'slug' => 'cn-b-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $foreignTag = Tag::create(['company_id' => $other->id, 'name' => 'ξένη']);

        Livewire::test(CustomerNotes::class, ['record' => $c->id])
            ->callTableAction('create', data: [
                'kind' => Note::KIND_GENERAL,
                'tags' => [$foreignTag->id],
                'body' => 'δοκιμή',
            ]);

        // Two layers protect the boundary: the Select's options-validation rejects
        // an off-list id, and persistNote() re-filters to tenant-owned tags. Either
        // way the foreign tag is NEVER written to the pivot.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('taggables')->where('tag_id', $foreignTag->id)->count());
    }

    #[Test]
    public function imported_backup_notes_are_read_only(): void
    {
        $c = $this->customer();
        $imported = $this->note($c, ['body' => 'από backup', 'source' => Note::SOURCE_BACKUP]);

        Livewire::test(CustomerNotes::class, ['record' => $c->id])
            ->assertSuccessful()
            ->assertTableActionHidden('edit', $imported)
            ->assertTableActionHidden('delete', $imported);
    }

    #[Test]
    public function markdown_body_renders_to_safe_html(): void
    {
        $c = $this->customer();
        $note = $this->note($c, ['body' => "Γεια <script>alert(1)</script>\n\n```\n/ip firewall\n```"]);

        $html = (string) $note->renderedBody();

        // Raw HTML is escaped (no live <script>); the fenced block is a <pre><code>.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('/ip firewall', $html);
    }

    #[Test]
    public function kartela_links_to_the_notes_page(): void
    {
        $c = $this->customer();
        $this->note($c, ['body' => 'Εσωτερική παρατήρηση']);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->assertStatus(200)
            ->assertSee('Άνοιγμα σημειώσεων');
    }

    #[Test]
    public function display_title_falls_back_to_first_body_line(): void
    {
        $c = $this->customer();
        $titled = $this->note($c, ['title' => 'Ρητός τίτλος', 'body' => 'σώμα']);
        $untitled = $this->note($c, ['body' => "Πρώτη γραμμή\nδεύτερη"]);

        $this->assertSame('Ρητός τίτλος', $titled->displayTitle());
        $this->assertSame('Πρώτη γραμμή', $untitled->displayTitle());
    }
}
