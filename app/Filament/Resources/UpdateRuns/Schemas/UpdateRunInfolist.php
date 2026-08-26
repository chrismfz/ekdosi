<?php

namespace App\Filament\Resources\UpdateRuns\Schemas;

use App\Models\UpdateRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only detail of one update run. The «Κατάσταση» section auto-polls while
 * the run is non-terminal, so the operator watches queued → running → succeeded
 * (and the phase + live output) without an F5. Filament 5's ViewRecord has no
 * native polling — the poll lives on the Section (the CanPoll trait is on Schema
 * components, not the page), exactly as FirebirdImportRunInfolist does.
 */
class UpdateRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Κατάσταση')
                    ->columns(3)
                    ->poll(fn ($record) => $record?->isTerminal() ? null : '3s')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Κατάσταση')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                UpdateRun::STATUS_SUCCEEDED => 'success',
                                UpdateRun::STATUS_FAILED => 'danger',
                                UpdateRun::STATUS_RUNNING => 'warning',
                                UpdateRun::STATUS_ROLLED_BACK => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                UpdateRun::STATUS_QUEUED => 'σε αναμονή',
                                UpdateRun::STATUS_RUNNING => 'εκτελείται',
                                UpdateRun::STATUS_SUCCEEDED => 'ολοκληρώθηκε',
                                UpdateRun::STATUS_FAILED => 'απέτυχε',
                                UpdateRun::STATUS_ROLLED_BACK => 'επαναφορά',
                                default => $state,
                            })
                            ->size('lg'),

                        TextEntry::make('phase')
                            ->label('Στάδιο')
                            ->badge()
                            ->placeholder('—'),

                        TextEntry::make('strategy')
                            ->label('Στρατηγική'),

                        TextEntry::make('from_version')
                            ->label('Από')
                            ->state(fn ($record) => trim(($record->from_version ? 'v'.$record->from_version : '?').' ('.($record->from_ref ?? '?').')')),

                        TextEntry::make('to_version')
                            ->label('Προς')
                            ->state(fn ($record) => trim(($record->to_version ? 'v'.$record->to_version : ($record->to_ref ?? '?')).' ('.($record->to_ref ?? '?').')')),

                        TextEntry::make('duration')
                            ->label('Διάρκεια')
                            ->state(function ($record) {
                                $seconds = $record->durationSeconds();
                                if ($seconds === null) {
                                    return '—';
                                }

                                return $seconds < 60
                                    ? $seconds.'s'
                                    : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
                            }),

                        TextEntry::make('triggeredByUser.name')
                            ->label('Από χρήστη')
                            ->placeholder('Σύστημα'),

                        TextEntry::make('started_at')
                            ->label('Έναρξη')
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),

                        TextEntry::make('finished_at')
                            ->label('Λήξη')
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),
                    ]),

                Section::make('Σφάλμα')
                    ->visible(fn ($record) => $record->status === UpdateRun::STATUS_FAILED && filled($record->error_message))
                    ->schema([
                        TextEntry::make('error_message')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->prose(),
                    ]),

                Section::make('Έξοδος')
                    ->description('Ζωντανή έξοδος της ενημέρωσης. Πλήρες αρχείο: storage/logs/updates/<id>.log')
                    ->poll(fn ($record) => $record?->isTerminal() ? null : '3s')
                    ->schema([
                        TextEntry::make('output')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->copyable()
                            ->extraAttributes([
                                'style' => 'font-family: monospace; white-space: pre-wrap; max-height: 28rem; overflow: auto;',
                            ]),
                    ]),

                Section::make('Σημείο επαναφοράς')
                    ->visible(fn ($record) => filled($record->snapshot_file))
                    ->schema([
                        TextEntry::make('snapshot_file')
                            ->label('Στιγμιότυπο ΒΔ (πριν την ενημέρωση)')
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
