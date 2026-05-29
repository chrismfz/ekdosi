<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * "Πελάτες με υπόλοιπο" — the per-customer breakdown behind the
 * dashboard's "Ανεξόφλητα (πιστωτικά)" headline: WHO owes, highest
 * first. Balance is computed the same way as the headline
 * (DashboardMetrics::outstandingReceivables via
 * Customer::scopeWithOutstandingBalance) so the two agree. Read-only;
 * each name links to that customer's Καρτέλα for the full ledger.
 *
 * Auto-refreshes so the figure stays live as payments/invoices land.
 */
class OutstandingCustomersTable extends TableWidget
{
    // Right under the headline + comparison cards, above the charts.
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public function getTableHeading(): string
    {
        return 'Πελάτες με υπόλοιπο';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        // Empty query guard for the (theoretical) no-tenant render.
        if (! $tenant instanceof Company) {
            return $table->query(Customer::query()->whereRaw('1 = 0'));
        }

        return $table
            ->query((new DashboardMetrics($tenant))->topDebtorsQuery(10))
            ->paginated(false)
            ->emptyStateHeading('Κανένας πελάτης με υπόλοιπο')
            ->emptyStateDescription('Όλοι οι πελάτες με πίστωση είναι εξοφλημένοι.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->limit(40)
                    ->color('primary')
                    ->url(fn (Customer $record): string => CustomerResource::getUrl('ledger', ['record' => $record])),
                TextColumn::make('afm')
                    ->label('ΑΦΜ'),
                TextColumn::make('outstanding_balance')
                    ->label('Υπόλοιπο')
                    ->alignEnd()
                    ->money('EUR')
                    ->weight('bold')
                    ->color('danger')
                    ->sortable(),
            ]);
    }
}
