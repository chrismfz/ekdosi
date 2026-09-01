<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\MyData\Codes;
use App\Support\MyData\DeliveryCodes;
use App\Support\MyData\DeliveryGuidance;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Δελτίο Αποστολής (Παραστατικό Διακίνησης) issuance form. Used by both
 * CreateDeliveryNote and EditDeliveryNote (Edit gated to drafts only).
 *
 * The form wires the operator-guidance helpers so the law lives in ONE place
 * (App\Support\MyData\DeliveryGuidance / DeliveryCodes) and the UI just reads it:
 *
 *  - A NON-blocking exemption notice at the top (DeliveryGuidance::EXEMPTIONS_LEAD
 *    + EXEMPTIONS + INTRO) — the operator can always proceed (voluntary issuance
 *    is legal).
 *  - A «Τι θέλω να κάνω;» scenario picker (DeliveryGuidance::scenarioOptions())
 *    that, on change, fills move_purpose (and, for the «Λοιπές» scenario, the
 *    free-text title). It is a pure UI helper — NOT a stored column
 *    (dehydrated(false)).
 *  - Every field's helperText comes from DeliveryGuidance::fieldHelp().
 *
 * Like the invoice form, the ΑΑ (code + invcode) is NOT a form field — it is
 * allocated server-side under a row lock in CreateDeliveryNote::
 * handleRecordCreation. The myDATA cache + lifecycle marks are NOT fillable.
 */
class DeliveryNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ─── Ενημερωτικό: πότε ΔΕΝ χρειάζεται δελτίο (μη-δεσμευτικό) ───
            Section::make('Πριν ξεκινήσεις')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->schema([
                    Placeholder::make('exemptions_notice')
                        ->hiddenLabel()
                        ->content(fn () => new HtmlString(
                            '<div class="text-sm space-y-2">'
                            .'<p class="font-medium">'.e(DeliveryGuidance::EXEMPTIONS_LEAD).'</p>'
                            .'<p class="font-medium">Δεν χρειάζεται δελτίο όταν:</p>'
                            .'<ul class="list-disc ps-5 space-y-1">'
                            .implode('', array_map(
                                fn (string $x) => '<li>'.e($x).'</li>',
                                DeliveryGuidance::EXEMPTIONS,
                            ))
                            .'</ul>'
                            .'<p class="pt-2 text-gray-500">'.e(DeliveryGuidance::INTRO).'</p>'
                            .'</div>'
                        )),
                ]),

            // ─── Σκοπός διακίνησης ───
            Section::make('Σκοπός διακίνησης')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    // UI-only helper — NOT persisted. Picks the right §8.14 code.
                    Select::make('scenario')
                        ->label('Τι θέλω να κάνω;')
                        ->options(DeliveryGuidance::scenarioOptions())
                        ->helperText(DeliveryGuidance::fieldHelp('scenario'))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (! $state) {
                                return;
                            }
                            $scenario = DeliveryGuidance::scenario($state);
                            if (! $scenario) {
                                return;
                            }
                            $set('move_purpose', $scenario['move_purpose']);
                            if (! empty($scenario['other_title'])) {
                                $set('other_move_purpose_title', $scenario['other_title']);
                            }
                        })
                        ->columnSpanFull(),

                    Select::make('move_purpose')
                        ->label('Κωδικός σκοπού (ΑΑΔΕ §8.14)')
                        ->options(DeliveryCodes::movePurposeOptions())
                        ->required()
                        ->searchable()
                        ->live()
                        ->helperText(DeliveryGuidance::fieldHelp('move_purpose')),

                    TextInput::make('other_move_purpose_title')
                        ->label('Τίτλος σκοπού (Λοιπές Διακινήσεις)')
                        ->maxLength(120)
                        ->visible(fn (Get $get) => (int) $get('move_purpose') === 19)
                        ->required(fn (Get $get) => (int) $get('move_purpose') === 19)
                        ->helperText(DeliveryGuidance::fieldHelp('other_move_purpose_title')),

                    Select::make('delivery_type_id')
                        ->label('Τύπος δελτίου')
                        // New notes pick only supported types (9.3); an existing draft
                        // already saved as a now-hidden 9.1/9.2 still shows its stored
                        // type (flagged) so an edit can't silently drop it (MYD-012).
                        ->options(function ($record) {
                            $opts = static::deliveryTypeOptions();
                            $current = $record?->delivery_type_id;
                            if ($current && ! isset($opts[$current]) && ($t = InvoiceType::find($current))) {
                                $opts[$t->id] = $t->code.' — '.$t->name.' (μη υποστηριζόμενο)';
                            }

                            return $opts;
                        })
                        ->default(fn () => static::defaultDeliveryTypeId())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('Σειρά + ΑΑ counter + myDATA τύπος (9.x). Προεπιλογή: Δελτίο Αποστολής (ΔΑΠ / 9.3).')
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),

                    DateTimePicker::make('issued_at')
                        ->label('Ημερομηνία έκδοσης')
                        ->required()
                        ->default(now())
                        ->seconds(false)
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),
                ]),

            // ─── Παραλήπτης (πελάτης Ή προμηθευτής Ή χειροκίνητα) ───
            Section::make('Παραλήπτης')
                ->columnSpanFull()
                ->columns(2)
                ->description('Άφησέ το κενό για ενδοδιακίνηση (μετακίνηση μέσα στην επιχείρησή σου) — η ΑΑΔΕ συμπληρώνει ΑΦΜ 000000000.')
                ->schema([
                    // Any-party picker over BOTH customers and suppliers. Mirrors
                    // the WHMCS create-draft recipient picker (server-side search,
                    // no preload) but unions the two tables. The selected option's
                    // KEY is prefixed (c:ID / s:ID) so we can resolve which table it
                    // came from and snapshot recipient_afm + recipient_name (+
                    // customer_id only for a customer). A supplier recipient leaves
                    // customer_id null (the FK is to customers only).
                    Select::make('recipient_pick')
                        ->label('Αναζήτηση παραλήπτη (πελάτης ή προμηθευτής)')
                        ->dehydrated(false)
                        ->searchable()
                        ->preload(false)
                        ->live()
                        ->getSearchResultsUsing(fn (string $search) => static::searchRecipients($search))
                        ->getOptionLabelUsing(fn ($value) => static::recipientLabel($value))
                        ->afterStateUpdated(function ($state, callable $set): void {
                            if (! $state) {
                                return;
                            }
                            $party = static::resolveRecipient($state);
                            if (! $party) {
                                return;
                            }
                            $set('customer_id', $party['customer_id']);
                            $set('recipient_afm', $party['afm']);
                            $set('recipient_name', $party['name']);
                            // Default the delivery address from the chosen party.
                            if ($party['street'] !== null) {
                                $set('delivery_street', $party['street']);
                            }
                            if ($party['postcode'] !== null) {
                                $set('delivery_postcode', $party['postcode']);
                            }
                            if ($party['city'] !== null) {
                                $set('delivery_city', $party['city']);
                            }
                        })
                        ->helperText(DeliveryGuidance::fieldHelp('recipient'))
                        ->columnSpanFull(),

                    // The persisted snapshot fields. Editable so the operator can
                    // type a party not in either table (manual fallback) — the
                    // submitter accepts a raw ΑΦΜ + name.
                    TextInput::make('recipient_name')
                        ->label('Επωνυμία παραλήπτη')
                        ->maxLength(120),

                    TextInput::make('recipient_afm')
                        ->label('ΑΦΜ παραλήπτη')
                        ->maxLength(20)
                        ->helperText('Άφησέ το κενό για ενδοδιακίνηση (συμπληρώνεται 000000000).'),

                    // Kept so a customer recipient links the Καρτέλα; hidden field.
                    Hidden::make('customer_id'),

                    // Optional link to the sale this δελτίο dispatches — lets the
                    // stock engine count the sale ONCE (whichever-first dedup).
                    Select::make('invoice_id')
                        ->label('Σχετικό τιμολόγιο (προαιρετικό)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Invoice::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('invcode', 'like', "%{$search}%")
                            ->limit(20)
                            ->pluck('invcode', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Invoice::find($value)?->invcode)
                        ->helperText('Αν το δελτίο αφορά πώληση που τιμολογείς ξεχωριστά, σύνδεσέ το — έτσι το απόθεμα δεν μετριέται δύο φορές.'),
                ]),

            // ─── Διευθύνσεις φόρτωσης / παράδοσης ───
            Section::make('Διευθύνσεις')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Section::make('Φόρτωση (από πού φεύγει)')
                        ->columns(2)
                        ->schema([
                            TextInput::make('loading_street')
                                ->label('Οδός')
                                ->required()
                                ->default(fn () => Filament::getTenant()?->address)
                                ->helperText(DeliveryGuidance::fieldHelp('loading_address')),
                            TextInput::make('loading_number')->label('Αριθμός')->maxLength(20),
                            TextInput::make('loading_postcode')
                                ->label('Τ.Κ.')
                                ->required()
                                ->maxLength(10)
                                ->default(fn () => Filament::getTenant()?->postcode),
                            TextInput::make('loading_city')
                                ->label('Πόλη')
                                ->required()
                                ->maxLength(60)
                                ->default(fn () => Filament::getTenant()?->city),
                            TextInput::make('start_shipping_branch')
                                ->label('Υποκατάστημα εκκίνησης')
                                ->numeric()
                                ->default(0),
                        ]),

                    Section::make('Παράδοση (πού πάει)')
                        ->columns(2)
                        ->schema([
                            TextInput::make('delivery_street')
                                ->label('Οδός')
                                ->required()
                                ->helperText(DeliveryGuidance::fieldHelp('delivery_address')),
                            TextInput::make('delivery_number')->label('Αριθμός')->maxLength(20),
                            TextInput::make('delivery_postcode')
                                ->label('Τ.Κ.')
                                ->required()
                                ->maxLength(10),
                            TextInput::make('delivery_city')
                                ->label('Πόλη')
                                ->required()
                                ->maxLength(60),
                            TextInput::make('complete_shipping_branch')
                                ->label('Υποκατάστημα παράδοσης')
                                ->numeric()
                                ->default(0),
                        ]),
                ]),

            // ─── Μεταφορά ───
            Section::make('Μεταφορά')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('transport_type')
                        ->label('Τρόπος μεταφοράς')
                        ->options(DeliveryCodes::transportTypeOptions())
                        ->required()
                        ->searchable()
                        ->helperText(DeliveryGuidance::fieldHelp('transport_type')),

                    TextInput::make('vehicle_number')
                        ->label('Πινακίδα οχήματος')
                        ->required()
                        ->maxLength(40)
                        ->helperText(DeliveryGuidance::fieldHelp('vehicle_number')),

                    TextInput::make('carrier_afm')
                        ->label('ΑΦΜ μεταφορέα')
                        ->maxLength(20)
                        ->default(fn () => Filament::getTenant()?->afm)
                        ->helperText(DeliveryGuidance::fieldHelp('carrier_afm')),

                    DateTimePicker::make('dispatch_at')
                        ->label('Έναρξη διακίνησης (ημ/ώρα)')
                        ->required()
                        ->default(now())
                        ->seconds(false)
                        ->helperText(DeliveryGuidance::fieldHelp('dispatch_at')),

                    Toggle::make('third_party_collection')
                        ->label('Παραλαβή από τρίτο (μεταφορέα)')
                        ->helperText(DeliveryGuidance::fieldHelp('third_party_collection')),
                ]),

            // ─── Γραμμές ───
            Section::make('Γραμμές (είδη που μετακινούνται)')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->relationship('lines')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->columns(4)
                        ->schema([
                            Select::make('product_id')
                                ->label('Προϊόν (προαιρετικό)')
                                ->searchable()
                                ->preload(false)
                                ->getSearchResultsUsing(fn (string $search) => Product::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->where('description_short', 'like', "%{$search}%")
                                    ->orderBy('description_short')
                                    ->limit(50)
                                    ->pluck('description_short', 'id')
                                    ->toArray())
                                ->getOptionLabelUsing(fn ($value) => optional(Product::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->find($value))->description_short)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if (! $state) {
                                        return;
                                    }
                                    $product = Product::find($state);
                                    if ($product) {
                                        $set('product_descr', $product->description_short);
                                    }
                                }),

                            TextInput::make('product_descr')
                                ->label('Περιγραφή')
                                ->placeholder('Από προϊόν ή ελεύθερο κείμενο'),

                            TextInput::make('qty')
                                ->label('Ποσότητα')
                                ->required()
                                ->numeric()
                                ->step('0.001')
                                ->default(1)
                                ->minValue(0.001)
                                ->helperText(DeliveryGuidance::fieldHelp('qty')),

                            Select::make('measurement_unit')
                                ->label('Μ.Μ.')
                                // New lines pick 1–6 (unit 7 needs unmodelled
                                // otherMeasurementUnit fields — MYD-016); a legacy
                                // line already on 7 still shows it, so an unrelated
                                // edit never silently drops the stored value.
                                ->options(fn (Get $get) => (int) $get('measurement_unit') === 7
                                    ? Codes::QUANTITY_TYPES
                                    : Codes::selectableQuantityTypes())
                                ->default(1)
                                ->selectablePlaceholder(false)
                                ->helperText(DeliveryGuidance::fieldHelp('measurement_unit')),

                            Select::make('move_purpose_line')
                                ->label('Σκοπός γραμμής (προαιρετικό)')
                                ->options(DeliveryCodes::movePurposeOptions())
                                ->searchable(),
                        ])
                        ->addActionLabel('+ Προσθήκη γραμμής')
                        ->reorderable(false)
                        ->disabled(fn ($record) => $record && $record->mydata_state !== null),
                ]),

            Section::make('Σημειώσεις')
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    Textarea::make('notes')->rows(3)->hiddenLabel()->columnSpanFull(),
                ]),
        ]);
    }

    /* ===================== Delivery-type helpers ===================== */

    /**
     * The tenant's 9.x delivery types (mydata_type starts with '9').
     *
     * @return array<int, string>
     */
    public static function deliveryTypeOptions(): array
    {
        return InvoiceType::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('mydata_type', 'like', '9%')
            ->orderBy('code')
            ->get()
            // 9.1/9.2 are not yet correctly fileable (correlation/aggregation not
            // built) — offer only the sandbox-validated 9.3 (MYD-012). Same rule the
            // submitter guard enforces, so picker and guard cannot drift.
            ->reject(fn (InvoiceType $t) => Codes::isUnsupportedDeliveryType($t->mydata_type))
            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name])
            ->toArray();
    }

    /** Default delivery type id: ΔΑΠ (9.3) if present, else the first 9.x type. */
    public static function defaultDeliveryTypeId(): ?int
    {
        $companyId = Filament::getTenant()?->getKey();

        $dap = InvoiceType::query()
            ->where('company_id', $companyId)
            ->where('code', 'ΔΑΠ')
            ->value('id');
        if ($dap) {
            return (int) $dap;
        }

        $first = InvoiceType::query()
            ->where('company_id', $companyId)
            ->where('mydata_type', 'like', '9%')
            // Never default to a hidden/unsupported 9.1/9.2 (MYD-012) — that would
            // pre-select a type absent from the picker and create an unfileable draft.
            ->whereNotIn('mydata_type', Codes::UNSUPPORTED_DELIVERY_TYPES)
            ->orderBy('code')
            ->value('id');

        return $first ? (int) $first : null;
    }

    /* ===================== Any-party recipient helpers ===================== */

    /**
     * Search BOTH customers and suppliers by name / ΑΦΜ, returning a single
     * option list keyed by a prefixed id (c:ID / s:ID) so resolveRecipient()
     * can tell which table a pick came from.
     *
     * @return array<string, string>
     */
    public static function searchRecipients(string $search): array
    {
        $companyId = Filament::getTenant()?->getKey();
        $out = [];

        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('afm', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(25)
            ->get();
        foreach ($customers as $c) {
            $out['c:'.$c->id] = 'Πελάτης: '.$c->name.($c->afm ? ' ('.$c->afm.')' : '');
        }

        $suppliers = Supplier::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('afm', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(25)
            ->get();
        foreach ($suppliers as $s) {
            $out['s:'.$s->id] = 'Προμηθευτής: '.$s->name.($s->afm ? ' ('.$s->afm.')' : '');
        }

        return $out;
    }

    public static function recipientLabel(?string $value): ?string
    {
        $party = $value ? static::resolveRecipient($value) : null;

        return $party ? $party['name'] : null;
    }

    /**
     * Resolve a prefixed recipient pick (c:ID / s:ID) into a normalised party
     * snapshot. customer_id is set ONLY for a customer (the FK is customers-only).
     *
     * @return array{customer_id: int|null, afm: string|null, name: string|null, street: string|null, postcode: string|null, city: string|null}|null
     */
    public static function resolveRecipient(string $value): ?array
    {
        $companyId = Filament::getTenant()?->getKey();
        [$kind, $id] = array_pad(explode(':', $value, 2), 2, null);
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        if ($kind === 'c') {
            $c = Customer::query()->where('company_id', $companyId)->find($id);
            if (! $c) {
                return null;
            }

            return [
                'customer_id' => $c->id,
                'afm' => $c->afm,
                'name' => $c->name,
                'street' => $c->address1,
                'postcode' => $c->postcode,
                'city' => $c->city,
            ];
        }

        if ($kind === 's') {
            $s = Supplier::query()->where('company_id', $companyId)->find($id);
            if (! $s) {
                return null;
            }

            return [
                'customer_id' => null,
                'afm' => $s->afm,
                'name' => $s->name,
                'street' => $s->address1,
                'postcode' => $s->postcode,
                'city' => $s->city,
            ];
        }

        return null;
    }
}
