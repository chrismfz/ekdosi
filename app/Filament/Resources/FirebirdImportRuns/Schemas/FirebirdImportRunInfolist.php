<?php

namespace App\Filament\Resources\FirebirdImportRuns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * PR #30 — Read-only details of one Firebird import run.
 *
 * Auto-polls while the run is in a non-terminal state — the operator
 * lands here right after submitting the upload form and watches the
 * status transition uploaded → restoring → importing → completed.
 */
class FirebirdImportRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Status')
                    ->columns(3)
                    // Poll the status section so the operator sees
                    // restoring → importing → completed transitions
                    // without F5. Filament 5's ViewRecord page itself
                    // does NOT have a pollingInterval property
                    // (verified at vendor — only Tables and Schema
                    // Components carry the CanPoll trait), so the
                    // poll must live on a Section inside the Infolist.
                    // Returns null for terminal rows so the polling
                    // stops once the run is done; no point hammering
                    // the DB for a row that won't change.
                    ->poll(fn ($record) => $record?->isTerminal() ? null : '3s')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'failed'    => 'danger',
                                'restoring', 'importing' => 'warning',
                                default     => 'gray',
                            })
                            ->size('lg'),

                        TextEntry::make('started_at')
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),

                        TextEntry::make('finished_at')
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),

                        TextEntry::make('duration')
                            ->label('Duration')
                            ->state(function ($record) {
                                $seconds = $record->durationSeconds();
                                if ($seconds === null) {
                                    return '—';
                                }
                                if ($seconds < 60) {
                                    return $seconds.'s';
                                }
                                return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
                            }),

                        TextEntry::make('uploadedByUser.name')
                            ->label('Uploaded by')
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime('Y-m-d H:i:s'),
                    ]),

                Section::make('Failure detail')
                    ->visible(fn ($record) => $record->status === 'failed')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('failed_step')
                            ->label('Failed at step')
                            ->badge()
                            ->color('danger'),

                        TextEntry::make('error_message')
                            ->label('Error')
                            ->columnSpanFull()
                            ->prose(),
                    ]),

                Section::make('Imported rows')
                    ->visible(fn ($record) => $record->status === 'completed' && is_array($record->counts_json))
                    ->description('Total per-tenant row counts AFTER the import. Re-runs that found no new legacy rows show the same numbers as the previous run.')
                    ->schema([
                        TextEntry::make('counts_json')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(function ($record) {
                                if (! is_array($record->counts_json)) {
                                    return '—';
                                }
                                $lines = [];
                                foreach ($record->counts_json as $table => $count) {
                                    $lines[] = sprintf('%-25s %s', $table, number_format($count));
                                }
                                return implode("\n", $lines);
                            })
                            ->copyable()
                            ->extraAttributes(['style' => 'font-family: monospace; white-space: pre;']),
                    ]),

                Section::make('Backup file')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('file_name')
                            ->label('Filename')
                            ->copyable(),

                        TextEntry::make('file_size')
                            ->label('Size')
                            ->formatStateUsing(fn (?int $state) => $state ? round($state / 1024 / 1024, 1).' MB' : '—'),

                        TextEntry::make('file_sha256')
                            ->label('SHA256')
                            ->copyable()
                            ->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 12).'…' : '—')
                            ->helperText('Hash of the uploaded file. If a previous successful run shares this hash, the import is a no-op (or near-no-op) re-run.'),

                        TextEntry::make('fb_host')
                            ->label('Firebird host'),

                        TextEntry::make('fb_user')
                            ->label('Firebird user'),
                    ]),
            ])
            // Auto-poll while non-terminal so the badge flips without
            // an operator F5. The Infolist isn't paged itself; we use
            // the page-level poll on the ViewFirebirdImportRun page.
            ;
    }
}
