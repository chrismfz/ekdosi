<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Επαφές — named people behind a customer (λογιστήριο, τεχνικός, υπεύθυνος…).
 *
 * Stamps `company_id` on create like the other tenant-owned child managers
 * ({@see PriceTiersRelationManager}); the parent Customer already belongs to
 * this tenant. Single-primary is enforced on the model ({@see \App\Models\
 * CustomerContact::booted}).
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Επαφές';

    protected static ?string $recordTitleAttribute = 'name';

    /** Common Greek roles offered as free-text suggestions. */
    private const ROLE_SUGGESTIONS = [
        'Λογιστήριο', 'Τεχνικός', 'Υπεύθυνος', 'Διοίκηση', 'Πωλήσεις',
        'Προμήθειες', 'Οικονομικό', 'Γραμματεία',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Ονοματεπώνυμο')
                    ->required()
                    ->maxLength(255),

                TextInput::make('role')
                    ->label('Ρόλος / Τμήμα')
                    ->datalist(self::ROLE_SUGGESTIONS)
                    ->maxLength(255)
                    ->helperText('π.χ. Λογιστήριο, Τεχνικός — ελεύθερο κείμενο.'),

                TextInput::make('phone')
                    ->label('Τηλέφωνο')
                    ->tel()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),

                Toggle::make('is_primary')
                    ->label('Κύρια επαφή')
                    ->helperText('Μόνο μία κύρια επαφή ανά πελάτη — οι υπόλοιπες υποβιβάζονται αυτόματα.'),

                Textarea::make('notes')
                    ->label('Σημειώσεις')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_primary')
                    ->label('Κύρια')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('name')
                    ->label('Ονοματεπώνυμο')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('role')
                    ->label('Ρόλος')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label('Τηλέφωνο')
                    ->placeholder('—'),

                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Νέα επαφή')
                    ->mutateDataUsing(function (array $data): array {
                        // Mirror the parent Customer's tenant — the global
                        // CompanyScope is read-only (no auto-fill on create).
                        $data['company_id'] = Filament::getTenant()?->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('is_primary', 'desc');
    }
}
