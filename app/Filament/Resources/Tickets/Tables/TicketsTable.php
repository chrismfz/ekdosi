<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'department', 'assignee']))
            ->columns([
                TextColumn::make('reference')
                    ->label('Κωδικός')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('subject')
                    ->label('Θέμα')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                TextColumn::make('requester')
                    ->label('Αιτών')
                    ->state(fn (Ticket $record): string => $record->requesterLabel())
                    ->description(fn (Ticket $record): ?string => $record->isGuest() ? 'GUEST' : null)
                    ->searchable(['requester_email', 'requester_name']),
                TextColumn::make('department.name')
                    ->label('Τμήμα')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Προτεραιότητα')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('assignee.name')
                    ->label('Χειριστής')
                    ->placeholder('— χωρίς ανάθεση —')
                    ->toggleable(),
                TextColumn::make('last_reply_at')
                    ->label('Τελευταία απάντηση')
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    ->tooltip(fn (Ticket $record): ?string => $record->last_reply_at?->format('d/m/Y H:i')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->multiple()
                    ->options(TicketStatus::options()),
                SelectFilter::make('priority')
                    ->label('Προτεραιότητα')
                    ->options(TicketPriority::options()),
                SelectFilter::make('ticket_department_id')
                    ->label('Τμήμα')
                    ->options(fn (): array => TicketDepartment::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('assigned_to')
                    ->label('Χειριστής')
                    ->options(fn (): array => Filament::getTenant()
                        ?->users()->orderBy('name')->pluck('users.name', 'users.id')->all() ?? []),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('last_reply_at', 'desc');
    }
}
