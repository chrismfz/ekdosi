<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    ->options(fn (Get $get) => $get('customer_id')
                        ? Invoice::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('customer_id', $get('customer_id'))
                            ->where('local_status', '!=', 'draft')
                            ->orderByDesc('issued_at')
                            ->pluck('invcode', 'id')
                        : [])
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
