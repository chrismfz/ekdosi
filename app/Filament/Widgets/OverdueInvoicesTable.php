<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «Ληξιπρόθεσμα τιμολόγια» — the dashboard surface for #6 (Due/overdue).
 * Lists credit-term invoices past their due date with an open balance,
 * oldest-due first, each linking to its ViewInvoice. Read-only; the overdue
 * predicate is {@see Invoice::scopeOverdue} (the same one behind the list
 * filter and the notification command), so all three agree. Balance comes
 * from the cached money columns (no per-row recompute).
 */
class OverdueInvoicesTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public function getTableHeading(): string
    {
        return 'Ληξιπρόθεσμα τιμολόγια';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return $table->query(Invoice::query()->whereRaw('1 = 0'));
        }

        return $table
            ->query(
                Invoice::query()
                    ->where('invoices.company_id', $tenant->id)
                    ->whereNull('invoices.deleted_at')
                    ->overdue()
                    ->with(['customer', 'paymentMethod'])
            )
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Κανένα ληξιπρόθεσμο τιμολόγιο')
            ->emptyStateDescription('Όλες οι επί πιστώσει οφειλές είναι εντός προθεσμίας ή εξοφλημένες.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('invcode')
                    ->label('Παραστατικό')
                    ->color('primary')
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->limit(35)
                    ->placeholder('—'),
                TextColumn::make('due_date')
                    ->label('Λήξη')
                    ->state(fn (Invoice $record) => $record->dueDate()?->format('d/m/Y'))
                    ->color('danger'),
                TextColumn::make('days_overdue')
                    ->label('Ημέρες')
                    ->alignEnd()
                    ->state(fn (Invoice $record) => ($d = $record->dueDate()) ? (int) abs($d->diffInDays(now())) : null)
                    ->color('danger'),
                TextColumn::make('balance')
                    ->label('Υπόλοιπο')
                    ->alignEnd()
                    ->money('EUR')
                    ->weight('bold')
                    ->color('danger')
                    ->state(fn (Invoice $record) => round(
                        (float) $record->gross_total - (float) $record->credited_total - (float) $record->paid_total,
                        2,
                    )),
            ]);
    }
}
