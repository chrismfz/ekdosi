<?php

namespace App\Filament\Widgets;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use App\Models\Company;
use App\Models\ServiceContract;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * «Επερχόμενες ανανεώσεις» — the dashboard pipeline of Active service contracts
 * whose next_due_date falls in the next 30 days, oldest-due first. Each row
 * links to its ViewServiceContract. Read-only; the scheduled
 * services:stage-renewals command will stage a draft when these come due.
 */
class UpcomingRenewalsTable extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public function getTableHeading(): string
    {
        return 'Επερχόμενες ανανεώσεις (30 ημέρες)';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return $table->query(ServiceContract::query()->whereRaw('1 = 0'));
        }

        return $table
            ->query(
                ServiceContract::query()
                    ->where('service_contracts.company_id', $tenant->id)
                    ->whereNull('service_contracts.deleted_at')
                    ->where('status', ServiceContractStatus::Active->value)
                    ->whereNotNull('next_due_date')
                    ->whereDate('next_due_date', '>=', Carbon::today())
                    ->whereDate('next_due_date', '<=', Carbon::today()->addDays(30))
                    ->with(['customer:id,name', 'product:id,description_short'])
            )
            ->defaultSort('next_due_date', 'asc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Καμία ανανέωση τις επόμενες 30 ημέρες')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->color('primary')
                    ->limit(35)
                    ->placeholder('—')
                    ->url(fn (ServiceContract $record): string => ServiceContractResource::getUrl('view', ['record' => $record])),

                TextColumn::make('description')
                    ->label('Περιγραφή')
                    ->state(fn (ServiceContract $record) => $record->description ?: $record->product?->description_short ?: '—')
                    ->limit(40),

                TextColumn::make('billing_cycle')
                    ->label('Κύκλος')
                    ->badge()
                    ->formatStateUsing(fn (BillingCycle $state) => $state->label()),

                TextColumn::make('amount')
                    ->label('Ποσό')
                    ->alignEnd()
                    ->money('EUR'),

                TextColumn::make('next_due_date')
                    ->label('Επόμενη χρέωση')
                    ->date('d/m/Y')
                    ->description(fn (ServiceContract $record) => ($d = $record->next_due_date)
                        ? 'σε '.max(0, (int) Carbon::today()->diffInDays($d, false)).' ημέρες'
                        : null)
                    ->color('warning'),
            ]);
    }
}
