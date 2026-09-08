<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
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

                        // The two dates, kept distinct: issued_at = «Ημερομηνία έκδοσης»
                        // (the public/legal date, stamped to today at «Αποστολή»),
                        // created_at = «Ημερομηνία δημιουργίας» (internal — πότε φτιάχτηκε
                        // το πρόχειρο). They differ when a draft lingers before issue.
                        TextEntry::make('issued_at')
                            ->label('Ημερομηνία έκδοσης')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Ημερομηνία δημιουργίας')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),

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

                        // Show the branch that ACTUALLY files (filedCounterpartBranch) —
                        // 0/hidden for retail (11.x, no counterpart) AND for a foreign
                        // party (branch forced to 0), so a legal view never shows an
                        // establishment number that AADE never received.
                        TextEntry::make('counterpart_branch')
                            ->label('Εγκατάσταση πελάτη (myDATA)')
                            ->visible(fn ($record) => $record->filedCounterpartBranch() > 0),
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
                        || $record->creditNotes()->exists()
                        || $record->deliveryNotes()->exists())
                    ->schema([
                        // Prominent badge for a fully-reversed original (credited_total
                        // reached gross). PROV-019: only claim a LEGAL cancellation
                        // when it actually happened (isLegallyReversed) — a still-draft
                        // credit reduces the local balance but leaves the turnover
                        // standing at AADE, so it reads as «μειώθηκε με πρόχειρο
                        // πιστωτικό — δεν υποβλήθηκε», not «ακυρώθηκε».
                        TextEntry::make('reversal_status')
                            ->label('Κατάσταση παραστατικού')
                            ->state(fn ($record) => $record->isLegallyReversed()
                                ? 'Ακυρώθηκε με πιστωτικό'
                                : 'Μειώθηκε με πρόχειρο πιστωτικό — δεν υποβλήθηκε στην ΑΑΔΕ')
                            ->badge()
                            ->color(fn ($record) => $record->isLegallyReversed() ? 'danger' : 'warning')
                            ->columnSpanFull()
                            ->visible(fn ($record) => $record->isFullyCredited()),

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

                        // The delivery side of the end-to-end link: δελτία αποστολής
                        // that dispatch this sale (delivery_notes.invoice_id → this).
                        TextEntry::make('delivery_notes')
                            ->label('Δελτία αποστολής')
                            ->state(fn ($record) => $record->deliveryNotes->pluck('invcode')->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->url(fn ($record) => $record->deliveryNotes->count() === 1
                                ? DeliveryNoteResource::getUrl('view', [
                                    'record' => $record->deliveryNotes->first(),
                                    'tenant' => $record->company,
                                ])
                                : null)
                            ->color('primary')
                            ->helperText('Η διακίνηση των ειδών αυτού του παραστατικού.')
                            ->visible(fn ($record) => $record->deliveryNotes->isNotEmpty()),
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
                            // For a provider document this URL is the durable pointer to
                            // the provider's OFFICIAL copy — with the MARK, an operator
                            // reaches the authoritative ΥΠΑΗΕΣ document even if our PDF is
                            // lost. (For direct myDATA it is the AADE QR verification URL.)
                            ->hint(fn ($record) => $record->providerEvidence() !== null
                                ? 'Επίσημο έγγραφο παρόχου'
                                : null)
                            ->extraAttributes(['class' => 'break-all'])
                            ->limit(60),

                        // Provider evidence (null for direct myDATA / cancelled / no
                        // licence). Read off the SAME memoised resolver the PDF uses
                        // (Invoice::providerEvidence) — identical gates, frozen-snapshot
                        // identity, one query — so the screen matches the printed PDF.
                        TextEntry::make('provider_name')
                            ->label('Πάροχος')
                            ->state(fn ($record) => $record->providerEvidence()['commercial_name'] ?? null)
                            ->visible(fn ($record) => $record->providerEvidence() !== null),

                        // PROV-009 ρετούς: these carry long, unbreakable strings (a
                        // licence code, 40-hex UID/signature) that overflowed the box
                        // in the narrow 4-column grid. `break-all` wraps them inside
                        // their cell (class defined in panel.css — no-build gotcha).
                        TextEntry::make('provider_licence')
                            ->label('Αριθμός Αδειοδότησης')
                            ->state(fn ($record) => $record->providerEvidence()['licence_no'] ?? null)
                            ->visible(fn ($record) => $record->providerEvidence() !== null)
                            ->extraAttributes(['class' => 'break-all'])
                            ->copyable(),

                        TextEntry::make('provider_uid')
                            ->label('Αναγνωριστικό (UID)')
                            ->state(fn ($record) => $record->providerEvidence()['uid'] ?? null)
                            ->visible(fn ($record) => filled($record->providerEvidence()['uid'] ?? null))
                            ->extraAttributes(['class' => 'break-all'])
                            ->copyable(),

                        TextEntry::make('authentication_code')
                            ->label('Υπογραφή')
                            ->state(fn ($record) => $record->providerEvidence()['auth_code'] ?? null)
                            ->visible(fn ($record) => filled($record->providerEvidence()['auth_code'] ?? null))
                            ->extraAttributes(['class' => 'break-all'])
                            ->copyable(),
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
