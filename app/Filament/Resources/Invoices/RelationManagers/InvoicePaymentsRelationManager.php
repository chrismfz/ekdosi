<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Support\BankAccountField;
use App\Filament\Support\PaymentReceiptAction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentAllocator;
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

    /**
     * On-account credit available to THIS invoice's customer. Read through the
     * allocator so the figure here can never disagree with what applyCredit()
     * will actually let through.
     */
    private function availableCredit(): float
    {
        $customer = $this->invoice()->customer;

        return $customer === null ? 0.0 : app(PaymentAllocator::class)->availableCredit($customer);
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

        // Overpay = total really recorded now exceeds what's owed
        // (gross − credited). NOT compared against balance(): for a cash-term
        // invoice the pre-payment balance is the SYNTHETIC 0 (settled-at-issue),
        // which would false-warn on the very first (correct) receipt.
        $owed = round($invoice->payableTotal() - (float) $invoice->balanceData()->credited, 2);
        if ($this->paidSoFar() > $owed + 0.005) {
            Notification::make()->warning()
                ->title('Υπερπληρωμή')
                ->body('Το συνολικό ποσό υπερβαίνει την αξία — το τιμολόγιο θα εμφανιστεί ως υπερπληρωμένο.')
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
            // Not a credit note (neither one issued against an original, nor a
            // standalone credit-TYPE invoice). You don't collect a customer
            // payment on a πιστωτικό — and recording one would push it into the
            // receivables owed base while the ledger treats it as a credit
            // (dashboard ≠ ledger by 2×gross for that doc).
            && ! ($invoice->invoiceType?->is_credit ?? false)
            && $invoice->mydata_state !== 'CANCELLED'
            // MON-5: a draft (πρόχειρο) isn't a receivable — paying it would
            // understate the balance (phantom credit). Finalise first, then pay.
            && $invoice->local_status !== 'draft';
    }

    /**
     * May customer CREDIT be pointed at this document?
     *
     * Every guard canRecordPayment() enforces still applies — a πιστωτικό, an
     * AADE-cancelled document and a customer-less retail slip are no more valid
     * targets for credit than for a fresh receipt. The ONE relaxation is the
     * draft rule: an OFFERED προτιμολόγιο is exactly the thing we want credit to
     * be able to settle before it becomes a legal document. An un-offered draft
     * stays excluded (MON-5: paying a πρόχειρο understates the balance).
     */
    private function canApplyCredit(): bool
    {
        $invoice = $this->invoice();

        return $invoice->customer_id !== null
            && $invoice->credited_invoice_id === null
            && ! ($invoice->invoiceType?->is_credit ?? false)
            && $invoice->mydata_state !== 'CANCELLED'
            && $invoice->local_status !== 'cancelled'
            && ($invoice->local_status !== 'draft' || $invoice->isOffered());
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

        return round(max($invoice->payableTotal() - $credited - $this->paidSoFar(), 0), 2);
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
            ->modifyQueryUsing(fn ($query) => $query->with('paymentIntent:id,gateway'))
            ->columns([
                TextColumn::make('pay_date')->label('Ημερομηνία')->date('d/m/Y')->sortable(),
                TextColumn::make('kind')->label('Τύπος')->badge()
                    ->formatStateUsing(fn (?string $state) => Payment::kindLabel($state))
                    ->color(fn (?string $state) => $state === 'refund' ? 'warning' : 'success'),
                TextColumn::make('amount')->label('Ποσό')->money('EUR')->alignRight()->sortable(),
                TextColumn::make('paymentMethod.description')->label('Τρόπος')->placeholder('—'),
                // «Εξοφλήθηκε μέσω πύλης Eurobank» right on the invoice: automatic
                // (gateway) vs manual, next to the txn id column below.
                TextColumn::make('payment_channel')->label('Κανάλι')->badge()
                    ->state(fn (Payment $record): string => $record->channelLabel())
                    ->color(fn (Payment $record): string => $record->payment_intent_id !== null ? 'info' : 'gray'),
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
                // «Χρήση πίστωσης» — the customer already has money with us
                // (an overpayment, an on-account gateway payment, an unapplied
                // credit note) and it should land on THIS document. Previously this
                // existed only on the customer's Καρτέλα, which meant leaving the
                // invoice to find it; the invoice is where an operator actually
                // notices the need. Same choke-point either way
                // (PaymentAllocator::applyCredit — a re-point, net-zero on the
                // customer's total balance, never new money).
                //
                // Works on an offered προτιμολόγιο too: that is the whole point of
                // the flag — credit can settle it BEFORE it becomes a legal document.
                Action::make('apply_credit')
                    ->label('Χρήση πίστωσης')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('info')
                    ->visible(fn (): bool => $this->canApplyCredit()
                        && $this->balance() > 0.005
                        && $this->availableCredit() > 0.005)
                    ->modalHeading('Χρήση διαθέσιμης πίστωσης')
                    ->modalDescription(fn (): string => 'Διαθέσιμη πίστωση πελάτη: '
                        .number_format($this->availableCredit(), 2, ',', '.').' € · '
                        .'Υπόλοιπο παραστατικού: '.number_format($this->balance(), 2, ',', '.').' €.')
                    ->modalSubmitActionLabel('Εφαρμογή')
                    ->schema(fn () => [
                        TextInput::make('amount')
                            ->label('Ποσό (€)')
                            ->numeric()->minValue(0.01)->required()
                            ->default(fn (): string => number_format(
                                min($this->availableCredit(), $this->balance()), 2, '.', ''
                            ))
                            ->helperText('Δεν μπορεί να ξεπεράσει τη διαθέσιμη πίστωση ή το υπόλοιπο.'),
                    ])
                    ->action(function (array $data): void {
                        $customer = $this->invoice()->customer;
                        if ($customer === null) {
                            Notification::make()->danger()->title('Το παραστατικό δεν έχει πελάτη')->send();

                            return;
                        }
                        try {
                            $applied = app(PaymentAllocator::class)
                                ->applyCredit($customer, $this->invoice(), (float) $data['amount']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Δεν έγινε εφαρμογή')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Η πίστωση εφαρμόστηκε')
                            ->body(number_format($applied, 2, ',', '.').' € στο '.$this->invoice()->invcode)->send();
                    }),

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
                // «Απόδειξη είσπραξης» PDF for an incoming payment (shared factory).
                PaymentReceiptAction::make(),
                EditAction::make()
                    ->schema(fn () => $this->paymentFields()),
                DeleteAction::make(),
            ])
            ->defaultSort('pay_date', 'desc')
            ->emptyStateHeading('Καμία πληρωμή')
            ->emptyStateDescription('Χρησιμοποίησε «Πλήρης εξόφληση» ή «Μερική πληρωμή».');
    }
}
