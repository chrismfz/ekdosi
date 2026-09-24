<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\DeliveryNoteLine;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Append-only stock ledger for a product. Read-only rows (the ledger is never
 * edited — you correct with a new adjustment movement). The «Καταχώριση κίνησης»
 * header action records manual receipts / opening counts / corrections via
 * StockService; auto sale/purchase/return movements (S2/S3) land here too.
 */
class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Κινήσεις αποθέματος';

    /** Greek labels for the reason badge. */
    private const REASON_LABELS = [
        'receipt' => 'Παραλαβή (+)',
        'initial' => 'Αρχική απογραφή',
        'adjustment' => 'Διόρθωση (+/−)',
        'sale' => 'Πώληση (−)',
        'purchase' => 'Αγορά (+)',
        'return' => 'Επιστροφή (+)',
        'cancel' => 'Αναστροφή ακύρωσης',
        'conversion' => 'Μετατροπή σε φορολογικό',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Ημ/νία')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('qty_change')
                    ->label('Μεταβολή')
                    ->numeric(decimalPlaces: 3)
                    ->badge()
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '').rtrim(rtrim((string) $state, '0'), '.'))
                    ->alignRight(),

                TextColumn::make('reason')
                    ->label('Αιτία')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => self::REASON_LABELS[$state] ?? $state)
                    ->color('gray'),

                TextColumn::make('source_type')
                    ->label('Πηγή')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        null => 'Χειροκίνητη',
                        InvoiceLine::class => 'Τιμολόγιο',
                        DeliveryNoteLine::class => 'Δελτίο',
                        default => class_basename($state),
                    })
                    ->badge()
                    ->color('gray'),

                TextColumn::make('note')
                    ->label('Σημείωση')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('record_movement')
                    ->label('Καταχώριση κίνησης')
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        Select::make('reason')
                            ->label('Είδος κίνησης')
                            ->options([
                                StockMovement::REASON_RECEIPT => self::REASON_LABELS['receipt'],
                                StockMovement::REASON_INITIAL => self::REASON_LABELS['initial'],
                                StockMovement::REASON_ADJUSTMENT => self::REASON_LABELS['adjustment'],
                            ])
                            ->default(StockMovement::REASON_RECEIPT)
                            ->required(),
                        TextInput::make('qty_change')
                            ->label('Ποσότητα (+ είσοδος / − έξοδος)')
                            ->numeric()
                            ->required()
                            ->helperText('Π.χ. 10 για παραλαβή, −2 για διόρθωση/φύρα.'),
                        DateTimePicker::make('occurred_at')
                            ->label('Ημ/νία')
                            ->default(now())
                            ->required(),
                        Textarea::make('note')
                            ->label('Σημείωση')
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        if (! $product->track_stock) {
                            Notification::make()
                                ->warning()
                                ->title('Το είδος δεν παρακολουθείται')
                                ->body('Ενεργοποίησε «Παρακολούθηση αποθέματος» στο προϊόν πρώτα.')
                                ->send();

                            return;
                        }

                        app(StockService::class)->record(
                            product: $product,
                            qtyChange: (float) $data['qty_change'],
                            reason: $data['reason'],
                            note: $data['note'] ?? null,
                            occurredAt: Carbon::parse($data['occurred_at']),
                        );

                        Notification::make()
                            ->success()
                            ->title('Η κίνηση καταχωρήθηκε')
                            ->body('Νέο απόθεμα: '.rtrim(rtrim((string) app(StockService::class)->currentStock($product), '0'), '.'))
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
