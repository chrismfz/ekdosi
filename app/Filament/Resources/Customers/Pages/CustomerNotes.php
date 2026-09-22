<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\Concerns\ManagesCustomerNotes;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Customer;
use App\Models\Note;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * «Σημειώσεις πελάτη» — a joplin-junior surface for the operator's per-customer
 * knowledge: short chronological notes AND long-form technical dossiers (RouterOS
 * exports, IP tables, TeamViewer/AnyDesk ids). Same polymorphic Note store as the
 * per-record «Σημειώσεις (εσωτερικές)» tab — this is just the roomy, searchable,
 * tag-filterable home for it, reachable straight from the Καρτέλα.
 *
 * Read/write of internal notes rides on Customer view/update rights (there is no
 * dedicated NotePolicy). canAccess is Gate::can('View:Customer') — 404-storm-safe
 * (a missing permission resolves to false, not a throw) — and mount() re-checks
 * the per-record view ability + the tenant boundary, exactly like the Καρτέλα.
 */
class CustomerNotes extends Page implements HasTable
{
    use InteractsWithTable;
    use ManagesCustomerNotes;

    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.customers.notes';

    #[Locked]
    public Customer|Model|int|string|null $record = null;

    public static function canAccess(array $parameters = []): bool
    {
        return (bool) auth()->user()?->can('View:Customer');
    }

    public function mount(int|string $record): void
    {
        $this->record = Customer::query()->withTrashed()->where('id', (int) $record)->firstOrFail();

        $tenant = Filament::getTenant();

        if ($tenant && (int) $this->record->company_id !== (int) $tenant->getKey()) {
            abort(404);
        }

        abort_unless(auth()->user()?->can('view', $this->record), 403);
    }

    public function getTitle(): string
    {
        return 'Σημειώσεις: '.($this->record?->name ?? '(unknown)');
    }

    public function getBreadcrumb(): string
    {
        return 'Σημειώσεις';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_kartela')
                ->label('Καρτέλα')
                ->icon('heroicon-o-document-chart-bar')
                ->color('primary')
                ->url(fn (): string => CustomerResource::getUrl('ledger', ['record' => $this->record])),

            Action::make('edit_customer')
                ->label('Επεξεργασία πελάτη')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn (): string => CustomerResource::getUrl('edit', ['record' => $this->record])),

            Action::make('back_to_list')
                ->label('Λίστα πελατών')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => CustomerResource::getUrl('index')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Note::query()
                    ->where('company_id', $this->record->company_id)
                    ->where('notable_type', $this->record->getMorphClass())
                    ->where('notable_id', $this->record->getKey())
                    ->with(['tags', 'author'])
                    ->orderByDesc('is_pinned')
                    ->orderByDesc('updated_at')
            )
            ->columns([
                ViewColumn::make('card')
                    ->label('Σημείωση')
                    ->view('filament.customers.note-card')
                    // One search box over title + body; the callback runs inside
                    // Filament's own search group, so the OR stays scoped.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%")),
            ])
            ->contentGrid(['default' => 1])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Είδος')
                    ->options(Note::kindOptions()),
                TagControls::filter(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Νέα σημείωση')
                    ->icon('heroicon-o-plus')
                    ->model(Note::class)
                    ->visible(fn (): bool => $this->canManageNotes())
                    ->modalHeading('Νέα σημείωση')
                    ->modalWidth('5xl')
                    ->schema($this->noteFormSchema())
                    ->using(fn (array $data): Note => $this->persistNote(new Note, $data)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Note $record): string => $record->displayTitle())
                    ->modalWidth('5xl')
                    ->modalContent(fn (Note $record) => view('filament.customers.note-view', ['note' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Κλείσιμο'),

                // Imported («από backup») notes are managed by the ETL — the next
                // run would revert an edit / restore a delete anyway, so they are
                // read-only here (mirrors the relation manager). Edit/delete also
                // require Customer update rights.
                EditAction::make()
                    ->modalHeading('Επεξεργασία σημείωσης')
                    ->modalWidth('5xl')
                    ->visible(fn (Note $record): bool => ! $record->isImported() && $this->canManageNotes())
                    ->schema($this->noteFormSchema())
                    ->mutateRecordDataUsing(function (array $data, Note $record): array {
                        $data['tags'] = $record->tags->pluck('id')->all();

                        return $data;
                    })
                    ->using(fn (Note $record, array $data): Note => $this->persistNote($record, $data)),

                DeleteAction::make()
                    ->visible(fn (Note $record): bool => ! $record->isImported() && $this->canManageNotes()),
            ])
            // Bounded page sizes on purpose (no «all»): each card re-parses its
            // markdown body per render, so an unbounded page on a customer with
            // hundreds of notes would run hundreds of conversions per keystroke.
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12)
            ->emptyStateHeading('Καμία σημείωση ακόμη')
            ->emptyStateDescription('Κράτα εδώ ό,τι χρειάζεται η υποστήριξη του πελάτη: IPs, servers/workstations, εκτυπωτές, TeamViewer/AnyDesk, RouterOS export…')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
