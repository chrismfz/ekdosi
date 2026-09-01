<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Support\Tags\TagControls;
use App\Models\Lead;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('timeline')
                ->with(['assignedTo', 'tags']))
            ->columns([
                TextColumn::make('name')
                    ->label('Επωνυμία')
                    ->description(fn (Lead $record): ?string => $record->contact_person)
                    ->searchable(['name', 'contact_person', 'afm', 'email', 'phone', 'mobile'])
                    ->sortable()
                    ->wrap()
                    ->weight('medium'),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->sortable(),

                TextColumn::make('assignedTo.name')
                    ->label('Χειριστής')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Τηλέφωνο')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable()
                    ->limit(30)
                    ->tooltip(fn ($state): ?string => $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_activity_at')
                    ->label('Τελευταία επαφή')
                    ->since()
                    ->placeholder('καμία')
                    ->sortable()
                    ->tooltip(fn (Lead $record): ?string => $record->last_activity_at?->format('d/m/Y H:i')),

                TextColumn::make('next_action_at')
                    ->label('Επόμενο βήμα')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Lead $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (Lead $record): ?string => $record->isOverdue() ? 'bold' : null)
                    ->icon(fn (Lead $record): ?string => $record->isOverdue() ? 'heroicon-o-exclamation-circle' : null),

                TextColumn::make('timeline_count')
                    ->label('Επαφές')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Πηγή')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TagControls::column(),

                TextColumn::make('created_at')
                    ->label('Δημιουργήθηκε')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->multiple()
                    ->options(collect(LeadStatus::cases())
                        ->mapWithKeys(fn (LeadStatus $s): array => [$s->value => $s->getLabel()])
                        ->all()),

                SelectFilter::make('assigned_user_id')
                    ->label('Χειριστής')
                    ->options(fn (): array => Filament::getTenant()
                        ?->users()->orderBy('name')->pluck('users.name', 'users.id')->all() ?? []),

                SelectFilter::make('source')
                    ->label('Πηγή')
                    ->options(LeadSource::options()),

                TagControls::filter(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
