<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Top 10 customers by invoiced gross this calendar year — answers both
 * "most invoiced" and "highest-value" customers. Read-only.
 */
class TopCustomersTable extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Κορυφαίοι πελάτες ('.now()->year.')';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        // Empty query guard for the (theoretical) no-tenant render.
        if (! $tenant instanceof Company) {
            return $table->query(\App\Models\Customer::query()->whereRaw('1 = 0'));
        }

        $now = now();
        $query = (new DashboardMetrics($tenant))->topCustomersQuery(
            $now->copy()->startOfYear(),
            $now->copy()->endOfYear(),
            10,
        );

        return $table
            ->query($query)
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('afm')
                    ->label('ΑΦΜ'),
                TextColumn::make('invoices_ytd')
                    ->label('Παραστατικά')
                    ->alignEnd(),
                TextColumn::make('gross_ytd')
                    ->label('Σύνολο (μικτά)')
                    ->alignEnd()
                    ->money('EUR')
                    ->weight('bold'),
            ]);
    }
}
