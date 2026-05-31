<?php

namespace App\Filament\RelationManagers;

use App\Models\Activity;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only «Ιστορικό» (audit trail) for any model that uses the TracksActivity
 * trait — invoices, customers, payments. The relationship name
 * (`activitiesAsSubject`) is the same on every such model, so this ONE manager
 * is registered on each resource.
 *
 * Rows are written by spatie/laravel-activitylog; this surface only displays
 * them. Naturally tenant-safe: it shows a single record's own activities, and
 * that record is already tenant-scoped by the page that renders this manager.
 */
class ActivityLogRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $title = 'Ιστορικό';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    /**
     * The owning page already enforces view access (resource policy); the audit
     * trail rides along with it for anyone who can view the record. Returning
     * true avoids a missing Activity-policy lookup.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Πότε')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Ενέργεια')
                    ->badge()
                    ->color(fn (Activity $record): string => match ($record->event) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('causer.name')
                    ->label('Χρήστης')
                    ->placeholder('Σύστημα'),

                TextColumn::make('changes')
                    ->label('Μεταβολές')
                    ->state(fn (Activity $record): array => $record->changeLines())
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
