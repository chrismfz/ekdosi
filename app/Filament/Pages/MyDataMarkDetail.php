<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\PaymentMethod;
use App\Services\MyData\EnrichInvoiceFromAade;
use App\Services\MyData\Orphans\OrphanImporter;
use App\Services\MyData\Orphans\OrphanLinker;
use App\Services\MyData\Orphans\OrphanMatcher;
use App\Services\MyData\Orphans\OrphanParty;
use App\Services\MyData\SyncInvoiceStateFromAade;
use App\Services\MyData\TransmittedDocReader;
use App\Support\MyData\MarkDetail;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * "MARK detail" — the full picture behind ONE myDATA MARK, reached by
 * clicking a MARK anywhere in the app (myDATA console, submission-history
 * relation manager, latest-invoices widget).
 *
 * Resolves the MARK two ways:
 *   - LOCAL: a local invoice carries this mydata_mark → show the document
 *     we filed (header, lines, totals) + the request/response XML kept in
 *     mydata_marks, with a link straight to the invoice.
 *   - ORPHAN: no local record → fetch the full document from AADE
 *     (RequestTransmittedDocs, line-level) within the date window and show
 *     it, with the local invoices WITHOUT a MARK that could be its twin
 *     (OrphanMatcher: series/ΑΑ, amount, ΑΦΜ, date) → «Σύνδεση» records the
 *     MARK on the chosen one (OrphanLinker); if none fits, «Καταχώριση
 *     τοπικά» imports it as filed (OrphanImporter).
 *
 * mark / from / to ride in the query string (#[Url]) so the page is
 * bookmarkable and the "change window" action just rewrites them. Not in
 * the navigation — link-only.
 *
 * Gated to gr-mydata, non-Off tenants: an orphan lookup triggers a live
 * AADE call with the tenant's credentials.
 */
class MyDataMarkDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Διασυνδέσεις';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Έλεγχος ΜΑΡΚ';

    protected string $view = 'filament.pages.my-data-mark-detail';

    #[Url]
    public ?string $mark = null;

    /** Date window (Y-m-d) used for the orphan AADE lookup. */
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /** Livewire-safe detail array (App\Support\MyData\MarkDetail shape). */
    #[Locked]
    public ?array $doc = null;

    #[Locked]
    public ?int $invoiceId = null;

    #[Locked]
    public bool $isOrphan = false;

    public ?string $error = null;

    /**
     * Result of the last «Άντληση/έλεγχος από ΑΑΔΕ» — the field-by-field
     * comparison (✓/⚠) rendered as a panel under the document. Shape:
     * {stamped_qr, filled[], comparison: [{label, local, aade, match}]}.
     *
     * @var array<string,mixed>|null
     */
    #[Locked]
    public ?array $enrichReport = null;

    /**
     * Live AADE state ('VALID'|'CANCELLED') captured by the last enrich, so the
     * «Συγχρονισμός κατάστασης» action can apply AADE's truth to the invoice.
     * Reset on every load() (must not outlive the document it described).
     */
    #[Locked]
    public ?string $aadeState = null;

    /**
     * AADE's MARK for the cancellation act, captured by the same enrich that set
     * `$aadeState` — the evidence «Συγχρονισμός κατάστασης» persists so the
     * adopted terminal state can say WHICH cancellation caused it (MYD-023).
     */
    #[Locked]
    public ?string $aadeCancelledByMark = null;

    /**
     * Orphan only: the local invoices without a MARK that could be its twin
     * (OrphanMatcher), Livewire-safe rows for the «Πιθανά τοπικά» panel.
     *
     * @var list<array{linkable: bool, blocker: ?string, id: int, invcode: string, date: ?string, customer: ?string, gross: float, score: int, reasons: string, url: string}>
     */
    #[Locked]
    public array $candidates = [];

    /** Orphan only: a local invoice with the same series/ΑΑ but ANOTHER MARK. */
    #[Locked]
    public ?array $sameNumber = null;

    public static function shouldRegisterNavigation(): bool
    {
        // Now a first-class menu page («Έλεγχος ΜΑΡΚ») — gated by canAccess()
        // (live myDATA tenant + View:MyDataMarkDetail). Reached either from the
        // menu (blank → lookup prompt) or by clicking a MARK anywhere (?mark=).
        return true;
    }

    /**
     * Route-level authorization. READ-ONLY drill-down linked from invoice rows;
     * the operator role holds View:MyDataMarkDetail directly (see
     * TenantRoleProvisioner::OPERATOR_PERMISSION_MAP), so no piggy-backing on
     * View:Invoice. The live-AADE orphan lookup inside load() is separately
     * gated on View:MyDataConsole (admin-only) + canReadMyData(). Gate::can is
     * 404-storm-safe.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        // Any electronic-filing tenant (direct myDATA OR via a provider) can view a
        // MARK's detail for their own filed invoices — a provider-filed MARK resolves
        // locally (invoices.mydata_mark). The LIVE-AADE orphan lookup inside load()
        // is separately gated on canReadMyData() + the console permission.
        return $tenant instanceof Company
            && $tenant->submitsElectronically()
            && (bool) auth()->user()?->can('View:MyDataMarkDetail');
    }

    public function getTitle(): string
    {
        return $this->mark ? 'ΜΑΡΚ '.$this->mark : 'ΜΑΡΚ';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        // Blank = menu landing → show the lookup prompt (header «Αναζήτηση ΜΑΡΚ»),
        // don't 404. A ?mark= (menu typed it, or a clicked MARK) loads the detail.
        if (blank($this->mark)) {
            return;
        }

        $this->load();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Lookup ANY MARK from the menu landing (or to jump to a different
            // one). Sets ?mark= and reloads — local invoice or live AADE orphan.
            Action::make('lookup_mark')
                ->label('Αναζήτηση ΜΑΡΚ')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->modalHeading('Έλεγχος ΜΑΡΚ')
                ->modalSubmitActionLabel('Έλεγχος')
                ->schema([
                    TextInput::make('mark')
                        ->label('ΜΑΡΚ')
                        ->required()
                        ->default(fn () => $this->mark)
                        ->helperText('Ο κωδικός ΜΑΡΚ από το myDATA (π.χ. 400013829677137).'),
                ])
                ->action(function (array $data): void {
                    $this->mark = trim((string) $data['mark']);
                    $this->load();
                }),

            Action::make('open_invoice')
                ->label('Άνοιγμα παραστατικού')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->visible(fn (): bool => $this->invoiceId !== null)
                ->url(fn (): ?string => $this->invoiceId === null ? null : InvoiceResource::getUrl('view', [
                    'record' => $this->invoiceId,
                    'tenant' => Filament::getTenant(),
                ])),

            // Live pull from AADE by MARK → stamp the QR (+ fill blanks) onto
            // the local invoice and show a field-by-field comparison. The whole
            // point for imported invoices (Epsilon/legacy) that have a MARK but
            // no QR. Gated on the console permission (it's a live, billable AADE
            // call) and only when a local invoice exists to enrich.
            Action::make('enrich_from_aade')
                ->label('Άντληση/έλεγχος από ΑΑΔΕ')
                ->icon('heroicon-o-qr-code')
                ->color('success')
                ->visible(fn (): bool => $this->invoiceId !== null && (bool) auth()->user()?->can('View:MyDataConsole'))
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-qr-code')
                ->modalHeading('Άντληση QR & σύγκριση με ΑΑΔΕ')
                ->modalDescription('Ζωντανή ανάκτηση του παραστατικού από το myDATA βάσει ΜΑΡΚ: συμπληρώνει το QR (και ό,τι λείπει) στο τοπικό παραστατικό και δείχνει τυχόν διαφορές. Δεν αλλάζει ήδη συμπληρωμένες τιμές.')
                ->modalSubmitActionLabel('Άντληση')
                ->action(fn () => $this->enrichFromAade()),

            // Apply AADE's live state to the local invoice (2-way: mirrors a
            // myDATA-portal cancellation back as cancelled; un-cancels a doc
            // that's VALID at AADE but wrongly cancelled locally). Only shown
            // when the last «Άντληση» found a STATE divergence — we sync the
            // freshly-fetched AADE truth, never a stale value.
            Action::make('sync_state_from_aade')
                ->label('Συγχρονισμός κατάστασης από ΑΑΔΕ')
                ->icon('heroicon-o-arrows-right-left')
                ->color('warning')
                ->visible(fn (): bool => $this->canSyncState())
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-arrows-right-left')
                ->modalHeading('Συγχρονισμός κατάστασης από ΑΑΔΕ')
                ->modalDescription(fn (): string => $this->syncStateDescription())
                ->modalSubmitActionLabel('Συγχρονισμός')
                ->action(fn () => $this->syncStateFromAade()),

            Action::make('change_window')
                ->label('Αλλαγή διαστήματος')
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->visible(fn (): bool => $this->isOrphan)
                ->modalHeading('Αναζήτηση αδέσποτου σε άλλο διάστημα')
                ->modalDescription('Το myDATA επιστρέφει παραστατικά ανά διάστημα. Αν το ΜΑΡΚ δεν βρέθηκε, διευρύνετε το διάστημα.')
                ->modalSubmitActionLabel('Αναζήτηση')
                ->schema([
                    DatePicker::make('from')
                        ->label('Από')
                        ->required()
                        ->default($this->from ?? now()->subMonths(13)->format('Y-m-d')),
                    DatePicker::make('to')
                        ->label('Έως')
                        ->required()
                        ->default($this->to ?? now()->format('Y-m-d')),
                ])
                ->action(function (array $data): void {
                    $this->from = $data['from'];
                    $this->to = $data['to'];
                    $this->load();
                }),

            // The income-side mirror of ExpenseImporter: the orphan becomes a
            // local invoice AS FILED (AADE series/ΑΑ, MARK, QR, one line per AADE
            // line) — never re-sent. Refused, with the reason, where it can't be
            // reproduced faithfully (credit notes, withholding, …).
            Action::make('import_local')
                ->label('Καταχώριση τοπικά')
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('primary')
                ->visible(fn (): bool => $this->isOrphan && $this->doc !== null && $this->canResolveOrphans())
                ->disabled(fn (): bool => $this->importBlocker() !== null)
                ->tooltip(fn (): ?string => $this->importBlocker())
                ->modalHeading('Καταχώριση αδέσποτου ως τοπικό παραστατικό')
                ->modalDescription(fn (): string => $this->importDescription())
                ->modalSubmitActionLabel('Καταχώριση')
                ->schema(fn (): array => $this->importSchema())
                ->action(fn (array $data) => $this->importOrphan($data)),
        ];
    }

    /**
     * «Σύνδεση» on a suggested local invoice: this orphan IS that invoice — record
     * the MARK on it as a filing would (no resend, no customer email).
     */
    public function linkAction(): Action
    {
        return Action::make('link')
            ->label('Σύνδεση')
            ->icon('heroicon-o-link')
            ->authorize(fn (): bool => $this->canResolveOrphans())
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-link')
            ->modalHeading('Σύνδεση με τοπικό παραστατικό')
            ->modalDescription(fn (array $arguments): string => $this->linkDescription((int) ($arguments['invoice'] ?? 0)))
            ->modalSubmitActionLabel('Σύνδεση')
            ->action(function (array $arguments): void {
                $tenant = Filament::getTenant();
                $invoice = $this->candidateInvoice((int) ($arguments['invoice'] ?? 0));
                if ($invoice === null || $this->doc === null) {
                    Notification::make()->title('Δεν βρέθηκε το παραστατικό')->danger()->send();

                    return;
                }
                try {
                    app(OrphanLinker::class)->link($tenant, $invoice, $this->doc, auth()->id());
                } catch (RuntimeException $e) {
                    Notification::make()->title('Δεν έγινε η σύνδεση')->body($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Συνδέθηκε — το '.$invoice->invcode.' έχει πλέον το ΜΑΡΚ '.$this->mark)->success()->send();
                $this->load();   // now resolves locally
            });
    }

    /** Resolving an orphan writes a legal record: invoices + the live console right. */
    public function canResolveOrphans(): bool
    {
        $user = auth()->user();

        return (bool) $user?->can('View:MyDataConsole') && (bool) $user?->can('Create:Invoice');
    }

    /** One of THIS orphan's suggested candidates (never an arbitrary id). */
    private function candidateInvoice(int $id): ?Invoice
    {
        if (! in_array($id, array_column($this->candidates, 'id'), true)) {
            return null;
        }

        return Invoice::query()->where('company_id', Filament::getTenant()?->getKey())->whereKey($id)->first();
    }

    private function linkDescription(int $invoiceId): HtmlString
    {
        $invoice = $this->candidateInvoice($invoiceId);
        if ($invoice === null || $this->doc === null) {
            return new HtmlString('');
        }
        $why = app(OrphanLinker::class)->blocker(Filament::getTenant(), $invoice, $this->doc);
        $money = fn ($v): string => number_format((float) $v, 2, ',', '.').' €';

        return new HtmlString(nl2br(e(($why !== null ? '⚠ '.$why."\n\n" : '')
            .'myDATA: '.($this->doc['invcode'] ?? '—').' · '.($this->doc['issuedAtHuman'] ?? '—').' · '.$money($this->doc['grossTotal'] ?? 0)
            .' · ΑΦΜ '.($this->doc['counterpartVat'] ?? '—')."\n"
            .'Τοπικό: '.$invoice->invcode.' · '.$invoice->issued_at?->format('d/m/Y').' · '.$money($invoice->gross_total)
            .' · ΑΦΜ '.($invoice->vat_no ?: $invoice->customer?->afm ?: '—')."\n\n"
            .'Το ΜΑΡΚ, το QR και η κατάσταση «έγκυρο στο myDATA» γράφονται στο τοπικό παραστατικό. Δεν στέλνεται τίποτα ξανά στο ΑΑΔΕ ούτε στον πελάτη.')));
    }

    private function importBlocker(): ?string
    {
        return $this->doc === null ? 'Δεν υπάρχει έγγραφο.' : app(OrphanImporter::class)->blocker(Filament::getTenant(), $this->doc);
    }

    private function importDescription(): string
    {
        if ($this->doc === null) {
            return '';
        }
        $lines = count($this->doc['lines'] ?? []);

        return 'Δημιουργείται τοπικό παραστατικό όπως δηλώθηκε στο myDATA: σειρά/ΑΑ '.($this->doc['invcode'] ?? '—')
            .', '.$lines.' '.($lines === 1 ? 'γραμμή' : 'γραμμές').' (καθαρή αξία + ΦΠΑ ανά γραμμή, χωρίς περιγραφές — το myDATA δεν τις κρατά), '
            .'με το ΜΑΡΚ και το QR του, ως '.(($this->doc['state'] ?? 'VALID') === 'CANCELLED' ? 'ακυρωμένο' : 'ήδη διαβιβασμένο')
            .'. Δεν στέλνεται ξανά στο ΑΑΔΕ. Αν μοιάζει με υπάρχον τοπικό, προτίμησε τη «Σύνδεση».';
    }

    /** @return list<Component|Field> */
    private function importSchema(): array
    {
        $tenant = Filament::getTenant();
        $doc = $this->doc ?? [];
        $owner = OrphanImporter::seriesType($tenant, $doc);
        // Filed under one of OUR series → only that series' own type (its counter
        // must move past the ΑΑ); otherwise any non-credit type.
        $types = $owner !== null
            ? collect([$owner])
            : InvoiceType::query()->where('company_id', $tenant->getKey())->where('is_credit', false)->orderBy('code')->get();
        $defaultType = $owner
            ?? $types->first(fn (InvoiceType $t): bool => (string) $t->mydata_type === (string) ($doc['invoiceType'] ?? ''));
        $counterpart = $this->counterpartCustomer($doc);
        $customer = $counterpart ?? $defaultType?->defaultCustomer;

        return [
            Select::make('invoice_type_id')
                ->label('Τύπος παραστατικού')
                ->options($types->mapWithKeys(fn (InvoiceType $t): array => [$t->getKey() => $t->code.' — '.$t->name.($t->mydata_type ? ' ('.$t->mydata_type.')' : '')])->all())
                ->default($defaultType?->getKey())
                ->required()
                ->helperText($owner !== null
                    ? 'Η σειρά «'.$owner->code.'» είναι δική μας — καταχωρίζεται στον τύπο της και ο μετρητής της προχωρά πέρα από τον ΑΑ.'
                    : 'Άλλη σειρά από τις δικές μας — κρατά τη σειρά/ΑΑ του myDATA. Προεπιλογή: ίδιος τύπος myDATA ('.($doc['invoiceType'] ?? '—').').'),
            Select::make('customer_id')
                ->label('Πελάτης')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                    ->where('company_id', $tenant->getKey())
                    ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('afm', 'like', "%{$search}%"))
                    ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value): ?string => Customer::query()->where('company_id', $tenant->getKey())->whereKey($value)->value('name'))
                ->default($customer?->getKey())
                ->required(filled($doc['counterpartVat'] ?? null))
                ->helperText(filled($doc['counterpartVat'] ?? null)
                    ? ($counterpart !== null
                        ? 'Βρέθηκε με το ΑΦΜ '.$doc['counterpartVat'].'.'
                        : 'Δεν υπάρχει πελάτης με ΑΦΜ '.$doc['counterpartVat'].' — δημιούργησέ τον πρώτα από τους Πελάτες.')
                    : 'Λιανική — προαιρετικό.'),
            Select::make('payment_method_id')
                ->label('Τρόπος πληρωμής')
                ->options(PaymentMethod::query()->where('company_id', $tenant->getKey())->orderBy('description')->pluck('description', 'id')->all())
                ->default($customer?->payment_method_id ?? $defaultType?->payment_method_id)
                ->required()
                ->helperText('Ορίζει αν μετρά ως απαίτηση (επί πιστώσει) ή εξοφλημένο (μετρητοίς).'),
        ];
    }

    /** The customer who IS the document's counterpart — by the indexed ΑΦΜ identity (afm_key). */
    private function counterpartCustomer(array $doc): ?Customer
    {
        $keys = OrphanParty::counterpartKeys($doc);

        return $keys === [] ? null : Customer::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->whereIn('afm_key', $keys)
            ->first(['id', 'name', 'afm', 'payment_method_id']);
    }

    private function importOrphan(array $data): void
    {
        $tenant = Filament::getTenant();
        if ($this->doc === null || ! $this->canResolveOrphans()) {
            return;
        }
        $type = InvoiceType::query()->where('company_id', $tenant->getKey())->whereKey($data['invoice_type_id'] ?? 0)->first();
        $pm = PaymentMethod::query()->where('company_id', $tenant->getKey())->whereKey($data['payment_method_id'] ?? 0)->first();
        $customer = filled($data['customer_id'] ?? null)
            ? Customer::query()->where('company_id', $tenant->getKey())->whereKey($data['customer_id'])->first()
            : null;
        if ($type === null || $pm === null) {
            Notification::make()->title('Άκυρη επιλογή')->danger()->send();

            return;
        }

        try {
            $invoice = app(OrphanImporter::class)->import($tenant, $this->doc, $type, $customer, $pm, auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()->title('Δεν έγινε η καταχώριση')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Καταχωρίστηκε ως '.$invoice->invcode)->success()->send();
        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice, 'tenant' => $tenant]));
    }

    /**
     * Resolve the MARK: local invoice first, else an AADE orphan lookup.
     * Re-runnable (the "change window" action calls it again).
     */
    public function load(): void
    {
        $this->error = null;
        $this->doc = null;
        // Drop any prior comparison so it can't outlive the document it
        // described (enrichFromAade re-sets it right after its own load()).
        $this->enrichReport = null;
        $this->aadeState = null;
        $this->aadeCancelledByMark = null;
        $this->candidates = [];
        $this->sameNumber = null;

        $tenant = Filament::getTenant();
        $mark = (string) $this->mark;

        $invoice = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->where('mydata_mark', $mark)
            ->with([
                // Stable, insertion-ordered lines so the displayed numbering
                // matches the filed document (no stored line_number column).
                // product.productCategory feeds the per-line E3 income class (MYD-5).
                'lines' => fn ($q) => $q->orderBy('id')->with('product.productCategory'),
                'invoiceType',
                'company',
            ])
            ->first();

        if ($invoice !== null) {
            $this->isOrphan = false;
            $this->invoiceId = $invoice->id;
            $this->doc = MarkDetail::fromInvoice($invoice);

            $markRow = MyDataMark::query()
                ->where('company_id', $tenant->getKey())
                ->where('invoice_id', $invoice->id)
                ->where('mark', $mark)
                ->latest('id')
                ->first();

            // Single home for the audit XML: the detail array (the orphan
            // path already carries response XML there). Avoids serialising it
            // into a second Livewire prop.
            $this->doc['requestXml'] = $markRow?->request;
            $this->doc['responseXml'] = $markRow?->response;

            return;
        }

        // Orphan — pull the full document from AADE for the window.
        $this->isOrphan = true;
        $this->invoiceId = null;

        // The orphan lookup is a LIVE AADE call with the tenant's credentials —
        // admin territory, same as the consoles. Operators reach this page (for
        // their own filed invoices) via View:MyDataMarkDetail, but must NOT be
        // able to trigger billable/rate-limited live AADE calls for arbitrary
        // hand-typed MARKs. Gate the live branch on the console permission AND on
        // the tenant having a live myDATA endpoint (a provider-only tenant with
        // mydata_mode=off can't make the call — its own marks resolve locally above).
        // A gr-provider tenant with its own read credentials CAN do the live
        // lookup (canReadMyData) — it reads its own AADE picture, it just doesn't
        // submit directly.
        if (! $tenant->canReadMyData() || ! auth()->user()?->can('View:MyDataConsole')) {
            $this->error = 'Το ΜΑΡΚ δεν αντιστοιχεί σε τοπικό παραστατικό. Η ζωντανή αναζήτηση στο myDATA απαιτεί ενεργό myDATA περιβάλλον + δικαιώματα διαχειριστή.';

            return;
        }

        [$from, $to] = $this->resolveWindow();

        try {
            $detail = app(TransmittedDocReader::class, ['tenant' => $tenant])->fetchDetailByMark($mark, $from, $to);

            if ($detail === null) {
                $this->error = 'Το ΜΑΡΚ δεν βρέθηκε στο myDATA για το διάστημα '
                    .$from->format('d/m/Y').' – '.$to->format('d/m/Y')
                    .'. Δοκιμάστε «Αλλαγή διαστήματος».';

                return;
            }

            $this->doc = $detail;
            $this->suggestTwins($tenant, $detail);
        } catch (RuntimeException $e) {
            // Our own guard messages (provider/mode/credentials) — safe Greek.
            $this->error = $e->getMessage();
        } catch (Throwable $e) {
            Log::warning('myDATA mark detail fetch failed', [
                'company_id' => $tenant?->getKey(),
                'mark' => $mark,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->error = 'Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.';
        }
    }

    /** The orphan's possible local twins + a same-number-other-MARK warning. */
    private function suggestTwins(Company $tenant, array $detail): void
    {
        if (($detail['direction'] ?? null) === 'inbound') {
            return;   // an expense — the Έξοδα console's job
        }
        $matcher = app(OrphanMatcher::class);
        $linker = app(OrphanLinker::class);
        foreach ($matcher->candidates($tenant, $detail) as $c) {
            $invoice = $c['invoice'];
            $why = $linker->blocker($tenant, $invoice, $detail);
            $this->candidates[] = [
                'linkable' => $why === null,
                'blocker' => $why,
                'id' => (int) $invoice->getKey(),
                'invcode' => (string) $invoice->invcode,
                'date' => $invoice->issued_at?->format('d/m/Y'),
                'customer' => $invoice->customer?->name ?? $invoice->company_name,
                'gross' => (float) $invoice->gross_total,
                'score' => $c['score'],
                'reasons' => implode(' · ', $c['reasons']),
                'url' => InvoiceResource::getUrl('view', ['record' => $invoice, 'tenant' => $tenant]),
            ];
        }
        $same = $matcher->sameNumberWithOtherMark($tenant, $detail);
        if ($same !== null) {
            $this->sameNumber = [
                'invcode' => (string) $same->invcode,
                'mark' => (string) $same->mydata_mark,
                'url' => InvoiceResource::getUrl('view', ['record' => $same, 'tenant' => $tenant]),
            ];
        }
    }

    /**
     * Live-pull this MARK from AADE, enrich the local invoice (QR + fill-blanks)
     * and surface the field-by-field comparison both as a toast and the on-page
     * panel ($enrichReport). No-op-safe: guards on the console permission and a
     * resolvable local invoice; AADE/credential failures degrade to a Greek
     * notification, never a 500.
     */
    public function enrichFromAade(): void
    {
        $tenant = Filament::getTenant();

        if ($this->invoiceId === null || ! auth()->user()?->can('View:MyDataConsole')) {
            Notification::make()->title('Μη διαθέσιμο')->danger()
                ->body('Η άντληση από το ΑΑΔΕ είναι διαθέσιμη μόνο σε διαχειριστές, για τοπικό παραστατικό.')
                ->send();

            return;
        }

        $invoice = Invoice::query()
            ->where('company_id', $tenant?->getKey())
            ->whereKey($this->invoiceId)
            ->first();

        if ($invoice === null || blank($invoice->mydata_mark)) {
            Notification::make()->title('Δεν βρέθηκε παραστατικό με ΜΑΡΚ')->danger()->send();

            return;
        }

        [$from, $to] = $this->windowForInvoice($invoice);

        try {
            $detail = app(TransmittedDocReader::class, ['tenant' => $tenant])->fetchDetailByMark((string) $this->mark, $from, $to);
        } catch (RuntimeException $e) {
            Notification::make()->title('Αποτυχία')->danger()->body($e->getMessage())->send();

            return;
        } catch (Throwable $e) {
            Log::warning('myDATA enrich fetch failed', [
                'company_id' => $tenant?->getKey(),
                'mark' => $this->mark,
                'exception' => $e::class,
            ]);
            Notification::make()->title('Αποτυχία')->danger()
                ->body('Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.')
                ->send();

            return;
        }

        if ($detail === null) {
            Notification::make()->title('Δεν βρέθηκε στο myDATA')->warning()
                ->body('Το ΜΑΡΚ δεν βρέθηκε για το διάστημα '.$from->format('d/m/Y').' – '.$to->format('d/m/Y')
                    .'. Δοκιμάστε «Αλλαγή διαστήματος».')
                ->send();

            return;
        }

        $report = app(EnrichInvoiceFromAade::class)->enrich($invoice, $detail);
        $aadeState = is_string($detail['state'] ?? null) ? $detail['state'] : null;
        $this->load(); // refresh the local doc (QR now shows); clears stale report
        $this->enrichReport = $report; // set AFTER load(), which nulls it
        $this->aadeState = $aadeState; // ditto — enables «Συγχρονισμός κατάστασης»
        $this->aadeCancelledByMark = is_string($detail['cancelledByMark'] ?? null)
            ? $detail['cancelledByMark']
            : null;

        $diffs = array_values(array_filter($this->enrichReport['comparison'], fn (array $r): bool => ! $r['match']));
        $notification = Notification::make()->title('Σύγκριση με ΑΑΔΕ ολοκληρώθηκε')->persistent();

        $bodyLines = [];
        if ($this->enrichReport['stamped_qr']) {
            $bodyLines[] = '✓ Συμπληρώθηκε το QR.';
        }
        if ($this->enrichReport['qr_skipped_cancelled'] ?? false) {
            $bodyLines[] = '⚠ Το παραστατικό είναι ΑΚΥΡΩΜΕΝΟ στο ΑΑΔΕ — δεν τυπώθηκε QR.';
        }
        if ($this->enrichReport['filled'] !== []) {
            $bodyLines[] = 'Συμπληρώθηκαν: '.implode(', ', $this->enrichReport['filled']).'.';
        }
        if ($diffs === [] && ! ($this->enrichReport['qr_skipped_cancelled'] ?? false)) {
            $bodyLines[] = '✓ Όλα τα πεδία συμφωνούν με το ΑΑΔΕ.';
            $notification->success();
        } elseif ($diffs === []) {
            $notification->warning();
        } else {
            $bodyLines[] = 'Διαφορές ('.count($diffs).'): '
                .implode(' · ', array_map(fn (array $r): string => $r['label'], $diffs)).'.';
            $notification->warning();
        }

        $notification->body(implode(' ', $bodyLines))->send();
    }

    /**
     * The «Κατάσταση» row of the last enrich comparison, IF it diverges
     * (match === false). Null when there's no enrich yet or the states agree.
     *
     * @return array{label:string, local:?string, aade:?string, match:bool}|null
     */
    private function stateDiffRow(): ?array
    {
        foreach ($this->enrichReport['comparison'] ?? [] as $row) {
            if (($row['label'] ?? null) === 'Κατάσταση' && ($row['match'] ?? true) === false) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The «Συγχρονισμός κατάστασης» action is offered only when the last live
     * pull found a STATE divergence (admin-gated, local invoice present).
     */
    public function canSyncState(): bool
    {
        return $this->invoiceId !== null
            && $this->aadeState !== null
            && (bool) auth()->user()?->can('View:MyDataConsole')
            && $this->stateDiffRow() !== null;
    }

    private function syncStateDescription(): string
    {
        $local = $this->stateDiffRow()['local'] ?? '—';

        return "Τοπική κατάσταση «{$local}» → ΑΑΔΕ «{$this->aadeState}». "
            .'Η ΑΑΔΕ είναι η πηγή αλήθειας· η τοπική κατάσταση του παραστατικού θα ενημερωθεί ανάλογα.';
    }

    /**
     * Apply AADE's live state (captured by the last enrich) to the local
     * invoice via SyncInvoiceStateFromAade, then reload. Admin-gated; failures
     * degrade to a Greek notification.
     */
    public function syncStateFromAade(): void
    {
        $tenant = Filament::getTenant();

        if (! $this->canSyncState()) {
            Notification::make()->title('Μη διαθέσιμο')->danger()
                ->body('Ο συγχρονισμός κατάστασης απαιτεί πρόσφατη άντληση από ΑΑΔΕ με διαφορά κατάστασης (διαχειριστής).')
                ->send();

            return;
        }

        $invoice = Invoice::query()
            ->where('company_id', $tenant?->getKey())
            ->whereKey($this->invoiceId)
            ->first();

        if ($invoice === null) {
            Notification::make()->title('Δεν βρέθηκε παραστατικό')->danger()->send();

            return;
        }

        try {
            $result = app(SyncInvoiceStateFromAade::class)
                ->sync($invoice, (string) $this->aadeState, $this->aadeCancelledByMark);
        } catch (Throwable $e) {
            Notification::make()->title('Αποτυχία συγχρονισμού')->danger()->body($e->getMessage())->send();

            return;
        }

        $this->load(); // refresh the local doc + hide the action (divergence resolved)

        if ($result['changed']) {
            Notification::make()->title('Η κατάσταση συγχρονίστηκε με την ΑΑΔΕ')->success()
                ->body("Κατάσταση myDATA: {$result['from']} → {$result['to']}.")->send();
        } else {
            Notification::make()->title('Καμία αλλαγή')->info()
                ->body('Η τοπική κατάσταση ήταν ήδη ίδια με την ΑΑΔΕ.')->send();
        }
    }

    /**
     * Lookup window for enriching a LOCAL invoice. An explicit operator window
     * (from/to) wins; otherwise centre on the invoice's issue date — the ~13
     * month default would miss old imported invoices, which are the whole point
     * of this feature.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function windowForInvoice(Invoice $invoice): array
    {
        if ($this->from || $this->to) {
            return $this->resolveWindow();
        }

        if ($invoice->issued_at !== null) {
            return [
                $invoice->issued_at->copy()->subDays(5)->startOfDay(),
                $invoice->issued_at->copy()->addDays(31)->endOfDay(),
            ];
        }

        return $this->resolveWindow();
    }

    /**
     * Window for the orphan lookup: the from/to query params when present
     * (e.g. carried from the console link), else the last ~13 months — wide
     * enough to catch a bookmarked MARK without a window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(): array
    {
        $from = $this->from
            ? Carbon::parse($this->from)->startOfDay()
            : now()->subMonths(13)->startOfDay();

        $to = $this->to
            ? Carbon::parse($this->to)->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }
}
