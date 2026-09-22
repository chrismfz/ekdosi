<?php

namespace App\Filament\Resources\Customers\Concerns;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Customers\Pages\CustomerNotes;
use App\Filament\Support\Tags\TagControls;
use App\Models\Note;
use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Shared internal-note form + persistence for the customer surfaces that let an
 * operator open/create/edit a customer's polymorphic {@see Note} rows: the roomy
 * «Σημειώσεις» page ({@see CustomerNotes})
 * and the Καρτέλα's inline «Σημειώσεις (εσωτερικές)» panel
 * ({@see CustomerLedger}). One form, one
 * write path — so a change to the note shape lands in both places at once.
 *
 * The consumer MUST expose a `$record` that is the owning Customer. Owner /
 * company / notable are always taken from THAT record (never from form data), so
 * a write can't be pointed at another customer or tenant; a note id passed from
 * the URL/an action argument is re-scoped through resolveCustomerNote() before use.
 */
trait ManagesCustomerNotes
{
    /**
     * Per-request memo for resolveCustomerNote(): an action mount resolves the
     * same note in several closures (heading + schema, or fillForm + action), and
     * this collapses those into one query. A private prop is NOT Livewire-hydrated,
     * so it resets fresh each round-trip — never stale across requests.
     *
     * @var array<int, ?Note>
     */
    private array $resolvedNoteCache = [];

    /**
     * Whether the operator may add/change notes (drives the create/edit gates).
     * Public so a page's Blade view can read it (Blade runs outside class scope).
     */
    public function canManageNotes(): bool
    {
        return auth()->user()?->can('update', $this->record) ?? false;
    }

    /**
     * Load ONE note that belongs to this page's customer (tenant + notable
     * scoped), or null. The id is client-supplied (URL / action argument), so it
     * is never trusted — the scoping IS the guard against reaching another
     * customer's or tenant's note.
     */
    protected function resolveCustomerNote(int|string|null $id): ?Note
    {
        if (blank($id)) {
            return null;
        }

        $key = (int) $id;
        if (array_key_exists($key, $this->resolvedNoteCache)) {
            return $this->resolvedNoteCache[$key];
        }

        return $this->resolvedNoteCache[$key] = Note::query()
            ->where('company_id', $this->record->company_id)
            ->where('notable_type', $this->record->getMorphClass())
            ->where('notable_id', $this->record->getKey())
            ->whereKey($key)
            ->with(['tags', 'author'])
            ->first();
    }

    /**
     * Same as resolveCustomerNote() but 404s when the note doesn't belong to this
     * page's customer — the fail-closed guard for the inline open/edit actions,
     * whose note id is a client-supplied argument.
     */
    protected function requireCustomerNote(int|string|null $id): Note
    {
        $note = $this->resolveCustomerNote($id);
        abort_unless($note !== null, 404);

        return $note;
    }

    /**
     * Shared create/edit form. Tags are a plain option field (the note is not the
     * form's bound relationship owner here), synced by persistNote() after save.
     *
     * @return array<int, Component>
     */
    protected function noteFormSchema(): array
    {
        return [
            TextInput::make('title')
                ->label('Τίτλος')
                ->maxLength(255)
                ->placeholder('π.χ. Δίκτυο γραφείου / RouterOS')
                ->columnSpanFull(),

            Select::make('kind')
                ->label('Είδος')
                ->options(Note::kindOptions())
                ->default(Note::KIND_GENERAL)
                ->required()
                ->native(false),

            Toggle::make('is_pinned')
                ->label('Καρφιτσωμένη (εμφανίζεται πρώτη)'),

            TagControls::plainField(),

            Textarea::make('body')
                ->label('Κείμενο')
                ->required()
                ->rows(18)
                ->helperText('Υποστηρίζει Markdown: για configs/IP βάλε τα ανάμεσα σε ``` (code block) ώστε να διαβάζονται με σταθερό πλάτος. Εσωτερικό — δεν εκτυπώνεται και δεν αποστέλλεται στην ΑΑΔΕ.')
                ->columnSpanFull(),
        ];
    }

    /**
     * Prefill data for the edit form of an existing note (business fields + its
     * current tag ids, which the plain tags field isn't bound to relationally).
     *
     * @return array<string, mixed>
     */
    protected function noteFormData(Note $note): array
    {
        return [
            'title' => $note->title,
            'kind' => $note->kind,
            'is_pinned' => $note->is_pinned,
            'body' => $note->body,
            'tags' => $note->tags->pluck('id')->all(),
        ];
    }

    /**
     * Persist a note against THIS customer, tenant-stamped, and sync its tags.
     * Owner/company/notable are set from the page's record (never from form data),
     * so the write can't be pointed at another customer or tenant. author_user_id
     * is stamped once, on create.
     */
    protected function persistNote(Note $note, array $data): Note
    {
        abort_unless($this->canManageNotes(), 403);
        // Imported («backup») notes are ETL-owned — an edit here would be reverted
        // on the next import. Enforce the read-only rule at THE write path (not only
        // at each UI call site), so a future surface reusing persistNote() can't
        // silently overwrite one. (A brand-new note isn't imported → create is fine.)
        abort_if($note->exists && $note->isImported(), 403);

        $tags = array_filter($data['tags'] ?? []);
        unset($data['tags']);

        $note->fill($data);
        $note->company_id = $this->record->company_id;
        $note->notable_type = $this->record->getMorphClass();
        $note->notable_id = $this->record->getKey();
        if (! $note->exists) {
            $note->author_user_id = auth()->id();
        }
        $note->save();

        // Tenant-guard the SUBMITTED tag ids: the picker is tenant-scoped, but the
        // posted value is attacker-controllable — only sync tags this tenant owns,
        // so a tampered request can't attach (and surface) another tenant's tag.
        $ownTags = $tags === []
            ? []
            : Tag::query()
                ->where('company_id', $this->record->company_id)
                ->whereKey($tags)
                ->pluck('id')
                ->all();
        $note->tags()->sync($ownTags);

        return $note;
    }
}
