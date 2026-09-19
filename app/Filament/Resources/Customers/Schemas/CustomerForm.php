<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\DTOs\AadeRegistryRecord;
use App\Enums\LeadActivityType;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\AadeFormFill;
use App\Filament\Support\Tags\TagControls;
use App\Filament\Support\ViesFormFill;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Support\Afm;
use App\Support\IsoCountry;
use Filament\Actions\Action as FormAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

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
                                    ->helperText('Όταν είναι ON, η προγραμματισμένη έκδοση εκδίδει + υποβάλλει στη myDATA αμέσως μόλις πληρωθεί, αντί να μπει στην εβδομαδιαία παρτίδα.')
                                    // For a WHMCS-linked customer whose tenant maps the «γκρινιάρης»
                                    // field, WHMCS is the source of truth: WhmcsInvoiceIngestor mirrors
                                    // the flag on every ingest, so an edit here would be overwritten.
                                    // Lock it read-only and point the operator to WHMCS. (Disabled ⇒
                                    // not dehydrated ⇒ the mirrored value is never touched by a save.)
                                    ->disabled(fn (?Customer $record): bool => self::immediateInvoiceGovernedByWhmcs($record))
                                    ->hintIcon(fn (?Customer $record): ?string => self::immediateInvoiceGovernedByWhmcs($record) ? 'heroicon-m-lock-closed' : null)
                                    ->hint(fn (?Customer $record): ?string => self::immediateInvoiceGovernedByWhmcs($record) ? 'Ελέγχεται από το WHMCS («γκρινιάρης»)' : null),

                                Toggle::make('needs_invoice_before_payment')
                                    ->label('Τιμολόγιο πριν την πληρωμή')
                                    ->helperText('Για πελάτες (δημόσιο/δήμοι/Α.Ε.) που θέλουν το παραστατικό ΠΡΙΝ πληρώσουν. Τα ΑΠΛΗΡΩΤΑ WHMCS invoices τους έρχονται στο «Εισερχόμενα» για χειροκίνητη έκδοση επί πιστώσει — ΠΟΤΕ αυτόματα.'),
                            ])
                            ->columns(2),

                        Tab::make('Tax & legal')
                            ->schema([
                                TextInput::make('afm')
                                    ->label('AFM / VAT number')
                                    ->maxLength(20)
                                    // One customer per ΑΦΜ identity per tenant (any formatting,
                                    // EL/GR prefix or not; soft-deleted included). A friendly
                                    // message instead of the UNIQUE(company_id, afm_key) error.
                                    ->rule(fn (?Customer $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                        if (Afm::uniqueKey($value) === null) {
                                            return; // blank / placeholder = no identity to collide on
                                        }

                                        // A PARKED row (the legacy υποκατάστημα twin the ETL
                                        // imported keyless — see customers.afm_key_parked)
                                        // deliberately shares its ΑΦΜ with the holder, so
                                        // handing it back its OWN ΑΦΜ is not a duplicate;
                                        // refusing it would make the row uneditable. Any OTHER
                                        // value is checked normally.
                                        if ($record?->afm_key_parked && Afm::uniqueKey($record->afm) === Afm::uniqueKey($value)) {
                                            return;
                                        }

                                        $other = Customer::afmOwnerQuery((int) Filament::getTenant()?->getKey(), $value)
                                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                            ->first();

                                        if ($other !== null) {
                                            $fail($other->trashed()
                                                ? "Υπάρχει ΔΙΑΓΡΑΜΜΕΝΟΣ πελάτης με αυτό το ΑΦΜ («{$other->name}») — επανέφερέ τον αντί να φτιάξεις νέο."
                                                : "Υπάρχει ήδη πελάτης με αυτό το ΑΦΜ: «{$other->name}».");
                                        }
                                    })
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
                                            ->action(fn (callable $get, callable $set, $livewire) => self::fetchAadeIntoCustomer($get, $set, $livewire)),
                                        FormAction::make('correct_customer_from_aade')
                                            ->label('Διόρθωση από ΑΑΔΕ')
                                            ->icon('heroicon-o-arrow-path')
                                            ->color('warning')
                                            ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                            ->requiresConfirmation()
                                            ->modalHeading('Διόρθωση στοιχείων από ΑΑΔΕ')
                                            ->modalDescription('Αντικαθιστά ΟΛΑ τα στοιχεία (επωνυμία/ΔΟΥ/διεύθυνση/δραστηριότητα) με τα επίσημα στοιχεία του μητρώου ΑΑΔΕ (πηγή αλήθειας), χωρίς ερώτηση ανά πεδίο. Για επιλεκτική ενημέρωση χρησιμοποίησε το «Άντληση από ΑΑΔΕ».')
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
                                                $get('country_code'),
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
                                // MYD-011: ISO picker bound to the clean `country_code`
                                // cache (never the raw free-text `country`, so a legacy
                                // «ΙΤΑΛΙΑ» can't fail the Select's implicit in: rule). The
                                // model's saving() hook mirrors the code into `country`.
                                Select::make('country_code')
                                    ->label('Χώρα')
                                    ->options(IsoCountry::options())
                                    ->searchable()
                                    ->native(false),

                                // i18n Slice 0: per-customer communication-language
                                // preference. Κενό = αυτόματο από τη χώρα. Resolved by
                                // App\Support\CustomerLanguage.
                                Select::make('language')
                                    ->label('Γλώσσα επικοινωνίας')
                                    ->options([
                                        'el' => 'Ελληνικά',
                                        'en' => 'Αγγλικά',
                                        'both' => 'Δίγλωσσο (GR/EN)',
                                    ])
                                    ->placeholder('Αυτόματο (από χώρα πελάτη)')
                                    ->helperText('Κενό = αυτόματο από τη χώρα (GR→Ελληνικά, ξένος→δίγλωσσο). Εφαρμόζεται στα email του πελάτη· στα PDF μέσω της «Γλώσσας PDF» του παραστατικού.'),

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
                                // «Αποστολή PDF στον πελάτη» action ignores this.
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

                        // Leads L1: «από πού ήρθε» — only for customers born from a lead.
                        Tab::make('Προέλευση')
                            ->icon('heroicon-o-funnel')
                            // `$record->originLead` (the relation, not ->exists()) loads once
                            // and is cached on the model for the Placeholder below.
                            ->visible(fn (?Customer $record): bool => $record?->originLead !== null)
                            ->schema([
                                Placeholder::make('origin_lead')
                                    ->hiddenLabel()
                                    ->content(fn (?Customer $record): HtmlString => self::originLeadSummary($record?->originLead)),
                            ]),

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
     * The AADE registry record mapped onto the customer form fields it can
     * populate. Single source shared by BOTH AADE buttons («Άντληση» diff-fill
     * and «Διόρθωση» overwrite) so the field list can't drift. country_code is
     * handled outside this map (see the NOTE below).
     *
     * @return array<string, string> field ⇒ AADE value (may be '')
     */
    private static function aadeCustomerValues(AadeRegistryRecord $record): array
    {
        $primary = $record->primaryActivity();

        // NOTE: country_code is deliberately NOT here. GSIS is GR-only, so it's
        // a fixed 'GR' handled separately (fill-empty on «Άντληση», overwrite on
        // «Διόρθωση») — never a per-field conflict, which would otherwise show a
        // confusing raw-ISO row («CY» → «GR») in the picker.
        return [
            'name' => (string) $record->name,
            'tax_office' => (string) $record->doy,
            'address1' => (string) $record->address,
            'city' => (string) $record->city,
            'postcode' => (string) $record->postcode,
            'kad_primary' => (string) ($primary['code'] ?? ''),
            // occupation = human-readable activity, printed on invoices as
            // "Δραστηριότητα: ...".
            'occupation' => (string) ($primary['description'] ?? ''),
        ];
    }

    /** @return array<string, string>  field ⇒ operator-facing Greek label */
    private static function aadeCustomerFieldLabels(): array
    {
        return [
            'name' => 'Επωνυμία',
            'tax_office' => 'ΔΟΥ',
            'address1' => 'Διεύθυνση',
            'city' => 'Πόλη',
            'postcode' => 'Τ.Κ.',
            'kad_primary' => 'ΚΑΔ',
            'occupation' => 'Δραστηριότητα',
        ];
    }

    /**
     * «Άντληση από ΑΑΔΕ»: fill every EMPTY field from the registry immediately
     * (nothing typed is lost), and — if any ALREADY-filled field disagrees with
     * AADE — chain into the per-field conflict picker so the operator decides
     * which typed values to replace. This fixes the old "fill-only-empty"
     * behaviour that silently skipped a changed address without a word.
     *
     * The conflict picker lives on the page (ResolvesAadeFormConflicts) so it
     * can write back into the page's form state after mount.
     */
    private static function fetchAadeIntoCustomer(callable $get, callable $set, $livewire): void
    {
        $result = AadeFormFill::lookup($get('afm'));
        if (! $result) {
            return;   // failure already surfaced as a notification
        }

        $values = self::aadeCustomerValues($result);
        $split = AadeFormFill::splitFillsAndConflicts($get, $values);

        // Apply the safe empty-fills right away.
        foreach ($split['fills'] as $field => $value) {
            $set($field, $value);
        }

        // GSIS is GR-only → set the country only when empty (never clobber /
        // never a conflict; the ISO picker mirrors into the free-text `country`).
        AadeFormFill::assign($get, $set, 'country_code', 'GR', overwrite: false);

        self::notifyAadeFetch($result, count($split['fills']), count($split['conflicts']));

        // Hand any conflicts to the picker modal (labelled for display), then
        // mount it. No conflicts → we're done in one click.
        if ($split['conflicts'] !== []) {
            $labels = self::aadeCustomerFieldLabels();
            $conflicts = [];
            foreach ($split['conflicts'] as $field => $pair) {
                $conflicts[$field] = [
                    'label' => $labels[$field] ?? $field,
                    'current' => $pair['current'],
                    'aade' => $pair['aade'],
                ];
            }

            $livewire->replaceMountedAction('resolveAadeConflicts', arguments: ['conflicts' => $conflicts]);
        }
    }

    /**
     * «Διόρθωση από ΑΑΔΕ»: the registry is the source of truth — overwrite ALL
     * mapped fields (a wrong/changed entry), no per-field prompt. The
     * empty/overwrite rule lives in AadeFormFill::assign so customer + supplier
     * can't drift.
     */
    private static function applyAadeToCustomer(callable $get, callable $set, bool $overwrite): void
    {
        $result = AadeFormFill::lookup($get('afm'));
        if (! $result) {
            return;
        }

        foreach (self::aadeCustomerValues($result) as $field => $value) {
            AadeFormFill::assign($get, $set, $field, $value, $overwrite);
        }
        // GSIS is GR-only (see aadeCustomerValues). Overwrite the country here.
        AadeFormFill::assign($get, $set, 'country_code', 'GR', $overwrite);

        self::sendAadeStatusNotification($result, 'Διορθώθηκε από ΑΑΔΕ: '.$result->name);
    }

    /**
     * Post-«Άντληση» toast: what was auto-filled, whether differences are
     * waiting in the picker, and the AADE activity/status.
     */
    private static function notifyAadeFetch(AadeRegistryRecord $result, int $filled, int $conflicts): void
    {
        $summary = [];
        if ($filled > 0) {
            $summary[] = "{$filled} κενά συμπληρώθηκαν";
        }
        if ($conflicts > 0) {
            $summary[] = "{$conflicts} διαφέρουν — δες το παράθυρο";
        }
        if ($filled === 0 && $conflicts === 0) {
            $summary[] = 'όλα ήδη συγχρονισμένα';
        }

        self::sendAadeStatusNotification(
            $result,
            'Άντληση από ΑΑΔΕ: '.$result->name.' ('.implode(' · ', $summary).')',
        );
    }

    /**
     * Shared AADE toast: title + activity body, success when the ΑΦΜ is active,
     * a warning carrying the raw status text when it isn't (a suspended/
     * deactivated ΑΦΜ would fail myDATA on the first invoice — surface it now).
     */
    private static function sendAadeStatusNotification(AadeRegistryRecord $result, string $title): void
    {
        $primary = $result->primaryActivity();
        $body = $result->doy.($primary ? ' · '.($primary['description'] ?? '') : '');

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
        $result = ViesFormFill::check($get('vat_vies') ?: $get('afm'), $get('country_code'));
        if (! $result || ! $result->valid) {
            return;
        }

        // Normalise the stored VIES value to the canonical prefixed id.
        ViesFormFill::assign($get, $set, 'vat_vies', $result->fullVatId(), overwrite: true);
        // The form's country control is the ISO picker (country_code); the saving()
        // hook mirrors it into the free-text `country`.
        $iso = $result->countryCode === 'EL' ? 'GR' : $result->countryCode;
        ViesFormFill::assign($get, $set, 'country_code', $iso, overwrite: false);
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

    /**
     * «Ήρθε από lead #N (πηγή …) · κυνηγός … · πρώτη επαφή … · μετατροπή … (X ημέρες,
     * N τηλέφωνα, M emails)» + link to the lead's full timeline. Evaluated once
     * per render (Placeholder content) — no memo needed.
     */
    private static function originLeadSummary(?Lead $lead): HtmlString
    {
        if ($lead === null) {
            return new HtmlString('');
        }

        $counts = $lead->timeline()->getQuery()->reorder()
            ->selectRaw('type, COUNT(*) AS n')
            ->groupBy('type')
            ->pluck('n', 'type');

        // Carbon 3 diffInDays() is a float — whole days only.
        // «Πρώτη επαφή» = the earliest REAL contact row (call/email/meeting/
        // quote), falling back to the lead's creation when none was logged.
        $firstContactRaw = $lead->timeline()->getQuery()->reorder()
            ->whereIn('type', LeadActivityType::contactValues())
            ->min('happened_at');
        $firstContact = $firstContactRaw ? Carbon::parse($firstContactRaw) : $lead->created_at;

        $days = $lead->converted_at && $firstContact
            ? (int) floor($firstContact->diffInDays($lead->converted_at))
            : null;

        $bits = array_filter([
            'Πηγή: '.($lead->source?->getLabel() ?? '—'),
            $lead->referredBy ? 'σύσταση από '.$lead->referredBy->name : null,
            'Χειριστής: '.($lead->assignedTo?->name ?? '—'),
            'Πρώτη επαφή: '.($firstContact?->format('d/m/Y') ?? '—'),
            'Μετατροπή: '.($lead->converted_at?->format('d/m/Y') ?? '—').($days !== null ? " ({$days} ημέρες)" : ''),
            'Επαφές: '.(int) ($counts['call'] ?? 0).' τηλέφωνα · '.(int) ($counts['email'] ?? 0).' emails · '.(int) ($counts['meeting'] ?? 0).' ραντεβού',
        ]);

        $url = LeadResource::getUrl('edit', ['record' => $lead]);

        return new HtmlString(
            '<div style="font-size:.875rem;line-height:1.6">'
            .'<div style="font-weight:600">Ήρθε από lead <a href="'.e($url).'" style="text-decoration:underline">#'.$lead->id.' — '.e($lead->name).'</a></div>'
            .'<ul style="margin:.25rem 0 0;padding-left:1.1rem">'
            .implode('', array_map(fn (string $b): string => '<li>'.e($b).'</li>', $bits))
            .'</ul>'
            .'<div style="margin-top:.4rem;opacity:.75">Το πλήρες χρονολόγιο (τι ειπώθηκε, πότε) είναι στο lead.</div>'
            .'</div>'
        );
    }

    /**
     * Is «Άμεση τιμολόγηση» owned by WHMCS for this customer? True only when the
     * customer is WHMCS-linked (whmcs_client_id) AND the tenant maps the «γκρινιάρης»
     * field — the exact condition under which WhmcsInvoiceIngestor mirrors the flag on
     * every ingest, so an ekdosi-side edit would be overwritten. Non-WHMCS customers,
     * and tenants that don't map the field, keep the toggle editable here.
     */
    private static function immediateInvoiceGovernedByWhmcs(?Customer $record): bool
    {
        if ($record === null || blank($record->whmcs_client_id)) {
            return false;
        }

        return Filament::getTenant()?->whmcsCustomFieldId('griniaris') !== null;
    }
}
