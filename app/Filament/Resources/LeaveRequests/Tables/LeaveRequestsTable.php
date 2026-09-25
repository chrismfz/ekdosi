<?php

namespace App\Filament\Resources\LeaveRequests\Tables;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Resources\LeaveRequests\LeaveRequestActions;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Policies\LeaveRequestPolicy;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LeaveRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('employee.last_name')
                    ->label('Εργαζόμενος')
                    ->formatStateUsing(fn (LeaveRequest $record): string => (string) $record->employee?->full_name)
                    ->searchable(['last_name', 'first_name'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('type')->label('Είδος')->badge(),
                TextColumn::make('starts_on')
                    ->label('Διάστημα')
                    ->formatStateUsing(fn (LeaveRequest $record): string => $record->periodLabel())
                    ->sortable(),
                TextColumn::make('days')->label('Ημέρες')->alignEnd()->sortable(),
                TextColumn::make('status')->label('Κατάσταση')->badge()->sortable(),
                TextColumn::make('decidedBy.name')->label('Απόφαση από')->placeholder('—')->toggleable(),
                IconColumn::make('accountant_notified')
                    ->label('Λογιστής')
                    ->tooltip(fn (LeaveRequest $record): string => match (true) {
                        $record->accountantOwed() !== null => 'Εκκρεμεί email στον λογιστή ('.($record->accountantOwed() === 'cancelled' ? 'ακύρωση' : 'έγκριση').')',
                        $record->accountant_notified_at !== null => 'Ενημερώθηκε '.$record->accountant_notified_at->format('d/m/Y H:i'),
                        default => '—',
                    })
                    ->state(fn (LeaveRequest $record): ?bool => $record->accountantOwed() !== null
                        ? false
                        : ($record->accountant_notified_at !== null ? true : null))
                    ->boolean()
                    ->visible(fn (): bool => LeaveRequestPolicy::isApprover(auth()->user()))
                    ->toggleable(),
                TextColumn::make('created_at')->label('Υποβλήθηκε')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Εργαζόμενος')
                    ->options(fn (): array => Employee::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('last_name')->get()
                        ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name])->all())
                    ->visible(fn (): bool => LeaveRequestPolicy::isApprover(auth()->user())),
                SelectFilter::make('type')->label('Είδος')->options(LeaveType::class),
                SelectFilter::make('status')->label('Κατάσταση')->options(LeaveStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    LeaveRequestActions::approve(),
                    LeaveRequestActions::reject(),
                    LeaveRequestActions::cancel(),
                ]),
            ]);
    }
}
