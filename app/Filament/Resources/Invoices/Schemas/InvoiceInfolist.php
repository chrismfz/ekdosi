<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only display of a single Invoice. The party / VAT fields are
 * pulled from the invoice's SNAPSHOT columns (address1, vat_no,
 * company_name, occupation) — these were frozen at issue time. Joining
 * through `customer` would show the live customer record, which may
 * have been edited since the invoice was filed. The legal document is
 * the snapshot.
 */
class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identification')
                    ->schema([
                        TextEntry::make('invcode')
                            ->label('Code')
                            ->copyable()
                            ->weight('bold')
                            ->size('lg'),

                        TextEntry::make('invoiceType.name')
                            ->label('Type')
                            ->placeholder('—'),

                        TextEntry::make('invoiceType.code')
                            ->label('Series')
                            ->placeholder('—'),

                        TextEntry::make('code')
                            ->label('ΑΑ')
                            ->numeric(),

                        TextEntry::make('issued_at')
                            ->label('Issued at')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('delivery_date')
                            ->label('Delivery date')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make('Customer')
                    ->description('Snapshot at issue time — these values are legally frozen and do NOT reflect later customer edits.')
                    ->schema([
                        TextEntry::make('customer.name')
                            ->label('Live customer record')
                            ->placeholder('—')
                            // Pass the Company model (Laravel uses its
                            // route key = slug, per Company::getRouteKeyName).
                            // Passing $record->company_id directly produces
                            // a URL with an integer in the {tenant} slug
                            // segment, which Filament's tenant-binding
                            // middleware then 404s.
                            ->url(fn ($record) => $record->customer_id
                                ? route('filament.admin.resources.customers.edit', [
                                    'tenant' => $record->company,
                                    'record' => $record->customer_id,
                                ])
                                : null)
                            ->color('primary'),

                        TextEntry::make('company_name')
                            ->label('Name on invoice')
                            ->placeholder('—'),

                        TextEntry::make('vat_no')
                            ->label('ΑΦΜ')
                            ->placeholder('—')
                            ->copyable(),

                        TextEntry::make('vies_vat')
                            ->label('VIES VAT')
                            ->placeholder('—'),

                        TextEntry::make('occupation')
                            ->label('Δραστηριότητα')
                            ->placeholder('—'),

                        TextEntry::make('address1')
                            ->label('Address')
                            ->placeholder('—'),

                        TextEntry::make('city')
                            ->label('City')
                            ->placeholder('—'),

                        TextEntry::make('postcode')
                            ->label('Postcode')
                            ->placeholder('—'),

                        TextEntry::make('country')
                            ->label('Country')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make('Totals')
                    ->schema([
                        TextEntry::make('net_total')
                            ->label('Net')
                            ->money('EUR'),

                        TextEntry::make('header_discount_percent')
                            ->label('Header discount')
                            ->suffix('%')
                            ->placeholder('0'),

                        TextEntry::make('gross_total')
                            ->label('Gross (with VAT)')
                            ->money('EUR')
                            ->weight('bold'),

                        TextEntry::make('withhold_amount')
                            ->label('Withholding tax')
                            ->money('EUR')
                            ->placeholder('—'),
                    ])
                    ->columns(4),

                // Live money status from App\Services\InvoiceBalance (the
                // authoritative figure; the list reads the cached column).
                Section::make('Κατάσταση πληρωμής')
                    ->schema([
                        TextEntry::make('balance_owed')
                            ->label('Οφειλόμενα')
                            ->state(fn ($record) => $record->balanceData()->owed)
                            ->money('EUR'),

                        TextEntry::make('balance_credited')
                            ->label('Πιστωμένα')
                            ->state(fn ($record) => $record->balanceData()->credited)
                            ->money('EUR'),

                        TextEntry::make('balance_paid')
                            ->label('Πληρωμένα')
                            ->state(fn ($record) => $record->balanceData()->paid)
                            ->money('EUR'),

                        TextEntry::make('balance_remaining')
                            ->label('Υπόλοιπο')
                            ->state(fn ($record) => $record->balanceData()->balance)
                            ->money('EUR')
                            ->weight('bold'),

                        TextEntry::make('balance_status')
                            ->label('Κατάσταση')
                            ->state(fn ($record) => $record->balanceData()->status->label())
                            ->badge()
                            ->color(fn ($record) => $record->balanceData()->status->color()),
                    ])
                    ->columns(5),

                Section::make('myDATA')
                    ->description('Mirror columns reflecting the latest mydata_marks submission. Full audit trail below.')
                    ->schema([
                        IconEntry::make('mydata_sent')
                            ->label('Submitted')
                            ->boolean(),

                        TextEntry::make('mydata_state')
                            ->label('State')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'VALID' => 'success',
                                'CANCELLED' => 'danger',
                                null => 'gray',
                                default => 'warning',
                            })
                            ->placeholder('pending'),

                        TextEntry::make('mydata_mark')
                            ->label('MARK')
                            ->copyable()
                            ->placeholder('—'),

                        TextEntry::make('mydata_url')
                            ->label('AADE QR URL')
                            ->url(fn (?string $state) => $state)
                            ->openUrlInNewTab()
                            ->placeholder('—')
                            ->limit(60),
                    ])
                    ->columns(4),

                Section::make('Logistics')
                    ->schema([
                        TextEntry::make('paymentMethod.description')
                            ->label('Payment method')
                            ->placeholder('—'),

                        TextEntry::make('deliveryMethod.description')
                            ->label('Delivery method')
                            ->placeholder('—'),

                        TextEntry::make('distributionAim.description')
                            ->label('Distribution aim')
                            ->placeholder('—'),

                        IconEntry::make('mailed')
                            ->boolean(),

                        IconEntry::make('printed')
                            ->boolean(),

                        TextEntry::make('email_sent')
                            ->label('Email sent to')
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label(false)
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => empty($record->notes)),
            ]);
    }
}
