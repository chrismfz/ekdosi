<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Filament\Support\AadeFormFill;
use App\Filament\Support\Tags\TagControls;
use App\Filament\Support\ViesFormFill;
use App\Models\Customer;
use App\Models\PaymentMethod;
use Filament\Actions\Action as FormAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Identity')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(191)
                                    ->columnSpan(2),

                                TextInput::make('type')
                                    ->label('Type')
                                    ->helperText('Free text — e.g. "Company" / "Individual" / etc.')
                                    ->maxLength(60),

                                TextInput::make('occupation')
                                    ->maxLength(120),

                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Inactive customers stay in the books for invoice history but are hidden from the default list.'),

                                Toggle::make('needs_immediate_invoice')
                                    ->label('Άμεση τιμολόγηση')
                                    ->helperText('Όταν είναι ON, η προγραμματισμένη έκδοση εκδίδει + υποβάλλει στη myDATA αμέσως μόλις πληρωθεί, αντί να μπει στην εβδομαδιαία παρτίδα.'),
                            ])
                            ->columns(2),

                        Tab::make('Tax & legal')
                            ->schema([
                                TextInput::make('afm')
                                    ->label('AFM / VAT number')
                                    ->maxLength(20)
                                    // Two AADE actions — both Greek-tenant only (RgWsPublic2
                                    // looks up Greek AFMs). "Άντληση" fills only EMPTY fields
                                    // (operator's typed value wins); "Διόρθωση" OVERWRITES from
                                    // AADE (the registry is the source of truth — for when the
                                    // customer typed something wrong).
                                    ->suffixActions([
                                        FormAction::make('fetch_customer_from_aade')
                                            ->label('Άντληση από ΑΑΔΕ')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                            ->action(fn (callable $get, callable $set) => self::applyAadeToCustomer($get, $set, overwrite: false)),
                                        FormAction::make('correct_customer_from_aade')
                                            ->label('Διόρθωση από ΑΑΔΕ')
                                            ->icon('heroicon-o-arrow-path')
                                            ->color('warning')
                                            ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                            ->requiresConfirmation()
                                            ->modalHeading('Διόρθωση στοιχείων από ΑΑΔΕ')
                                            ->modalDescription('Αντικαθιστά επωνυμία/ΔΟΥ/διεύθυνση/δραστηριότητα με τα επίσημα στοιχεία του μητρώου ΑΑΔΕ (πηγή αλήθειας). Ό,τι έχει γράψει ο πελάτης λάθος θα διορθωθεί.')
                                            ->action(fn (callable $get, callable $set) => self::applyAadeToCustomer($get, $set, overwrite: true)),
                                    ]),

                                TextInput::make('vat_vies')
                                    ->label('VIES VAT (EU intra-community)')
                                    ->maxLength(30)
                                    ->helperText('Ενδοκοινοτικό ΦΠΑ άλλης χώρας ΕΕ (π.χ. ATU18522105). Επαληθεύεται μέσω VIES — για ελληνικά ΑΦΜ χρησιμοποιήστε το «Άντληση από ΑΑΔΕ» πιο πάνω.')
                                    // EU VIES validation (the non-GR twin of the GSIS actions).
                                    // "Επαλήθευση" only reports valid/invalid; "Άντληση"
                                    // also fills name/address WHERE the member state
                                    // publishes them (AT yes, DE withholds). Uses the vat_vies
                                    // value, falling back to the afm field, with the customer
                                    // country as the prefix hint.
                                    ->suffixActions([
                                        FormAction::make('verify_vies')
                                            ->label('Επαλήθευση VIES')
                                            ->icon('heroicon-o-shield-check')
                                            ->action(fn (callable $get) => ViesFormFill::check(
                                                $get('vat_vies') ?: $get('afm'),
                                                $get('country'),
                                            )),
                                        FormAction::make('fetch_vies')
                                            ->label('Άντληση από VIES')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->action(fn (callable $get, callable $set) => self::applyViesToCustomer($get, $set)),
                                    ]),

                                TextInput::make('tax_office')
                                    ->label('Tax office (ΔΟΥ)')
                                    ->maxLength(60),

                                TextInput::make('kad_primary')
                                    ->label('Primary KAD (Δραστηριότητα)')
                                    ->maxLength(20)
                                    ->helperText('Auto-fills from AADE Fetch. Numeric activity code; the human-readable text lives in Occupation on the Identity tab.'),

                                TextInput::make('withhold_tax')
                                    ->label('Withholding tax category')
                                    ->numeric()
                                    ->helperText('Numeric category code (legacy column).'),
                            ])
                            ->columns(2),

                        Tab::make('Address & contact')
                            ->schema([
                                TextInput::make('address1')
                                    ->label('Address line 1')
                                    ->maxLength(60)
                                    ->columnSpan(2),

                                TextInput::make('address2')
                                    ->label('Address line 2')
                                    ->maxLength(60)
                                    ->columnSpan(2),

                                TextInput::make('city')->maxLength(60),
                                TextInput::make('postcode')->maxLength(10),
                                TextInput::make('country')->maxLength(60),

                                TextInput::make('phone1')
                                    ->label('Phone')
                                    ->tel()
                                    ->maxLength(30),
                                TextInput::make('phone2')
                                    ->label('Phone (alt)')
                                    ->tel()
                                    ->maxLength(30),
                                TextInput::make('fax')
                                    ->tel()
                                    ->maxLength(30),

                                TextInput::make('email')
                                    ->email()
                                    ->maxLength(120),
                                TextInput::make('secondary_email')
                                    ->label('Email (alt)')
                                    ->email()
                                    ->maxLength(120),

                                // G6: per-customer auto-email opt-out. On by
                                // default; turn off for a customer who doesn't
                                // want automatic invoice mails. The manual
                                // "Email PDF to customer" action ignores this.
                                Toggle::make('auto_email_invoices')
                                    ->label('Αυτόματη αποστολή τιμολογίων με email')
                                    ->default(true)
                                    ->helperText('Όταν είναι ενεργό, το παραστατικό αποστέλλεται αυτόματα στον πελάτη κατά την έκδοση/αποδοχή ΑΑΔΕ (εφόσον το ενεργοποιεί και η εταιρεία). Η χειροκίνητη αποστολή δεν επηρεάζεται.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Commercial')
                            ->schema([
                                TextInput::make('discount')
                                    ->label('Default discount %')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(0)
                                    ->suffix('%'),

                                Select::make('payment_method_id')
                                    ->label('Default payment method')
                                    ->options(fn () => PaymentMethod::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('description')
                                        ->pluck('description', 'id'))
                                    ->searchable()
                                    ->preload(),

                                Select::make('referred_by_customer_id')
                                    ->label('Referred by')
                                    ->searchable()
                                    // Server-side search — no row-count ceiling.
                                    ->getSearchResultsUsing(function (string $search, ?Customer $record) {
                                        return Customer::query()
                                            ->where('company_id', Filament::getTenant()?->getKey())
                                            ->when($record?->id, fn ($q, $id) => $q->whereKeyNot($id))
                                            ->where('name', 'like', "%{$search}%")
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    // Resolve the currently-saved id back to a label on edit.
                                    ->getOptionLabelUsing(fn ($value) => Customer::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->whereKey($value)
                                        ->value('name'))
                                    ->helperText('Optional. Pick another customer if this one was referred. Non-customer sources (Google, trade show…) — tag support comes later.'),

                                TextInput::make('whmcs_client_id')
                                    ->label('WHMCS client ID')
                                    ->numeric()
                                    ->helperText('Set by the WHMCS bridge when it lands. Editable manually for now.'),

                                // «Υπόλοιπο πελάτη» στο PDF — override της εταιρικής
                                // προεπιλογής. null = κληρονομεί την εταιρεία.
                                Select::make('show_balance_on_pdf')
                                    ->label('Υπόλοιπο πελάτη στο PDF')
                                    ->options([
                                        1 => 'Ναι — να τυπώνεται',
                                        0 => 'Όχι — να μην τυπώνεται',
                                    ])
                                    ->placeholder('Προεπιλογή εταιρείας')
                                    ->helperText('Block «Νέο υπόλοιπο» στα τιμολόγια επί πιστώσει. Κενό = ακολουθεί τη ρύθμιση της εταιρείας.'),

                                TagControls::field()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        // Only meaningful when the tenant submits via PEPPOL
                        // (Estonian companies right now; future EU expansion).
                        Tab::make('PEPPOL')
                            ->visible(fn () => Filament::getTenant()?->einvoice_provider === 'ee-peppol')
                            ->schema([
                                TextInput::make('peppol_endpoint')
                                    ->label('PEPPOL endpoint identifier')
                                    ->maxLength(255)
                                    ->helperText('Estonian companies: their Äriregistri kood formatted as the PEPPOL identifier (e.g. 0007:12345678). Used by the future PEPPOL submitter.'),
                            ]),

                        // The old free-text «Σχόλια» (customers.details) tab was
                        // dropped in favour of the richer «Σημειώσεις (εσωτερικές)»
                        // relation manager (dated, multi-entry, with author). The
                        // `details` column itself was migrated into `notes`
                        // (source='backup') and dropped; imports now sync a backup
                        // note via App\Services\Etl\BackupNoteSync.
                    ]),
            ]);
    }

    /**
     * Look up the form's ΑΦΜ in the GSIS registry and apply the result to the
     * customer fields. $overwrite=false fills only empty fields (import);
     * $overwrite=true replaces them (AADE is the source of truth — correct a
     * wrong/changed entry). Shared by both AADE buttons; the empty/overwrite
     * rule lives in AadeFormFill::assign so customer + supplier can't drift.
     */
    private static function applyAadeToCustomer(callable $get, callable $set, bool $overwrite): void
    {
        $result = AadeFormFill::lookup($get('afm'));
        if (! $result) {
            return;
        }

        AadeFormFill::assign($get, $set, 'name', $result->name, $overwrite);
        AadeFormFill::assign($get, $set, 'tax_office', $result->doy, $overwrite);
        AadeFormFill::assign($get, $set, 'address1', $result->address, $overwrite);
        AadeFormFill::assign($get, $set, 'city', $result->city, $overwrite);
        AadeFormFill::assign($get, $set, 'postcode', $result->postcode, $overwrite);
        AadeFormFill::assign($get, $set, 'country', 'GR', $overwrite);
        $primary = $result->primaryActivity();
        if ($primary) {
            AadeFormFill::assign($get, $set, 'kad_primary', $primary['code'] ?? null, $overwrite);
            // occupation = human-readable activity, printed on invoices as
            // "Δραστηριότητα: ...".
            AadeFormFill::assign($get, $set, 'occupation', $primary['description'] ?? null, $overwrite);
        }

        // Surface AADE status — a suspended/deactivated AFM would fail myDATA
        // on first invoice; the operator should see it now.
        $body = $result->doy.($primary ? ' · '.($primary['description'] ?? '') : '');
        $title = ($overwrite ? 'Διορθώθηκε από ΑΑΔΕ: ' : 'Loaded from AADE: ').$result->name;
        $notification = Notification::make()->title($title);
        if ($result->active) {
            $notification->body($body)->success();
        } else {
            $notification
                ->body($body.' · ⚠ Status: '.($result->statusDescr ?: 'unknown — verify with AADE before issuing'))
                ->warning();
        }
        $notification->send();
    }

    /**
     * Validate the form's EU VAT (vat_vies, falling back to afm) against VIES
     * and fill name/address where the member state publishes them. Fill-empty
     * only (the operator's typed values win); the validity outcome is surfaced
     * by ViesFormFill::check as a notification. For Greek AFMs use the GSIS
     * actions above (richer data) — VIES withholds Greek identity fields.
     */
    private static function applyViesToCustomer(callable $get, callable $set): void
    {
        $result = ViesFormFill::check($get('vat_vies') ?: $get('afm'), $get('country'));
        if (! $result || ! $result->valid) {
            return;
        }

        // Normalise the stored VIES value to the canonical prefixed id.
        ViesFormFill::assign($get, $set, 'vat_vies', $result->fullVatId(), overwrite: true);
        ViesFormFill::assign($get, $set, 'country', $result->countryCode === 'EL' ? 'GR' : $result->countryCode, overwrite: false);
        // Also seed afm (only-when-empty): for a FOREIGN B2B customer the afm
        // field carries the foreign VAT number — MyDataSubmitter::buildCounterpart
        // reads afm (not vat_vies) and throws if it's empty. Filling it here
        // closes the "VIES-validated but unfileable" edge. Greek customers keep
        // their GSIS-sourced 9-digit AFM (this only fills when afm is blank).
        ViesFormFill::assign($get, $set, 'afm', $result->fullVatId(), overwrite: false);

        if ($result->hasIdentity()) {
            ViesFormFill::assign($get, $set, 'name', $result->name, overwrite: false);
            // VIES returns address as one multi-line string; drop it into
            // address1 only when empty (we don't try to split city/postcode).
            ViesFormFill::assign($get, $set, 'address1', str_replace("\n", ', ', $result->address), overwrite: false);
        }
    }
}
