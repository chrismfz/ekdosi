<?php

namespace App\Filament\Resources\ServiceContracts\RelationManagers;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Ανανεώσεις» — read-only list of the invoices billed from this service
 * contract (staged renewals AND any invoice retro-linked to it). It is the
 * tangible "how many times billed / what was charged each cycle" history: each
 * row is one document with its money + status, linking to the full invoice.
 *
 * Read-only: the invoices live their own lifecycle (Οριστικοποίηση → myDATA);
 * this surface never creates/edits/detaches. Naturally tenant-safe — it shows a
 * single contract's own invoices() and that contract is already tenant-scoped by
 * the page rendering this manager.
 */
class RenewalsRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Ανανεώσεις';

    protected static string|BackedEnum|null $icon = 'heroicon-o-receipt-percent';

    /** The owning page already enforces view access; the list rides along. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invcode')
            ->description('Τα παραστατικά που χρεώθηκαν από τη σύμβαση. Τα «Στατιστικά χρέωσης» αφαιρούν τυχόν πιστωτικά, οπότε το «Συνολικό έσοδο» μπορεί να είναι μικρότερο από το άθροισμα εδώ.')
            ->columns([
                TextColumn::make('invcode')
                    ->label('Κωδικός')
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('issued_at')
                    ->label('Ημ/νία')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                TextColumn::make('local_status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Πρόχειρο',
                        'active' => 'Ενεργό',
                        'cancelled' => 'Ακυρωμένο',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('payment_status')
                    ->label('Πληρωμή')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => PaymentStatus::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => PaymentStatus::tryFrom((string) $state)?->color() ?? 'gray'),

                TextColumn::make('net_total')
                    ->label('Καθαρό')
                    ->money('EUR')
                    ->alignEnd(),

                TextColumn::make('gross_total')
                    ->label('Μικτό')
                    ->money('EUR')
                    ->alignEnd(),
            ])
            ->defaultSort('issued_at', 'desc')
            // Reuse the panel tenant (already loaded) instead of $record->company,
            // which would lazy-load the company relation once per row (N+1).
            ->recordUrl(fn (Invoice $record): string => InvoiceResource::getUrl('view', [
                'record' => $record,
                'tenant' => Filament::getTenant(),
            ]))
            ->emptyStateHeading('Καμία χρέωση ακόμη')
            ->emptyStateDescription('Δεν έχει τιμολογηθεί ανανέωση για αυτό το συμβόλαιο (ή δεν έχει συνδεθεί υπάρχον παραστατικό).')
            ->paginated([10, 25, 50]);
    }
}
