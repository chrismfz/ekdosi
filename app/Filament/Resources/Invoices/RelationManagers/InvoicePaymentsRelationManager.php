<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Support\BankAccountField;
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
            BankAccountField::make($this->invoice()->company_id),
            TextInput::make('transaction_id')
                ->label('Κωδικός συναλλαγής')
                ->maxLength(100)
                ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας.'),
            Textarea::make('notes')->label('Σημειώσεις')->rows(2),
        ];
    }

    private function createPayment(array $data, string $kind = 'payment'): void
    {
        $invoice = $this->invoice();
        DB::transaction(fn () => Payment::create([
            'company_id' => $invoice->company_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'kind' => $kind,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'amount' => $data['amount'],
            'pay_date' => $data['pay_date'],
            'transaction_id' => $data['transaction_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]));

        if ($kind === 'refund') {
            Notification::make()->success()->title('Η επιστροφή καταχωρίστηκε')
                ->body('Το ποσό αφαιρέθηκε από τις πληρωμές του τιμολογίου.')->send();

            return;
        }

        if ((float) $data['amount'] > $this->balance() + 0.005) {
            Notification::make()->warning()
                ->title('Υπερπληρωμή')
                ->body('Το ποσό υπερβαίνει το υπόλοιπο — το τιμολόγιο θα εμφανιστεί ως υπερπληρωμένο.')
                ->send();
        }
        Notification::make()->success()->title('Η πληρωμή καταχωρίστηκε')->send();
    }

    /** Can a payment be recorded here? Live, has a customer, not a credit note. */
    private function canRecordPayment(): bool
    {
        $invoice = $this->invoice();

        return $invoice->customer_id !== null
            && $invoice->credited_invoice_id === null
            && $invoice->mydata_state !== 'CANCELLED';
    }

    /**
     * Suggested «record payment» amount = what's left to fully record
     * (gross − credited − really-paid). For a fresh cash-term invoice that's
     * the gross (nothing logged yet); for a credit-term invoice it's the
     * outstanding balance. Never below zero.
     */
    private function suggestedAmount(): float
    {
        $invoice = $this->invoice();
        $credited = (float) $invoice->balanceData()->credited;

        return round(max((float) $invoice->gross_total - $credited - $this->paidSoFar(), 0), 2);
    }

    /**
     * Money ACTUALLY received on this invoice (Σ real payment rows, net of any
     * refunds) — the most a refund can return. Deliberately NOT
     * balanceData()->paid: for a cash-term invoice that figure is the SYNTHETIC
     * paid = owed (settled-at-issue), with no real payment rows behind it, so a
     * refund built on it would create a phantom receivable on the dashboard /
     * customer balance. Querying the rows makes the refund action correctly
     * hidden when nothing was really collected.
     */
    private function paidSoFar(): float
    {
        $row = DB::table('payments')
            ->where('invoice_id', $this->invoice()->id)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM('.Payment::NET_AMOUNT_SQL.'), 0) AS net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pay_date')->label('Ημερομηνία')->date('d/m/Y')->sortable(),
                TextColumn::make('kind')->label('Τύπος')->badge()
                    ->formatStateUsing(fn (?string $state) => Payment::kindLabel($state))
                    ->color(fn (?string $state) => $state === 'refund' ? 'warning' : 'success'),
                TextColumn::make('amount')->label('Ποσό')->money('EUR')->alignRight()->sortable(),
                TextColumn::make('paymentMethod.description')->label('Τρόπος')->placeholder('—'),
                TextColumn::make('bankAccount.bank_name')->label('Τράπεζα')->placeholder('—')->toggleable(),
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
                        BankAccountField::make($this->invoice()->company_id),
                        TextInput::make('transaction_id')
                            ->label('Κωδικός συναλλαγής')
                            ->maxLength(100)
                            ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας.'),
                    ])
                    ->action(fn (array $data) => $this->createPayment($data + ['amount' => $this->balance()])),

                // Always available (for any live, non-credit-note invoice with a
                // customer) — incl. cash-term invoices, so the operator can LOG
                // the real receipt (Stripe/POS/τράπεζα + transaction_id) for the
                // books. Recording the first payment on a cash-term invoice flips
                // it from synthetic settled-at-issue to real tracking
                // (InvoiceBalance), netting to zero — no phantom receivable.
                Action::make('record_payment')
                    ->label('Καταχώριση πληρωμής')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn () => $this->canRecordPayment())
                    ->schema(fn () => $this->paymentFields(defaultAmount: $this->suggestedAmount()))
                    ->action(fn (array $data) => $this->createPayment($data)),

                // Επιστροφή χρημάτων (refund) — money OUT, back to the customer.
                // Reduces this invoice's paid total (kind = 'refund'); useful
                // after a credit note / cancellation when cash is physically
                // returned instead of left as on-account credit. Available
                // whenever something has actually been paid here.
                Action::make('refund')
                    ->label('Επιστροφή χρημάτων')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn () => $this->paidSoFar() > 0.005)
                    ->modalDescription(fn () => 'Έχουν εισπραχθεί '.number_format($this->paidSoFar(), 2, ',', '.').' € σε αυτό το τιμολόγιο. Η επιστροφή τα αφαιρεί.')
                    ->schema(fn () => $this->paymentFields(defaultAmount: $this->paidSoFar()))
                    ->action(function (array $data) {
                        if ((float) $data['amount'] > $this->paidSoFar() + 0.005) {
                            Notification::make()->warning()
                                ->title('Προσοχή')
                                ->body('Η επιστροφή υπερβαίνει τα εισπραχθέντα — το τιμολόγιο θα εμφανιστεί με χρεωστικό υπόλοιπο.')
                                ->send();
                        }
                        $this->createPayment($data, 'refund');
                    }),

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
