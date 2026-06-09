<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\CompanyBackupRun;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

/**
 * Read-only history of the company's backup runs (Phase 4) with a per-row
 * Download for local bundles. Rows are written by CompanyBackupRunner — nothing
 * here creates/edits/deletes.
 */
class CompanyBackupRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'backupRuns';

    protected static ?string $title = 'Αντίγραφα ασφαλείας';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->emptyStateHeading('Κανένα αντίγραφο ακόμα')
            ->emptyStateDescription('Ρύθμισε «Αυτόματα αντίγραφα» και τρέξε «Αντίγραφο τώρα».')
            ->columns([
                TextColumn::make('started_at')->label('Πότε')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('trigger')->label('Έναυσμα')->badge()
                    ->formatStateUsing(fn (?string $s) => $s === 'scheduled' ? 'Προγραμματισμένο' : 'Χειροκίνητο')
                    ->color(fn (?string $s) => $s === 'scheduled' ? 'info' : 'gray'),
                TextColumn::make('bucket')->label('Περιεχόμενο')
                    ->formatStateUsing(fn (?string $s) => match ($s) {
                        'settings' => 'Ρυθμίσεις', 'full' => 'Πλήρες', default => 'Ρυθμίσεις+setup',
                    }),
                TextColumn::make('status')->label('Κατάσταση')->badge()
                    ->color(fn (CompanyBackupRun $record) => $record->statusColor()),
                TextColumn::make('bytes')->label('Μέγεθος')
                    ->formatStateUsing(fn (?int $b) => $b ? number_format($b / 1024, 1).' KB' : '—'),
                TextColumn::make('message')->label('Σημείωση')->placeholder('—')->limit(60)->toggleable(),
            ])
            ->recordActions([
                // A signed link to the streaming download route (not an action
                // returning the file — that buffers the whole bundle in memory).
                Action::make('download')
                    ->label('Λήψη')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (CompanyBackupRun $record) => $record->isDownloadable())
                    ->url(fn (CompanyBackupRun $record) => URL::temporarySignedRoute(
                        'company-backups.download', now()->addMinutes(15), ['run' => $record->getKey()],
                    ), shouldOpenInNewTab: true),
            ]);
    }
}
