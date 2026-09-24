<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Resources\PaymentIntents\PaymentIntentResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\InvoiceScope;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Trail («πλήρωσα, δεν φαίνεται;»): when this Payment was settled from
                // a portal/gateway intent, show WHERE it came from — reference, gateway,
                // acquirer txn — linked to that intent. Hidden for operator/FIFO/import
                // payments (no intent).
                Placeholder::make('origin')
                    ->label('Προέλευση')
                    ->visible(fn (?Payment $record): bool => $record?->payment_intent_id !== null)
                    ->content(function (?Payment $record): HtmlString {
                        $intent = $record?->paymentIntent;
                        if ($intent === null) {
                            return new HtmlString('—');
                        }
                        $url = PaymentIntentResource::getUrl('index', ['tableSearch' => $intent->reference]);
                        $txn = filled($record->transaction_id) ? ' · κωδ. συναλλαγής '.e($record->transaction_id) : '';

                        return new HtmlString(
                            'Πληρωμή πύλης <a href="'.e($url).'" class="fi-link" style="text-decoration:underline">'
                            .e($intent->reference).'</a> · '.e($intent->gateway).$txn
                        );
                    })
                    ->columnSpanFull(),

                Select::make('customer_id')
                    ->label('Πελάτης')
                    ->options(fn () => Customer::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live(),

                Select::make('invoice_id')
                    ->label('Παραστατικό')
                    // MON-5: only ISSUED invoices are payable targets. A draft
                    // (πρόχειρο) is excluded from receivables, so attaching a payment
                    // to it would understate the balance (phantom credit); the
                    // operator finalises first, then pays. Mirrors PaymentAllocator /
                    // openInvoiceOptions, which already gate on local_status='active'.
                    // Only a LIVE document is a payment target — not a cancelled / AADE-
                    // cancelled one, and not an informal (non-fiscal) one (live() carries
                    // both), nor a credit note (MON-9).
                    ->options(function (Get $get, ?Payment $record) {
                        if (! $get('customer_id')) {
                            return [];
                        }
                        $options = InvoiceScope::excludeCreditNotes(InvoiceScope::live(Invoice::query()))
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('customer_id', $get('customer_id'))
                            ->where('local_status', '!=', 'draft')
                            ->orderByDesc('issued_at')
                            ->pluck('invcode', 'id');
                        // Editing: the payment's CURRENT link stays selectable (an AADE
                        // cancel doesn't detach payments) — else the edit can't be saved
                        // without silently turning it into on-account credit.
                        if ($record?->invoice_id !== null && ! $options->has($record->invoice_id)
                            && ($current = Invoice::query()->withTrashed()->find($record->invoice_id))) {
                            $options->put($current->id, $current->invcode);
                        }

                        return $options;
                    })
                    ->searchable()
                    ->placeholder('Έναντι λογαριασμού')
                    ->helperText('Κενό = έναντι λογαριασμού (πιστωτικό υπόλοιπο πελάτη, δεν εξοφλεί συγκεκριμένο παραστατικό).'),

                Select::make('payment_method_id')
                    ->label('Τρόπος πληρωμής')
                    ->options(fn () => PaymentMethod::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->pluck('description', 'id')),

                TextInput::make('amount')
                    ->label('Ποσό')
                    ->numeric()
                    // MON-8: amount is always stored POSITIVE (kind carries the
                    // sign). This resource only creates kind='payment', so a
                    // strictly-positive rule is correct — a negative «payment»
                    // would bypass the refund path and mis-sign the balance.
                    ->minValue(0.01)
                    ->required(),

                DatePicker::make('pay_date')
                    ->label('Ημερομηνία')
                    ->required()
                    ->default(now()),

                Textarea::make('notes')
                    ->label('Σημειώσεις')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
