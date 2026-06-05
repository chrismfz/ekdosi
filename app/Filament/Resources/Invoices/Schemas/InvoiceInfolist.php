<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Facades\Filament;
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

                // Bidirectional credit-note binding. The data is the
                // credited_invoice_id FK we already store — surfaced here so an
                // «Ακύρωση μέσω πιστωτικού» has a visible audit trail on BOTH
                // documents: the original shows the credit note(s) that reversed
                // it; the credit note shows the invoice it reverses. Hidden when
                // there's no relationship (a plain invoice).
                Section::make('Σχετικά παραστατικά')
                    ->icon('heroicon-o-link')
                    ->visible(fn ($record) => $record->credited_invoice_id !== null
                        || $record->creditNotes()->exists())
                    ->schema([
                        // Credit-note side → the invoice it reverses.
                        TextEntry::make('credited_for')
                            ->label('Πιστωτικό — αντιστρέφει το παραστατικό')
                            ->state(fn ($record) => $record->creditedInvoice?->invcode)
                            ->url(fn ($record) => $record->creditedInvoice
                                ? InvoiceResource::getUrl('view', [
                                    'record' => $record->creditedInvoice,
                                    'tenant' => $record->company,
                                ])
                                : null)
                            ->color('primary')
                            ->weight('bold')
                            ->helperText('Αυτό το πιστωτικό εκδόθηκε για να ακυρώσει/διορθώσει το παραπάνω παραστατικό.')
                            ->visible(fn ($record) => $record->credited_invoice_id !== null),

                        // Original side → the credit note(s) that reversed it.
                        TextEntry::make('cancelled_by')
                            ->label('Ακυρώθηκε / πιστώθηκε με')
                            ->state(fn ($record) => $record->creditNotes->pluck('invcode')->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            // Single credit note (the full-cancel case) → clickable;
                            // multiple partials are listed (open each from the list).
                            ->url(fn ($record) => $record->creditNotes->count() === 1
                                ? InvoiceResource::getUrl('view', [
                                    'record' => $record->creditNotes->first(),
                                    'tenant' => $record->company,
                                ])
                                : null)
                            ->color('primary')
                            ->helperText('Το αρχικό παραμένει VALID στην ΑΑΔΕ· το/τα πιστωτικό/ά το μηδενίζει/ουν λογιστικά.')
                            ->visible(fn ($record) => $record->creditNotes()->exists()),
                    ])
                    ->columns(2),

                Section::make('myDATA / Πάροχος')
                    ->description('Κατάσταση τελευταίας υποβολής (άμεσα ή μέσω παρόχου). Πλήρες ιστορικό + Request/Response XML στην καρτέλα «Ιστορικό υποβολών».')
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
                            // Click the MARK → full «Έλεγχος ΜΑΡΚ» page (was just
                            // copyable). Plain text when there's no MARK yet.
                            ->color(fn ($record) => filled($record?->mydata_mark) ? 'primary' : null)
                            ->url(fn ($record) => filled($record?->mydata_mark)
                                ? MyDataMarkDetail::getUrl(['mark' => $record->mydata_mark, 'tenant' => Filament::getTenant()])
                                : null)
                            ->placeholder('—'),

                        TextEntry::make('mydata_url')
                            ->label('QR / URL επαλήθευσης')
                            ->url(fn (?string $state) => $state)
                            ->openUrlInNewTab()
                            ->placeholder('—')
                            ->limit(60),

                        // Provider-only (null for direct myDATA filings): which provider
                        // + its authentication seal, read from the latest provider mark.
                        TextEntry::make('provider_key')
                            ->label('Πάροχος')
                            ->state(fn ($record) => $record->mydataMarks()->whereNotNull('provider_key')->latest('id')->value('provider_key'))
                            ->visible(fn ($record) => filled($record->mydataMarks()->whereNotNull('provider_key')->latest('id')->value('provider_key'))),

                        TextEntry::make('authentication_code')
                            ->label('Authentication code')
                            ->state(fn ($record) => $record->mydataMarks()->whereNotNull('authentication_code')->latest('id')->value('authentication_code'))
                            ->visible(fn ($record) => filled($record->mydataMarks()->whereNotNull('authentication_code')->latest('id')->value('authentication_code')))
                            ->copyable()
                            ->limit(40),
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

                        // Deterministic WHMCS origin (whmcs:backfill-invoice-ids
                        // stamps it from the legacy invoiced→legacy_id link).
                        // Hidden when this invoice didn't come from WHMCS.
                        TextEntry::make('whmcs_invoice_id')
                            ->label('WHMCS #')
                            ->prefix('#')
                            ->visible(fn ($record) => filled($record->whmcs_invoice_id)),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('Παρατηρήσεις (εκτύπωσης)')
                    ->description('Εμφανίζονται στο PDF/email του πελάτη.')
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
