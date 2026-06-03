<?php

namespace App\Filament\RelationManagers;

use App\Models\Note;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Σημειώσεις (εσωτερικές) — reusable polymorphic operator-only notes tab.
 *
 * These are back-office only: NEVER printed on a PDF, NEVER sent to AADE
 * (distinct from the printed `invoices.notes`). Stamps `company_id` +
 * `author_user_id` on create; pinned notes sort first.
 */
class InternalNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'internalNotes';

    protected static ?string $title = 'Σημειώσεις (εσωτερικές)';

    protected static ?string $recordTitleAttribute = 'body';

    /**
     * No dedicated NotePolicy — let the parent page's authorization gate access
     * (mirrors ActivityLogRelationManager) so strict authorization doesn't throw
     * on a missing policy.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('body')
                    ->label('Σημείωση')
                    ->required()
                    ->rows(3)
                    ->helperText('Εσωτερική — δεν εκτυπώνεται στο παραστατικό και δεν αποστέλλεται στην ΑΑΔΕ.')
                    ->columnSpanFull(),

                Toggle::make('is_pinned')
                    ->label('Καρφιτσωμένη (εμφανίζεται πρώτη)'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_pinned')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-s-bookmark')
                    ->falseIcon('')
                    ->alignCenter(),

                TextColumn::make('body')
                    ->label('Σημείωση')
                    ->wrap()
                    ->limit(200)
                    ->searchable(),

                TextColumn::make('source')
                    ->label('Πηγή')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state, Note $record): string => $record->sourceLabel() ?? '—')
                    ->placeholder('—'),

                TextColumn::make('author.name')
                    ->label('Από')
                    ->placeholder('Σύστημα'),

                TextColumn::make('created_at')
                    ->label('Ημερομηνία')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Νέα σημείωση')
                    ->mutateDataUsing(function (array $data): array {
                        $data['company_id'] = Filament::getTenant()?->getKey();
                        $data['author_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                // Imported («από backup») notes are managed by the import — the
                // next run would revert an edit / restore a delete anyway, so
                // they're read-only here. Operators annotate with their own note.
                EditAction::make()->visible(fn (Note $record): bool => ! $record->isImported()),
                DeleteAction::make()->visible(fn (Note $record): bool => ! $record->isImported()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('is_pinned', 'desc');
    }
}
