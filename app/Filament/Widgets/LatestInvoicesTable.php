<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The 10 most recently issued invoices for the current tenant. A
 * quick "what just happened" pane with the myDATA state at a glance.
 * Read-only — operators drill into the Invoice resource for actions.
 */
class LatestInvoicesTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Τελευταία παραστατικά';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        $query = Invoice::query()
            // withTrashed so an invoice issued to a since-soft-deleted
            // customer still shows the party name (matches InvoiceResource).
            ->with(['customer' => fn ($q) => $q->withTrashed()])
            ->where('company_id', $tenant instanceof Company ? $tenant->id : 0)
            ->latest('issued_at')
            ->limit(10);

        return $table
            ->query($query)
            ->paginated(false)
            ->columns([
                TextColumn::make('invcode')
                    ->label('Κωδικός'),
                TextColumn::make('issued_at')
                    ->label('Ημ/νία')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->limit(35)
                    ->placeholder('—'),
                TextColumn::make('gross_total')
                    ->label('Μικτά')
                    ->alignEnd()
                    ->money('EUR'),
                TextColumn::make('mydata_state')
                    ->label('myDATA')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'VALID'     => 'Υποβλήθηκε',
                        'CANCELLED' => 'Ακυρώθηκε',
                        default     => 'Εκκρεμεί',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'VALID'     => 'success',
                        'CANCELLED' => 'danger',
                        default     => 'gray',
                    }),
            ]);
    }
}
