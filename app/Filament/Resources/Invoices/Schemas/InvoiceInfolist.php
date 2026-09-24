<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\MyData\Codes;
use App\Support\MyData\IncomeClassResolver;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
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

                        // «Series» (invoiceType.code, e.g. ΤΠΥ) and «ΑΑ» (code, e.g. 6664)
                        // are dropped here: they are exactly the two halves of «Code»
                        // (invcode = ΤΠΥ6664, per GET_INV_CODE = code || invcount), so
                        // showing them again was pure duplication and height. Code keeps
                        // both; Type keeps the human-readable document name.

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
                    ->columns(3)
                    // Tighter header + body padding so the top pair of cards claims
                    // less vertical space (reclaims room below for the phase-2 block).
                    ->compact(),

                Section::make('Customer')
                    ->key('customer')
                    ->description('Snapshot at issue time — these values are legally frozen and do NOT reflect later customer edits.')
                    // «Πλήρη στοιχεία» → modal with the full frozen snapshot (address,
                    // VIES, city/postcode/country, establishment) so the card itself stays
                    // compact (only name + ΑΦΜ + the live-record link inline). Mirrors the
                    // WHMCS-inbox detail modal (Action + Blade modalContent).
                    ->headerActions([
                        Action::make('customer_details')
                            ->label('Πλήρη στοιχεία')
                            ->icon('heroicon-m-identification')
                            ->color('gray')
                            ->modalHeading('Στοιχεία πελάτη (στιγμιότυπο έκδοσης)')
                            // $livewire->getRecord() (the ViewRecord's invoice) rather than
                            // a $record closure arg — guaranteed on a ViewRecord page, no
                            // reliance on schema-action arg injection.
                            ->modalContent(fn ($livewire) => view('filament.invoices.customer-details', [
                                'invoice' => $livewire->getRecord(),
                            ]))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Κλείσιμο')
                            ->modalWidth('2xl'),
                    ])
                    ->schema([
                        TextEntry::make('customer.name')
                            ->label('Live customer record')
                            ->placeholder('—')
                            // Compact link instead of the full live company name: that
                            // name wrapped to 5–7 lines and, next to the «Name on invoice»
                            // snapshot (the legal value), was pure height. Here we only
                            // need the JUMP to the live record, so render a short link
                            // (icon carries the arrow) and keep the Customer card tight.
                            //
                            // Link ONLY for a live (non-trashed) customer. InvoiceResource
                            // eager-loads `customer` with withTrashed() so the invoice can
                            // still reference a deleted customer, so `$record->customer` is
                            // non-null even when soft-deleted — and customers.edit route-
                            // model binding 404s a trashed record. So gate on
                            // `! trashed()`: a trashed/missing customer degrades to «—»,
                            // no dead-end link (the legal name still shows in the snapshot).
                            // ONE guard for both state and url so they can never disagree
                            // (a url without a visible label, or vice-versa).
                            ->getStateUsing(fn ($record) => self::customerIsLinkable($record) ? 'Άνοιγμα εγγραφής' : null)
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            // Pass the Company model (Laravel uses its
                            // route key = slug, per Company::getRouteKeyName).
                            // Passing $record->company_id directly produces
                            // a URL with an integer in the {tenant} slug
                            // segment, which Filament's tenant-binding
                            // middleware then 404s.
                            ->url(fn ($record) => self::customerIsLinkable($record)
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

                        // VIES, Δραστηριότητα, address, city/postcode/country and the
                        // counterpart establishment moved into the «Πλήρη στοιχεία» modal
                        // (headerActions above) to keep this card compact — only name +
                        // ΑΦΜ + the live-record link stay inline.
                    ])
                    ->columns(3)
                    ->compact(),

                // Lines in the page BODY (between the header cards and the totals), so the
                // view reads like the printed παραστατικό — moved here from the bottom
                // «Lines» tab (that relation manager is dropped from getRelations(), which
                // also promotes «Πληρωμές» to the first tab). Read-only, snapshot columns.
                Section::make('Γραμμές')
                    ->columnSpanFull()
                    // Never render an empty «Γραμμές» card (e.g. a fresh draft with no
                    // lines yet) above the totals.
                    ->visible(fn (Invoice $record): bool => $record->lines->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Περιγραφή'),
                                TableColumn::make('Μ.Μ.'),
                                TableColumn::make('Ποσότ.')->alignEnd(),
                                TableColumn::make('Τιμή μον.')->alignEnd(),
                                TableColumn::make('Έκπτ.%')->alignEnd(),
                                TableColumn::make('ΦΠΑ%')->alignEnd(),
                                TableColumn::make('Καθαρή')->alignEnd(),
                                TableColumn::make('Μεικτή')->alignEnd(),
                                TableColumn::make('E3 (ΑΑΔΕ)')->alignEnd(),
                            ])
                            ->schema([
                                // Description + the per-line «Σημείωση» underneath (small,
                                // muted italic), mirroring the PDF's .line-notes — shown only
                                // when the line has a note. `opacity` (not a hardcoded grey)
                                // so it stays legible in the panel's dark theme too; the view
                                // additionally keeps the note's line breaks (nl2br).
                                TextEntry::make('product_descr')
                                    ->html()
                                    ->getStateUsing(function (InvoiceLine $record): string {
                                        $html = e($record->product_descr ?: '—');
                                        if (filled($record->notes)) {
                                            $html .= '<div style="margin-top:.15rem;font-size:.75rem;font-style:italic;opacity:.65">'
                                                .nl2br(e($record->notes)).'</div>';
                                        }

                                        return $html;
                                    }),
                                TextEntry::make('metric_unit')->placeholder('—'),
                                TextEntry::make('qty')->numeric(decimalPlaces: 3)->alignEnd(),
                                TextEntry::make('price_per_item')->money('EUR')->alignEnd(),
                                TextEntry::make('discount')->numeric(decimalPlaces: 4)->placeholder('—')->alignEnd(),
                                TextEntry::make('vat_percent')->suffix('%')->alignEnd(),
                                TextEntry::make('net_price')->money('EUR')->alignEnd(),
                                TextEntry::make('gross_price')->money('EUR')->alignEnd(),
                                // The E3 income class this line FILES at myDATA (same resolver
                                // as the filing path), «—» when the pair doesn't actually file.
                                TextEntry::make('income_class')
                                    ->alignEnd()
                                    ->getStateUsing(function (InvoiceLine $record, $livewire): string {
                                        [$class, $cat] = self::lineIncomeClass($livewire->getRecord(), $record);

                                        return (filled($class) && filled($cat)) ? $class : '—';
                                    })
                                    ->tooltip(function (InvoiceLine $record, $livewire): string {
                                        [$class, $cat] = self::lineIncomeClass($livewire->getRecord(), $record);
                                        if (! filled($class) || ! filled($cat)) {
                                            return 'Χωρίς ταξινόμηση εσόδων (π.χ. δελτίο/εσωτερικό — δεν φέρει έσοδο).';
                                        }
                                        $typeLabel = Codes::e3TypeLabel($class);
                                        $catLabel = Codes::e3CategoryLabel($cat);

                                        return $class.($typeLabel ? ' — '.$typeLabel : '')
                                            .' · '.$cat.($catLabel ? ' — '.$catLabel : '');
                                    }),
                            ]),
                    ]),

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
                        || $record->deliveryNotes()->exists()
                        || $record->converted_from_invoice_id !== null
                        || $record->conversions()->exists())
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

                        // «Μετατροπή σε φορολογικό»: the fiscal side → its informal source…
                        TextEntry::make('converted_from')
                            ->label('Από άτυπο')
                            ->state(fn ($record) => $record->convertedFrom?->invcode)
                            ->url(fn ($record) => $record->convertedFrom
                                ? InvoiceResource::getUrl('view', ['record' => $record->convertedFrom, 'tenant' => $record->company])
                                : null)
                            ->color('primary')
                            ->weight('bold')
                            ->helperText('Βγήκε με «Μετατροπή σε φορολογικό» από αυτό το άτυπο.')
                            ->visible(fn ($record) => $record->converted_from_invoice_id !== null),

                        // …and the informal side → the fiscal document(s) made from it.
                        TextEntry::make('converted_to')
                            ->label('Μετατράπηκε σε')
                            ->state(fn ($record) => $record->conversions->map(fn ($c) => $c->invcode.($c->local_status === 'cancelled' ? ' (ακυρωμένο)' : ''))->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->url(fn ($record) => ($live = $record->liveConversion())
                                ? InvoiceResource::getUrl('view', ['record' => $live, 'tenant' => $record->company])
                                : null)
                            ->color('primary')
                            ->helperText('Το άτυπο μένει ως ίχνος και δεν μετράει πουθενά.')
                            ->visible(fn ($record) => $record->conversions()->exists()),

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
                    ->columns(2)
                    // Full-width panel: a related-documents list reads better across the
                    // page than squeezed into a half column — and keeps the grid clean
                    // next to the now full-width «myDATA / Πάροχος» below (no orphan half-row).
                    ->columnSpanFull(),

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
                    ->columns(4)
                    // Full width so the provider fields (MARK, QR/URL, licence, UID,
                    // signature — long unbreakable strings) stop cramming into a
                    // half-page column and get room to breathe.
                    ->columnSpanFull(),

                // «Παρατηρήσεις» + «Logistics» share the bottom row (both short), which
                // frees the full page width above for «myDATA / Πάροχος» to spread out.
                // Παρατηρήσεις is listed first so it takes the left cell, Logistics the right.
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
            ]);
    }

    /**
     * Whether the «Live customer record» link should render: a customer that is
     * present AND not soft-deleted. InvoiceResource eager-loads `customer` with
     * withTrashed(), so `$record->customer` is non-null for a deleted customer —
     * and customers.edit route-model binding 404s a trashed record. Single source
     * for both the link label (getStateUsing) and its url so they can't diverge.
     */
    private static function customerIsLinkable(mixed $record): bool
    {
        return $record->customer !== null && ! $record->customer->trashed();
    }

    /**
     * The (E3 class, §8.6 category) a line files, via the SAME IncomeClassResolver
     * the filing path uses (so the column reads exactly what gets sent).
     *
     * Memoised in WeakMaps keyed by the invoice / line OBJECTS — not a process-global
     * array keyed by id: a WeakMap is freed with the request's models (no cross-request
     * staleness or unbounded growth under Octane), the base pair (a DB query for a
     * credit note) is computed once per invoice, and each line resolves once (shared by
     * the cell value and its tooltip). The nested product.productCategory the resolver
     * reads is eager-loaded in one go so N lines don't fire N+1 queries.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function lineIncomeClass(Invoice $invoice, InvoiceLine $line): array
    {
        /** @var \WeakMap<InvoiceLine, array{0: ?string, 1: ?string}> $lineCache */
        static $lineCache = null;
        /** @var \WeakMap<Invoice, array{0: ?string, 1: ?string, 2: ?string}> $baseCache */
        static $baseCache = null;
        $lineCache ??= new \WeakMap;
        $baseCache ??= new \WeakMap;

        if (isset($lineCache[$line])) {
            return $lineCache[$line];
        }

        if (! isset($baseCache[$invoice])) {
            // Everything the resolver reads, loaded once: product.productCategory per
            // line (the N+1 the old relation manager guarded against) + the invoice's
            // own invoiceType/company (once-per-invoice, for baseFor + business type).
            $invoice->loadMissing(['lines.product.productCategory', 'invoiceType', 'company']);
            [$class, $cat] = app(IncomeClassResolver::class)->baseFor($invoice);
            $baseCache[$invoice] = [$class, $cat, $invoice->company?->business_activity_type];
        }
        [$baseClass, $baseCat, $businessType] = $baseCache[$invoice];

        return $lineCache[$line] = app(IncomeClassResolver::class)->forLine($line, $baseClass, $baseCat, $businessType);
    }
}
