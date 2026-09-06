<?php

namespace App\Filament\Pages;

use App\Enums\LocalStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Local cross-check: where Τοπική κατάσταση and our recorded myDATA state
 * DISAGREE — a worklist of things needing action. This compares the two
 * internal fields ONLY; it does NOT call AADE (the live RequestTransmitted-
 * Docs reconciliation is the Phase-2 myDATA Console).
 *
 * Two ⚠ rows:
 *   - Cancelled locally + still VALID at AADE → must send a myDATA cancel.
 *   - Active locally + CANCELLED at AADE      → contradiction; reissue.
 */
class MyDataReconciliation extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Διασυνδέσεις';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.my-data-reconciliation';

    public static function getNavigationLabel(): string
    {
        return 'Τοπικός έλεγχος κατάστασης';
    }

    public function getTitle(): string
    {
        return 'Τοπικός έλεγχος κατάστασης';
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::mismatchQuery()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** Count of local↔AADE state contradictions for the current tenant (dashboard tile). */
    public static function mismatchCount(): int
    {
        return static::mismatchQuery()->count();
    }

    /** Tenant-scoped invoices whose local status contradicts the AADE state. */
    protected static function mismatchQuery(): Builder
    {
        return Invoice::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where(function (Builder $q) {
                $q->where(fn (Builder $w) => $w->where('local_status', 'cancelled')->where('mydata_state', 'VALID'))
                    ->orWhere(fn (Builder $w) => $w->where('local_status', 'active')->where('mydata_state', 'CANCELLED'));
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::mismatchQuery())
            ->columns([
                TextColumn::make('invcode')->label('Κωδικός')->searchable()->copyable(),
                TextColumn::make('issued_at')->label('Έκδοση')->dateTime('d/m/Y')->sortable(),
                TextColumn::make('customer.name')->label('Πελάτης')->wrap(),
                TextColumn::make('gross_total')->label('Σύνολο')->money('EUR')->alignRight(),
                TextColumn::make('local_status')
                    ->label('Τοπική')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => $s ? LocalStatus::from($s)->label() : '—')
                    ->color(fn (?string $s) => $s ? LocalStatus::from($s)->color() : 'gray'),
                TextColumn::make('mydata_state')
                    ->label('myDATA')
                    ->badge()
                    ->color(fn (?string $s) => match ($s) {
                        'VALID' => 'success', 'CANCELLED' => 'danger', default => 'gray',
                    }),
                TextColumn::make('problem')
                    ->label('Πρόβλημα')
                    ->state(fn (Invoice $r) => $r->local_status === 'cancelled' && $r->mydata_state === 'VALID'
                        ? 'Ακυρώθηκε τοπικά — χρειάζεται ακύρωση στο myDATA'
                        : 'Ενεργό τοπικά ενώ έχει ακυρωθεί στο myDATA — επανέκδοση')
                    ->badge()
                    ->color('warning')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r, 'tenant' => $r->company])),
            ])
            ->defaultSort('issued_at', 'desc')
            ->emptyStateHeading('Καμία ασυμφωνία')
            ->emptyStateDescription('Όλα τα παραστατικά συμφωνούν με την κατάσταση myDATA.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
