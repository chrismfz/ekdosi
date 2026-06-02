<?php

namespace Tests\Feature\Customers;

use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\RelationManagers\InternalNotesRelationManager;
use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Note;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerAttachmentsNotesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'an-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user = User::create(['name' => 'Όπερ', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function customer(): Customer
    {
        return Customer::create(['company_id' => $this->tenant->id, 'name' => 'K']);
    }

    public function test_uploading_attachment_captures_metadata_and_stamps_tenant(): void
    {
        Storage::fake('local');
        $c = $this->customer();

        // createWithContent → real bytes (fake()->create() writes a 0-byte file
        // whose size is only metadata, which Storage::size() reads back as 0).
        $file = UploadedFile::fake()->createWithContent('σύμβαση.pdf', str_repeat('x', 5000));

        Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $c, 'pageClass' => EditCustomer::class,
        ])
            ->callTableAction('create', data: [
                'path' => [$file],
                'title' => 'Σύμβαση 2026',
            ])
            ->assertHasNoTableActionErrors();

        $att = Attachment::where('attachable_type', Customer::class)->where('attachable_id', $c->id)->first();
        $this->assertNotNull($att);
        $this->assertSame($this->tenant->id, $att->company_id);
        $this->assertSame($this->user->id, $att->uploaded_by_user_id);
        $this->assertSame('Σύμβαση 2026', $att->title);
        $this->assertSame('σύμβαση.pdf', $att->original_name);
        $this->assertGreaterThan(0, $att->size);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_force_delete_removes_the_physical_file(): void
    {
        Storage::fake('local');
        $c = $this->customer();
        Storage::disk('local')->put('attachments/x.pdf', 'data');

        $att = Attachment::create([
            'company_id' => $this->tenant->id, 'attachable_type' => Customer::class, 'attachable_id' => $c->id,
            'disk' => 'local', 'path' => 'attachments/x.pdf', 'original_name' => 'x.pdf', 'size' => 4,
        ]);

        $att->delete();                              // soft delete keeps the file
        Storage::disk('local')->assertExists('attachments/x.pdf');

        $att->forceDelete();                         // hard delete drops it
        Storage::disk('local')->assertMissing('attachments/x.pdf');
    }

    public function test_internal_note_create_stamps_author_and_tenant(): void
    {
        $c = $this->customer();

        Livewire::test(InternalNotesRelationManager::class, [
            'ownerRecord' => $c, 'pageClass' => EditCustomer::class,
        ])
            ->callTableAction('create', data: [
                'body' => 'Πληρώνει πάντα αργά — να παρακολουθείται.',
                'is_pinned' => true,
            ])
            ->assertHasNoTableActionErrors();

        $note = Note::where('notable_type', Customer::class)->where('notable_id', $c->id)->first();
        $this->assertNotNull($note);
        $this->assertSame($this->tenant->id, $note->company_id);
        $this->assertSame($this->user->id, $note->author_user_id);
        $this->assertTrue($note->is_pinned);
    }

    public function test_internal_notes_order_pinned_first(): void
    {
        $c = $this->customer();
        Note::create(['company_id' => $this->tenant->id, 'notable_type' => Customer::class, 'notable_id' => $c->id, 'body' => 'παλιά']);
        Note::create(['company_id' => $this->tenant->id, 'notable_type' => Customer::class, 'notable_id' => $c->id, 'body' => 'καρφιτσωμένη', 'is_pinned' => true]);

        $this->assertSame('καρφιτσωμένη', $c->internalNotes()->first()->body);
    }

    public function test_kartela_renders_internal_notes_section(): void
    {
        $c = $this->customer();
        Note::create([
            'company_id' => $this->tenant->id, 'notable_type' => Customer::class, 'notable_id' => $c->id,
            'body' => 'Εσωτερική παρατήρηση', 'author_user_id' => $this->user->id,
        ]);

        Livewire::test(CustomerLedger::class, ['record' => $c->id])
            ->assertStatus(200)
            ->assertSee('Σημειώσεις (εσωτερικές)')
            ->assertSee('Εσωτερική παρατήρηση');
    }
}
