<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Per-invoice payments cockpit. Lists the invoice's payments and lets the
 * operator «κόψει/ράψει» — full settle, partial, edit, delete, or «mark unpaid»
 * (clear all). The money cache recomputes automatically via PaymentObserver on
 * every create/edit/delete; the balance shown here comes from InvoiceBalance.
 *
 * Partial payments already work (a Payment.amount below the balance leaves the
 * invoice «μερικώς πληρωμένο»); over-payment is allowed (→ Overpaid) but warned.
 * Customer-level multi-invoice allocation (one «έμβασμα» across many invoices) is
 * a separate screen (Phase 2).
 */
class InvoicePaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Πληρωμές';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function invoice(): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->getOwnerRecord();

        return $invoice;
    }

    private function balance(): float
    {
        return (float) $this->invoice()->balanceData()->balance;
    }

    private function paymentMethodOptions(): array
    {
        return PaymentMethod::query()
            ->where('company_id', $this->invoice()->company_id)
            ->pluck('description', 'id')
            ->all();
    }

    /** Shared form for create/edit of a single payment. */
    private function paymentFields(?float $defaultAmount = null): array
    {
        return [
            TextInput::make('amount')
                ->label('Ποσό (€)')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->default($defaultAmount !== null ? number_format($defaultAmount, 2, '.', '') : null)
                ->helperText('Υπόλοιπο: '.number_format($this->balance(), 2, ',', '.').' €'),
            DatePicker::make('pay_date')
                ->label('Ημερομηνία')
                ->required()
                ->default(now()),
            Select::make('payment_method_id')
                ->label('Τρόπος πληρωμής')
                ->options(fn () => $this->paymentMethodOptions())
                ->default(fn () => $this->invoice()->payment_method_id),
            TextInput::make('transaction_id')
                ->label('Κωδικός συναλλαγής')
                ->maxLength(100)
                ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας.'),
            Textarea::make('notes')->label('Σημειώσεις')->rows(2),
        ];
    }

    private function createPayment(array $data): void
    {
        $invoice = $this->invoice();
        DB::transaction(fn () => Payment::create([
            'company_id' => $invoice->company_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'amount' => $data['amount'],
            'pay_date' => $data['pay_date'],
            'transaction_id' => $data['transaction_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]));

        if ((float) $data['amount'] > $this->balance() + 0.005) {
            Notification::make()->warning()
                ->title('Υπερπληρωμή')
                ->body('Το ποσό υπερβαίνει το υπόλοιπο — το τιμολόγιο θα εμφανιστεί ως υπερπληρωμένο.')
                ->send();
        }
        Notification::make()->success()->title('Η πληρωμή καταχωρίστηκε')->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pay_date')->label('Ημερομηνία')->date('d/m/Y')->sortable(),
                TextColumn::make('amount')->label('Ποσό')->money('EUR')->alignRight()->sortable(),
                TextColumn::make('paymentMethod.description')->label('Τρόπος')->placeholder('—'),
                TextColumn::make('transaction_id')->label('Κωδ. συναλλαγής')->placeholder('—')->copyable()->toggleable(),
                TextColumn::make('notes')->label('Σημείωση')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('settle_full')
                    ->label('Πλήρης εξόφληση')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn () => $this->balance() > 0.005)
                    ->requiresConfirmation()
                    ->modalDescription(fn () => 'Καταχώριση πληρωμής για το υπόλοιπο: '.number_format($this->balance(), 2, ',', '.').' €.')
                    ->schema(fn () => [
                        DatePicker::make('pay_date')->label('Ημερομηνία')->required()->default(now()),
                        Select::make('payment_method_id')->label('Τρόπος πληρωμής')
                            ->options(fn () => $this->paymentMethodOptions())
                            ->default(fn () => $this->invoice()->payment_method_id),
                        TextInput::make('transaction_id')
                            ->label('Κωδικός συναλλαγής')
                            ->maxLength(100)
                            ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας.'),
                    ])
                    ->action(fn (array $data) => $this->createPayment($data + ['amount' => $this->balance()])),

                Action::make('settle_partial')
                    ->label('Μερική πληρωμή')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn () => $this->balance() > 0.005)
                    ->schema(fn () => $this->paymentFields(defaultAmount: $this->balance()))
                    ->action(fn (array $data) => $this->createPayment($data)),

                Action::make('mark_unpaid')
                    ->label('Σήμανση ως ανεξόφλητο')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn () => $this->invoice()->payments()->exists())
                    ->requiresConfirmation()
                    ->modalHeading('Σήμανση ως ανεξόφλητο')
                    ->modalDescription(fn () => 'Θα διαγραφούν ΟΛΕΣ οι πληρωμές αυτού του τιμολογίου ('
                        .$this->invoice()->payments()->count().'). Το υπόλοιπο επιστρέφει στο πλήρες ποσό. Χρήσιμο π.χ. για μια λανθασμένη πληρωμή από import.')
                    ->action(function () {
                        $invoice = $this->invoice();
                        DB::transaction(function () use ($invoice) {
                            foreach ($invoice->payments()->get() as $payment) {
                                $payment->delete(); // PaymentObserver recomputes
                            }
                        });
                        Notification::make()->success()->title('Σημάνθηκε ως ανεξόφλητο')
                            ->body('Διαγράφηκαν οι πληρωμές· το υπόλοιπο επανυπολογίστηκε.')->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn () => $this->paymentFields()),
                DeleteAction::make(),
            ])
            ->defaultSort('pay_date', 'desc')
            ->emptyStateHeading('Καμία πληρωμή')
            ->emptyStateDescription('Χρησιμοποίησε «Πλήρης εξόφληση» ή «Μερική πληρωμή».');
    }
}
