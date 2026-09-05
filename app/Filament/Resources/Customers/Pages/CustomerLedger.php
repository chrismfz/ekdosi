<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\PaymentStatus;
use App\Exceptions\Aade\AadeRegistryException;
use App\Filament\Concerns\HandlesAadeRegistryExceptions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\BankAccountField;
use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\AadeRegistryLookup;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\CustomerLedger\CustomerStatementCsv;
use App\Services\CustomerLedger\CustomerStatementPdfRenderer;
use App\Services\CustomerLedger\CustomerTopProducts;
use App\Services\Payments\PaymentAllocator;
use App\Services\TenantMailerFactory;
use App\Services\Whmcs\CustomerWhmcsLedger;
use App\Services\Whmcs\CustomerWhmcsLedgerResult;
use App\Support\InvoiceScope;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

/**
 * Καρτέλα Πελάτη: full customer financial dashboard.
 *
 * Layout (top → bottom):
 *   1. Header card — identity + key facts (Blade section).
 *   2. KPI stats + aging + balance trend — Filament widgets embedded via
 *
 *      @livewire (always styled by Filament's compiled CSS; no custom
 *      theme build needed). Data is the filter-independent stats block,
 *      computed ONCE on mount.
 *   3. Καρτέλα κινήσεων — a real Filament table (records()-backed) with
 *      pagination, search, sortable date, native year/type/paid filters,
 *      and click-through links to each invoice.
 *   4. WHMCS comparison (collapsible).
 *
 * Header actions are grouped to keep the bar compact: «Νέο Παραστατικό»
 * (standalone), «Εισπράξεις / Πληρωμές» (receipt / payment / allocations /
 * credit / refund), «Εξαγωγή / Αποστολή» (PDF / CSV / email statement), and
 * «Περισσότερα» (AADE crosscheck / edit / back).
 *
 * Read-only ledger. The running balance shown in the table is always
 * computed from the FULL history (CustomerLedgerBuilder), so a year/type
 * filter never resets it to zero — operators expect carry-over.
 */
class CustomerLedger extends Page implements HasTable
{
    use HandlesAadeRegistryExceptions;
    use InteractsWithTable;

    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.customers.ledger';

    /**
     * Filament/Livewire binds `$record` to the raw URL value first, then
     * mount() resolves it to a Customer. The union + #[Locked] mirrors
     * Filament's own InteractsWithRecord lifecycle (see git history for
     * the prod-404 this prevents).
     */
    #[Locked]
    public Customer|Model|int|string|null $record = null;

    public bool $showWhmcsPanel = false;

    /**
     * Filter-independent ledger sections (stats / aging / yearly).
     * Computed ONCE on mount and fed to the KPI/aging/chart widgets +
     * the header card. The movements table recomputes its own rows per
     * interaction (cheap, bounded per customer).
     *
     * @var array{stats: array, aging: array, yearly: array}|null
     */
    public ?array $cachedStatsBlock = null;

    /**
     * «Συχνά προϊόντα/υπηρεσίες» — top items this customer buys, computed once
     * on mount from their live sales lines (see CustomerTopProducts).
     *
     * @var array<int, array{key: string, label: string, product_id: ?int, sku: ?string, times: int, qty: float, net: float, unit: ?string, last_at: ?string}>
     */
    public array $topProducts = [];

    /**
     * This customer's UNISSUED drafts (πρόχειρα) — deliberately OUT of the money
     * ledger (a draft is not a movement or a receivable, so it must never touch
     * the running balance), but operators still need to FIND «that draft I made
     * for this customer». This is the findability surface the ledger cannot be.
     *
     * @var array<int, array{id: int, invcode: ?string, type: ?string, issued_at: ?string, gross: float, view_url: string, edit_url: ?string}>
     */
    public array $draftInvoices = [];

    public ?CustomerWhmcsLedgerResult $whmcsLedger = null;

    /**
     * @var array{
     *     diffs: array<string, array{stored: string, aade: string}>,
     *     error: ?string,
     *     is_active: ?bool,
     *     status_descr: ?string,
     *     activities: array<int, array{code: string, description: string, kind: string}>,
     * }|null
     */
    public ?array $aadeCrosscheckMemo = null;

    /**
     * @var array<int, int>
     */
    public array $availableYears = [];

    /**
     * @var array<int, array{id: int, code: string}>
     */
    public array $availableInvoiceTypes = [];

    /**
     * The Καρτέλα is a read-only drill-down of a customer's invoices/payments,
     * so it rides on Customer view rights (Customer ∈ the operator role's set).
     * Using Gate::can keeps it 404-storm-safe: a missing permission resolves to
     * false (not a PermissionDoesNotExist throw), and super_admin bypasses via
     * Gate::before.
     */
    public static function canAccess(array $parameters = []): bool
    {
        return (bool) auth()->user()?->can('View:Customer');
    }

    public function mount(int|string $record): void
    {
        $this->record = Customer::query()->withTrashed()->where('id', (int) $record)->firstOrFail();

        $tenant = Filament::getTenant();

        if ($tenant && (int) $this->record->company_id !== (int) $tenant->getKey()) {
            abort(404);
        }

        abort_unless(auth()->user()?->can('view', $this->record), 403);

        // Eager-load the Καρτέλα's read-only side panels so the blade doesn't
        // lazy-load (and N+1 the note authors / attachment uploaders) per render.
        $this->record->load(['contacts', 'internalNotes.author', 'attachments.uploadedBy']);

        $this->cachedStatsBlock = app(CustomerLedgerBuilder::class)->buildStatsBlock($this->record);
        $this->topProducts = app(CustomerTopProducts::class)->for($this->record);
        $this->draftInvoices = $this->loadDraftInvoices();
        $this->loadDimensionLookups();
    }

    /**
     * The customer's unissued sale drafts, newest first. Uses the SAME
     * `onlyUnissuedDrafts` scope the dashboard's «Πρόχειρα» figure uses, so «drafts»
     * means one thing everywhere: a local draft, new-app (not legacy-imported), not
     * a credit note. Explicit company_id + no ambient scope reliance (this runs in a
     * panel request, but the query is explicit for the same reason the ledger's is).
     *
     * @return array<int, array{id: int, invcode: ?string, type: ?string, issued_at: ?string, gross: float, view_url: string, edit_url: ?string}>
     */
    private function loadDraftInvoices(): array
    {
        $query = Invoice::query()
            ->with('invoiceType')
            ->where('company_id', $this->record->company_id)
            ->where('customer_id', $this->record->getKey())
            // Only GENUINELY unfiled drafts belong under «Πρόχειρα»: this section
            // lists exactly what the edit surfaces treat as an editable draft
            // (onlyUnissuedDrafts gives the local_status='draft' half; mydata_state
            // IS NULL is the other half of EditInvoice::mount's gate). A row that
            // is draft-status but carries a MARK is a filed AADE document, not a
            // draft, and must not be presented as one here. (SoftDeletes' global
            // scope already excludes trashed rows — no explicit whereNull needed.)
            ->whereNull('mydata_state');

        InvoiceScope::onlyUnissuedDrafts($query);

        $drafts = $query
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();

        // InvoicePolicy::update is permission-only (it ignores the invoice), so the
        // check is loop-invariant — resolve it ONCE, through the Gate (not the raw
        // Shield string), using any row as the required instance. Every listed row
        // is now mydata_state===null + local_status='draft', so this permission is
        // the only remaining half of the edit gate.
        $canEdit = $drafts->isNotEmpty()
            && (auth()->user()?->can('update', $drafts->first()) ?? false);

        return $drafts
            ->map(fn (Invoice $invoice): array => [
                'id' => (int) $invoice->getKey(),
                'invcode' => $invoice->invcode,
                'type' => $invoice->invoiceType?->name ?? $invoice->invoiceType?->code,
                'issued_at' => $invoice->issued_at?->toDateString(),
                'gross' => (float) $invoice->gross_total,
                'view_url' => InvoiceResource::getUrl('view', ['record' => $invoice, 'tenant' => $this->record->company]),
                'edit_url' => $canEdit
                    ? InvoiceResource::getUrl('edit', ['record' => $invoice, 'tenant' => $this->record->company])
                    : null,
            ])
            ->all();
    }

    public function getTitle(): string
    {
        return 'Καρτέλα: '.($this->record?->name ?? '(unknown)');
    }

    public function getBreadcrumb(): string
    {
        return 'Καρτέλα';
    }

    /**
     * Does the customer have any movements? Drives the empty-state in
     * the Blade view.
     */
    public function hasActivity(): bool
    {
        return ($this->cachedStatsBlock['stats']['total_invoices_lifetime'] ?? 0) > 0;
    }

    /**
     * Should the balance-trend chart be shown? Only with ≥2 years of
     * history (a single point isn't a trend).
     */
    public function hasBalanceTrend(): bool
    {
        return count($this->cachedStatsBlock['yearly'] ?? []) >= 2;
    }

    public function hasWhmcsLink(): bool
    {
        return $this->record->whmcs_client_id !== null
            && ($this->record->company?->hasWhmcsIntegration() ?? false);
    }

    /**
     * Totals for the period (έτος) currently picked in the movements-table
     * filter — the «σαν το βιβλίο εσόδων-εξόδων» period view. Null when «Όλα τα
     * έτη» is selected (the lifetime/YTD stat cards already cover the whole
     * picture). Reads the already-cached per-year breakdown (computeYearly), so
     * it adds NO query and the figures match the YoY chart exactly. The running
     * balance in the table stays full-history regardless.
     *
     * @return array{year: int, invoice_count: int, net: float, gross: float, paid: float, year_end_balance: ?float}|null
     */
    public function getPeriodSummary(): ?array
    {
        $state = $this->getTableFilterState('year');
        $value = $state['value'] ?? '';

        if ($value === '' || $value === null) {
            return null;
        }

        $year = (int) $value;

        foreach ($this->cachedStatsBlock['yearly'] ?? [] as $row) {
            if ((int) ($row['year'] ?? 0) === $year) {
                return [
                    'year' => $year,
                    'invoice_count' => (int) ($row['invoice_count'] ?? 0),
                    'net' => (float) ($row['net'] ?? 0),
                    'gross' => (float) ($row['gross'] ?? 0),
                    'paid' => (float) ($row['paid'] ?? 0),
                    'year_end_balance' => isset($row['year_end_balance']) ? (float) $row['year_end_balance'] : null,
                ];
            }
        }

        // Defensive: the year dropdown is built from invoice years, so a picked
        // year always has a computeYearly row — but never trust that blindly.
        return [
            'year' => $year,
            'invoice_count' => 0,
            'net' => 0.0,
            'gross' => 0.0,
            'paid' => 0.0,
            'year_end_balance' => null,
        ];
    }

    /* ===================== Movements table ===================== */

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (
                ?array $filters,
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                int|string $page,
                int|string $recordsPerPage,
            ): LengthAwarePaginator => $this->paginateLedgerRows(
                $filters,
                $search,
                $sortColumn,
                $sortDirection,
                $page,
                $recordsPerPage,
            ))
            ->columns([
                TextColumn::make('date')
                    ->label('Ημερομηνία')
                    ->formatStateUsing(fn ($state): string => Carbon::parse($state)->format('d/m/Y'))
                    // Show the entry time under the date so same-day rows (a payment +
                    // a same-day refund) read in the right order at a glance.
                    ->description(fn (array $record): ?string => $record['time'] ?? null)
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono whitespace-nowrap']),
                TextColumn::make('type')
                    ->label('Τύπος')
                    ->badge()
                    ->formatStateUsing(fn ($state, array $record): string => CustomerLedgerBuilder::eventTypeLabel($state, $record['invoice_type_code'] ?? null))
                    ->color(fn ($state): string => match ($state) {
                        'invoice' => 'info',
                        'refund' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('reference')
                    ->label('Αναφορά')
                    ->searchable()
                    // Blue (clickable) for anything the row links to: an invoice, or a
                    // payment/refund (→ its editable record). Grouped receipts have the
                    // «Κατανομή» drill-down instead, so they stay plain.
                    ->color(fn (array $record): ?string => $this->ledgerRowUrl($record) !== null ? 'primary' : null)
                    // «Αναλυτική παρακράτηση»: when the collectible differs from the
                    // document value (withholding/τέλη), show both under the reference.
                    // Display-only — the Χρέωση/Υπόλοιπο stay = payable.
                    ->description(fn (array $record): ?string => CustomerLedgerBuilder::adjustmentDetail(
                        $record['document_gross'] ?? null,
                        (float) ($record['tax_adjustment'] ?? 0),
                        fn ($v): string => $this->fmtMoney($v),
                    )),
                TextColumn::make('debit')
                    ->label('Χρέωση')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => $state > 0 ? $this->fmtMoney($state) : ''),
                TextColumn::make('credit')
                    ->label('Πίστωση')
                    ->alignEnd()
                    ->color('success')
                    ->formatStateUsing(fn ($state): string => $state > 0 ? $this->fmtMoney($state) : ''),
                TextColumn::make('running_balance')
                    ->label('Υπόλοιπο')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state): string => $this->fmtMoney($state)),
                TextColumn::make('mydata_state')
                    ->label('myDATA')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn ($state): string => match ($state) {
                        'VALID' => 'success',
                        'CANCELLED' => 'gray',
                        'INVALID' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('year')
                    ->label('Έτος / περίοδος')
                    ->options(array_combine($this->availableYears, $this->availableYears))
                    ->placeholder('Όλα τα έτη'),
                SelectFilter::make('invoice_type')
                    ->label('Τύπος παραστατικού')
                    ->options(collect($this->availableInvoiceTypes)->pluck('code', 'id')->all()),
                SelectFilter::make('paid')
                    ->label('Κατάσταση')
                    ->options([
                        'paid' => 'Εξοφλημένα',
                        'unpaid' => 'Ανεξόφλητα',
                    ]),
            ])
            // Surface the filters above the table (not hidden behind the funnel
            // icon) so picking a customer's «τρέχον/προηγούμενο έτος» view is one
            // click — the «εύκολος τρόπος» the ledger-book period dropdown gives.
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'sm' => 3])
            ->recordActions([
                // Φ3 — drill-down on a grouped «έμβασμα/είσπραξη» row: a
                // read-only modal listing each allocation (settled invoice →
                // amount + the «Πίστωση/προκαταβολή» remainder), summing to
                // the row's credit. Visible ONLY on receipt-group rows.
                Action::make('allocations')
                    ->label('Κατανομή')
                    ->icon('heroicon-o-list-bullet')
                    ->color('success')
                    ->iconButton()
                    ->visible(fn (array $record): bool => ! empty($record['is_receipt_group']))
                    ->modalHeading(fn (array $record): string => 'Κατανομή είσπραξης — '.($record['reference'] ?? ''))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Κλείσιμο')
                    ->modalContent(fn (array $record) => view('filament.customers.receipt-allocations-modal', [
                        'allocations' => $record['allocations'] ?? [],
                        'total' => (float) ($record['credit'] ?? 0),
                        'fmtMoney' => fn ($v): string => $this->fmtMoney($v),
                    ])),
            ])
            ->recordUrl(fn (array $record): ?string => $this->ledgerRowUrl($record))
            ->defaultSort('date', 'desc')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Δεν βρέθηκαν κινήσεις')
            ->emptyStateIcon('heroicon-o-document-magnifying-glass');
    }

    /**
     * Resolve, filter, search, sort and paginate the chronological
     * movements for the records()-backed table.
     *
     * Filtering (year / invoice type / paid) is delegated to
     * CustomerLedgerBuilder::buildLedgerOnly so the running balance is
     * computed over the FULL history before filtering — never reset by
     * the active filter window. Only search + sort + pagination are
     * applied here on top of the already-correct rows.
     *
     * @param  array<string, mixed>|null  $filters
     */
    private function paginateLedgerRows(
        ?array $filters,
        ?string $search,
        ?string $sortColumn,
        ?string $sortDirection,
        int|string $page,
        int|string $recordsPerPage,
    ): LengthAwarePaginator {
        $builderFilters = [
            'year' => isset($filters['year']['value']) && $filters['year']['value'] !== ''
                ? (int) $filters['year']['value']
                : null,
            'invoice_type_id' => isset($filters['invoice_type']['value']) && $filters['invoice_type']['value'] !== ''
                ? (int) $filters['invoice_type']['value']
                : null,
            'paid_status' => isset($filters['paid']['value']) && in_array($filters['paid']['value'], ['paid', 'unpaid'], true)
                ? $filters['paid']['value']
                : null,
        ];

        $rows = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->record, $builderFilters);

        // Search across reference + type label + myDATA state.
        if (filled($search)) {
            $needle = mb_strtolower(trim($search));
            $rows = array_values(array_filter($rows, function (array $r) use ($needle): bool {
                $typeLabel = $r['type'] === 'invoice' ? ($r['invoice_type_code'] ?? 'τιμολόγιο') : 'πληρωμή';
                $haystack = mb_strtolower(implode(' ', [
                    $r['reference'] ?? '',
                    $typeLabel,
                    $r['mydata_state'] ?? '',
                    $r['date'] ?? '',
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        // buildLedgerOnly already returns newest-first (date + creation-order
        // tiebreak). Flipping to ascending just reverses it — array_reverse keeps
        // the same-day creation-order tiebreak intact (strcmp on the date string
        // would collapse same-day rows into an arbitrary order again).
        if ($sortColumn === 'date' && $sortDirection === 'asc') {
            $rows = array_reverse($rows);
        }

        $total = count($rows);
        $perPage = ($recordsPerPage === 'all' || (int) $recordsPerPage < 1)
            ? max($total, 1)
            : (int) $recordsPerPage;
        $currentPage = max(1, (int) $page);

        $slice = array_slice($rows, ($currentPage - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * Where a ledger row links: an invoice → its view; a payment/refund → its
     * (editable) Payment record, so the operator can inspect/correct it (e.g. a
     * wrong refund amount). A grouped receipt (no single payment_id) uses the
     * «Κατανομή» drill-down modal instead → no direct url.
     *
     * @param  array<string, mixed>  $record
     */
    private function ledgerRowUrl(array $record): ?string
    {
        if (($record['type'] ?? null) === 'invoice' && ! empty($record['invoice_id'])) {
            return InvoiceResource::getUrl('view', ['record' => $record['invoice_id']]);
        }
        if (in_array($record['type'] ?? null, ['payment', 'refund'], true) && ! empty($record['payment_id'])) {
            return PaymentResource::getUrl('edit', ['record' => $record['payment_id']]);
        }

        return null;
    }

    private function fmtMoney(mixed $value): string
    {
        return Money::eur($value);
    }

    private ?float $availableCreditCache = null;

    /** @var array<int, string>|null */
    private ?array $openInvoiceOptionsCache = null;

    /**
     * This customer's available on-account credit (unallocated money). Memoised
     * per request: the apply-credit action reads it from visible() + the modal
     * description + the amount default, all in one render (a private prop is not
     * Livewire-hydrated, so it resets fresh each round-trip — no staleness).
     */
    private function availableCredit(): float
    {
        return $this->availableCreditCache ??= app(PaymentAllocator::class)->availableCredit($this->record);
    }

    /**
     * The customer's OPEN credit-term invoices as id => «ΤΙΜ123 — υπόλοιπο X€»,
     * for the apply-credit / manual-allocation pickers. Balance from the cached
     * money columns (paid_total already nets refunds). Only positive balances.
     * Memoised per request (read from several action closures per render).
     *
     * @return array<int, string>
     */
    private function openInvoiceOptions(): array
    {
        if ($this->openInvoiceOptionsCache !== null) {
            return $this->openInvoiceOptionsCache;
        }

        $q = Invoice::query()
            ->join('payment_methods', 'invoices.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.company_id', $this->record->company_id)
            ->where('invoices.customer_id', $this->record->getKey())
            ->whereNull('invoices.deleted_at')
            ->where('invoices.local_status', 'active')
            ->where('payment_methods.due_days', '>', 0);
        // MON-9: a payment can't be allocated to a credit note — exclude both the
        // correlated and the standalone legacy (is_credit type) shape.
        InvoiceScope::excludeCreditNotes($q);
        InvoiceScope::live($q, 'invoices.');

        return $this->openInvoiceOptionsCache = $q->orderBy('invoices.issued_at')
            ->get(['invoices.id', 'invoices.invcode', 'invoices.gross_total', 'invoices.payable_total', 'invoices.credited_total', 'invoices.paid_total'])
            ->mapWithKeys(function (Invoice $inv): array {
                // Open balance = collectible (payable_total, gross fallback) − credited − paid.
                $payable = (float) ($inv->payable_total ?? $inv->gross_total);
                $balance = round($payable - (float) $inv->credited_total - (float) $inv->paid_total, 2);

                return $balance > 0.005
                    ? [$inv->id => $inv->invcode.' — υπόλοιπο '.$this->fmtMoney($balance)]
                    : [];
            })
            ->all();
    }

    /** Tenant's payment methods as id => description (shared by the action schemas). */
    private function paymentMethodOptions(): array
    {
        return PaymentMethod::query()
            ->where('company_id', $this->record->company_id)
            ->pluck('description', 'id')
            ->all();
    }

    /* ===================== Header actions ===================== */

    protected function getHeaderActions(): array
    {
        return [
            // "Reverse" flow: start a new invoice straight from the
            // customer's account, pre-filled with this customer (+ snapshot).
            // See CreateInvoice::fillForm(). Hidden when the operator can't
            // issue invoices.
            Action::make('new_invoice')
                ->label('Νέο Παραστατικό')
                ->icon('heroicon-o-document-plus')
                ->color('primary')
                ->visible(fn (): bool => InvoiceResource::canCreate())
                ->url(fn (): string => InvoiceResource::getUrl('create', [
                    'customer_id' => $this->record->getKey(),
                ])),

            // All money operations live in one dropdown so the header stays
            // compact (was ~9 top-level actions → the row overflowed off-screen
            // on narrow viewports). Πιο συχνό πρώτο: Είσπραξη.
            ActionGroup::make([
                // One «έμβασμα/είσπραξη» auto-allocated FIFO across the open invoices
                // (oldest first), remainder → on-account credit. Mirrors Epsilon.
                Action::make('record_receipt')
                    ->label('Είσπραξη (έμβασμα)')
                    ->icon('heroicon-o-arrow-down-on-square-stack')
                    ->color('success')
                    ->modalHeading('Είσπραξη / Έμβασμα')
                    ->modalDescription('Το ποσό κατανέμεται αυτόματα στα ανοιχτά τιμολόγια (παλαιότερα πρώτα). Ό,τι περισσέψει μένει ως πίστωση/προκαταβολή στον πελάτη.')
                    ->modalSubmitActionLabel('Καταχώριση')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Ποσό είσπραξης (€)')->numeric()->minValue(0.01)->required(),
                        DatePicker::make('pay_date')
                            ->label('Ημερομηνία')->required()->default(now()),
                        Select::make('payment_method_id')
                            ->label('Τρόπος πληρωμής')
                            ->options(fn () => $this->paymentMethodOptions()),
                        BankAccountField::make($this->record->company_id, 'Σε ποιον λογαριασμό μπήκε το έμβασμα. Μπαίνει σε όλες τις γραμμές.'),
                        TextInput::make('transaction_id')
                            ->label('Κωδικός συναλλαγής')
                            ->maxLength(100)
                            ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας. Μπαίνει σε όλες τις γραμμές του εμβάσματος.'),
                        Textarea::make('notes')
                            ->label('Σημειώσεις')->rows(2),
                    ])
                    ->action(function (array $data) {
                        $res = app(PaymentAllocator::class)->allocate(
                            $this->record,
                            (float) $data['amount'],
                            Carbon::parse($data['pay_date']),
                            $data['payment_method_id'] ?? null,
                            null,
                            $data['notes'] ?? null,
                            $data['transaction_id'] ?? null,
                            $data['bank_account_id'] ?? null,
                        );
                        $msg = count($res->allocations).' τιμολόγια ('.number_format($res->allocatedToInvoices(), 2, ',', '.').' €)';
                        if ($res->onAccount > 0.005) {
                            $msg .= ' + '.number_format($res->onAccount, 2, ',', '.').' € πίστωση';
                        }
                        Notification::make()->success()->title('Η είσπραξη καταχωρίστηκε')->body($msg)->send();
                        $this->redirect(static::getUrl(['record' => $this->record]));
                    }),

                Action::make('record_on_account_payment')
                    ->label('Πληρωμή έναντι λογαριασμού')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading('Πληρωμή έναντι λογαριασμού')
                    ->modalSubmitActionLabel('Καταχώριση')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Ποσό')->numeric()->minValue(0.01)->required(),
                        DatePicker::make('pay_date')
                            ->label('Ημερομηνία')->required()->default(now()),
                        Select::make('payment_method_id')
                            ->label('Τρόπος πληρωμής')
                            ->options(fn () => $this->paymentMethodOptions()),
                        BankAccountField::make($this->record->company_id),
                        TextInput::make('transaction_id')
                            ->label('Κωδικός συναλλαγής')
                            ->maxLength(100)
                            ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας.'),
                        Textarea::make('notes')
                            ->label('Σημειώσεις')->rows(2),
                    ])
                    ->action(function (array $data) {
                        Payment::create([
                            'company_id' => $this->record->company_id,
                            'customer_id' => $this->record->getKey(),
                            'invoice_id' => null,
                            'kind' => 'payment',
                            'payment_method_id' => $data['payment_method_id'] ?? null,
                            'bank_account_id' => $data['bank_account_id'] ?? null,
                            'amount' => $data['amount'],
                            'pay_date' => $data['pay_date'],
                            'transaction_id' => $data['transaction_id'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ]);
                        Notification::make()->title('Η πληρωμή καταχωρίστηκε')->success()->send();
                        // Redirect to self so the KPI widgets + table reflect
                        // the new balance (header widgets are separate Livewire
                        // components mounted with the pre-payment stats).
                        $this->redirect(static::getUrl(['record' => $this->record]));
                    }),

                // Επιστροφή χρημάτων (refund) — money OUT, back to the customer, at
                // the customer level (invoice_id null). Clears an on-account credit
                // (e.g. left over after a credit note / cancellation) or returns an
                // overpayment. Recorded as kind='refund' so every money surface
                // (balance, καρτέλα, dashboard, receivables) nets it out.
                Action::make('record_refund')
                    ->label('Επιστροφή χρημάτων')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalHeading('Επιστροφή χρημάτων στον πελάτη')
                    ->modalDescription('Καταγράφει χρήματα που επιστράφηκαν (π.χ. μετά από ακύρωση/πιστωτικό). Αυξάνει το υπόλοιπο/μειώνει την πίστωση του πελάτη.')
                    ->modalSubmitActionLabel('Καταχώριση επιστροφής')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Ποσό επιστροφής (€)')->numeric()->minValue(0.01)->required(),
                        DatePicker::make('pay_date')
                            ->label('Ημερομηνία')->required()->default(now()),
                        Select::make('payment_method_id')
                            ->label('Τρόπος')
                            ->options(fn () => $this->paymentMethodOptions()),
                        BankAccountField::make($this->record->company_id, 'Από ποιον λογαριασμό επιστράφηκαν τα χρήματα.'),
                        TextInput::make('transaction_id')
                            ->label('Κωδικός συναλλαγής')
                            ->maxLength(100)
                            ->helperText('Προαιρετικό — ref επιστροφής τράπεζας / Stripe-PayPal refund.'),
                        Textarea::make('notes')
                            ->label('Σημειώσεις')->rows(2),
                    ])
                    ->action(function (array $data) {
                        Payment::create([
                            'company_id' => $this->record->company_id,
                            'customer_id' => $this->record->getKey(),
                            'invoice_id' => null,
                            'kind' => 'refund',
                            'payment_method_id' => $data['payment_method_id'] ?? null,
                            'bank_account_id' => $data['bank_account_id'] ?? null,
                            'amount' => $data['amount'],
                            'pay_date' => $data['pay_date'],
                            'transaction_id' => $data['transaction_id'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ]);
                        Notification::make()->success()->title('Η επιστροφή καταχωρίστηκε')->send();
                        $this->redirect(static::getUrl(['record' => $this->record]));
                    }),

                // #1 — Εφαρμογή πίστωσης: μετακινεί διαθέσιμη on-account πίστωση πάνω
                // σε ανοιχτό τιμολόγιο (re-point — net-zero στο συνολικό υπόλοιπο).
                Action::make('apply_credit')
                    ->label('Χρήση πίστωσης')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('info')
                    ->visible(fn () => $this->availableCredit() > 0.005 && $this->openInvoiceOptions() !== [])
                    ->modalHeading('Χρήση διαθέσιμης πίστωσης')
                    ->modalDescription(fn () => 'Διαθέσιμη πίστωση: '.$this->fmtMoney($this->availableCredit()).'. Επιλέξτε τιμολόγιο για να την εφαρμόσετε.')
                    ->modalSubmitActionLabel('Εφαρμογή')
                    ->schema(fn () => [
                        Select::make('invoice_id')
                            ->label('Τιμολόγιο')
                            ->options($this->openInvoiceOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->label('Ποσό (€)')
                            ->numeric()->minValue(0.01)->required()
                            ->default(fn () => number_format($this->availableCredit(), 2, '.', ''))
                            ->helperText('Δεν μπορεί να ξεπεράσει τη διαθέσιμη πίστωση ή το υπόλοιπο του τιμολογίου.'),
                    ])
                    ->action(function (array $data) {
                        $invoice = Invoice::query()
                            ->where('company_id', $this->record->company_id)
                            ->where('customer_id', $this->record->getKey())
                            ->findOrFail($data['invoice_id']);
                        try {
                            $applied = app(PaymentAllocator::class)->applyCredit($this->record, $invoice, (float) $data['amount']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Δεν έγινε εφαρμογή')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Η πίστωση εφαρμόστηκε')
                            ->body($this->fmtMoney($applied).' στο '.$invoice->invcode)->send();
                        $this->redirect(static::getUrl(['record' => $this->record]));
                    }),

                // #2 — Χειροκίνητη κατανομή: ο χειριστής ορίζει ποσό ανά τιμολόγιο.
                Action::make('manual_allocation')
                    ->label('Χειροκίνητη κατανομή')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('success')
                    ->visible(fn () => $this->openInvoiceOptions() !== [])
                    ->modalHeading('Χειροκίνητη κατανομή είσπραξης')
                    ->modalDescription('Ορίστε ΑΚΡΙΒΩΣ πόσα πηγαίνουν σε κάθε τιμολόγιο (αντί για αυτόματη FIFO).')
                    ->modalSubmitActionLabel('Καταχώριση')
                    ->schema([
                        DatePicker::make('pay_date')->label('Ημερομηνία')->required()->default(now()),
                        Select::make('payment_method_id')
                            ->label('Τρόπος πληρωμής')
                            ->options(fn () => $this->paymentMethodOptions()),
                        BankAccountField::make($this->record->company_id),
                        TextInput::make('transaction_id')->label('Κωδικός συναλλαγής')->maxLength(100),
                        Repeater::make('lines')
                            ->label('Κατανομή')
                            ->schema([
                                Select::make('invoice_id')
                                    ->label('Τιμολόγιο')
                                    ->options($this->openInvoiceOptions())
                                    ->searchable()
                                    ->required(),
                                TextInput::make('amount')
                                    ->label('Ποσό (€)')->numeric()->minValue(0.01)->required(),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->addActionLabel('Προσθήκη τιμολογίου'),
                        Textarea::make('notes')->label('Σημειώσεις')->rows(2),
                    ])
                    ->action(function (array $data) {
                        try {
                            $res = app(PaymentAllocator::class)->allocateManual(
                                $this->record,
                                $data['lines'] ?? [],
                                Carbon::parse($data['pay_date']),
                                $data['payment_method_id'] ?? null,
                                null,
                                $data['notes'] ?? null,
                                $data['transaction_id'] ?? null,
                                $data['bank_account_id'] ?? null,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Δεν έγινε κατανομή')->body($e->getMessage())->send();

                            return;
                        }
                        // Warn (don't block) if any target ended up overpaid —
                        // parity with the single-payment cockpit path. One fetch for
                        // all touched invoices (vs a query per allocation line).
                        $overpaid = Invoice::query()
                            ->where('company_id', $this->record->company_id)
                            ->whereIn('invcode', array_column($res->allocations, 'invcode'))
                            ->get()
                            ->filter(fn (Invoice $inv) => $inv->balanceData()->status === PaymentStatus::Overpaid)
                            ->pluck('invcode')
                            ->all();
                        if ($overpaid !== []) {
                            Notification::make()->warning()->title('Υπερπληρωμή')
                                ->body('Υπερβαίνει το υπόλοιπο: '.implode(', ', $overpaid).'.')->send();
                        }

                        Notification::make()->success()->title('Η κατανομή καταχωρίστηκε')
                            ->body(count($res->allocations).' τιμολόγια ('.$this->fmtMoney($res->allocatedToInvoices()).')')->send();
                        $this->redirect(static::getUrl(['record' => $this->record]));
                    }),
            ])
                ->label('Εισπράξεις / Πληρωμές')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->button(),

            ActionGroup::make([
                Action::make('export_pdf')
                    ->label('Εξαγωγή PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function () {
                        $renderer = app(CustomerStatementPdfRenderer::class);
                        $bytes = $renderer->render($this->record);
                        $filename = $renderer->filename($this->record);

                        return response()->streamDownload(
                            fn () => print ($bytes),
                            $filename,
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                Action::make('export_csv')
                    ->label('Εξαγωγή CSV')
                    ->icon('heroicon-o-table-cells')
                    ->action(function () {
                        $csvService = app(CustomerStatementCsv::class);
                        $csv = $csvService->build($this->record);
                        $filename = $csvService->filename($this->record);

                        return response()->streamDownload(
                            fn () => print ($csv),
                            $filename,
                            ['Content-Type' => 'text/csv; charset=UTF-8'],
                        );
                    }),
                Action::make('email_statement')
                    ->label('Αποστολή στο email')
                    ->icon('heroicon-o-paper-airplane')
                    ->modalHeading('Αποστολή καρτέλας στο email')
                    ->modalDescription('Επιλέξτε παραλήπτες από τον πελάτη και τις επαφές του (π.χ. λογιστήριο), ή προσθέστε ελεύθερα emails.')
                    ->modalSubmitActionLabel('Αποστολή')
                    ->fillForm(fn (): array => [
                        'recipients' => $this->defaultStatementRecipients(),
                        'extra_recipients' => null,
                        'subject' => null,
                        'message' => null,
                    ])
                    ->schema([
                        CheckboxList::make('recipients')
                            ->label('Παραλήπτες')
                            ->options(fn (): array => $this->statementRecipientOptions())
                            ->helperText($this->statementRecipientOptions() === []
                                ? 'Ο πελάτης δεν έχει email ούτε επαφές με email — προσθέστε παραλήπτη παρακάτω.'
                                : 'Πελάτης + επαφές με email.'),
                        TextInput::make('extra_recipients')
                            ->label('Επιπλέον παραλήπτες')
                            ->placeholder('email1@example.com, email2@example.com')
                            ->helperText('Προαιρετικά, χωρισμένα με κόμμα.'),
                        TextInput::make('subject')
                            ->label('Θέμα')
                            ->placeholder('Καρτέλα πελάτη: '.$this->record->name),
                        Textarea::make('message')
                            ->label('Μήνυμα (προαιρετικό)')
                            ->rows(3),
                    ])
                    ->action(function (array $data) {
                        $this->sendStatementEmail(
                            $data['recipients'] ?? [],
                            $data['extra_recipients'] ?? null,
                            $data['subject'] ?? null,
                            $data['message'] ?? null,
                        );
                    }),
            ])
                ->label('Εξαγωγή / Αποστολή')
                ->icon('heroicon-o-arrow-down-tray')
                ->button(),

            // Secondary / occasional actions — out of the main row.
            ActionGroup::make([
                Action::make('crosscheck_aade')
                    ->label('Διασταύρωση ΑΦΜ με ΑΑΔΕ')
                    ->icon('heroicon-o-shield-check')
                    ->color('info')
                    ->visible(fn () => $this->canCrosscheckAade())
                    ->modalHeading(fn () => 'Διασταύρωση ΑΦΜ '.($this->record->afm ?: '—').' με ΑΑΔΕ')
                    ->modalSubmitActionLabel('Ενημέρωση πελάτη με στοιχεία ΑΑΔΕ')
                    ->modalCancelActionLabel('Κλείσιμο')
                    ->modalContent(function () {
                        $result = $this->runAadeCrosscheck();

                        return view('filament.customers.aade-crosscheck-modal', [
                            'customer' => $this->record,
                            'result' => $result,
                        ]);
                    })
                    ->action(function () {
                        $result = $this->runAadeCrosscheck();
                        if ($result['error'] !== null) {
                            Notification::make()->title('Δεν εφαρμόστηκαν αλλαγές')->body($result['error'])->warning()->send();

                            return;
                        }
                        if (empty($result['diffs'])) {
                            Notification::make()->title('Τα στοιχεία είναι ήδη συγχρονισμένα')->success()->send();

                            return;
                        }
                        $this->applyAadeCrosscheck($result['diffs']);
                        Notification::make()
                            ->title('Στοιχεία πελάτη ενημερώθηκαν')
                            ->body(count($result['diffs']).' πεδίο/α ενημερώθηκαν από την ΑΑΔΕ.')
                            ->success()->send();
                        $this->aadeCrosscheckMemo = null;
                    }),
                Action::make('edit')
                    ->label('Επεξεργασία πελάτη')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->url(fn () => CustomerResource::getUrl('edit', ['record' => $this->record])),
                Action::make('back_to_list')
                    ->label('Λίστα πελατών')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(fn () => CustomerResource::getUrl('index')),
            ])
                ->label('Περισσότερα')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * Pickable recipients for the statement email: the customer's own email
     * plus every per-customer contact that has an email, labelled with the
     * contact's name + role (e.g. «Λογιστήριο (Μαρία) — maria@…»). Keyed by the
     * address itself so the CheckboxList returns ready-to-send emails. Contacts
     * were eager-loaded on mount().
     *
     * @return array<string, string>
     */
    private function statementRecipientOptions(): array
    {
        $options = [];

        $email = trim((string) $this->record->email);
        if ($email !== '') {
            $options[$email] = 'Πελάτης — '.$email;
        }

        foreach ($this->record->contacts as $contact) {
            $cEmail = trim((string) $contact->email);
            if ($cEmail === '' || isset($options[$cEmail])) {
                continue;
            }
            $label = trim((string) $contact->name) ?: 'Επαφή';
            if (filled($contact->role)) {
                $label .= ' ('.$contact->role.')';
            }
            $options[$cEmail] = $label.' — '.$cEmail;
        }

        return $options;
    }

    /**
     * Pre-checked recipients: the customer email (if any) and any primary
     * contact's email — the common «στείλ' το στον πελάτη και στο λογιστήριό
     * του» default; the operator can tick/untick the rest.
     *
     * @return array<int, string>
     */
    private function defaultStatementRecipients(): array
    {
        $defaults = [];

        $email = trim((string) $this->record->email);
        if ($email !== '') {
            $defaults[] = $email;
        }

        foreach ($this->record->contacts as $contact) {
            $cEmail = trim((string) $contact->email);
            if ($cEmail !== '' && $contact->is_primary && ! in_array($cEmail, $defaults, true)) {
                $defaults[] = $cEmail;
            }
        }

        return $defaults;
    }

    /**
     * Email the Καρτέλα PDF to one or more recipients (επαφή-aware): the picked
     * customer/contact addresses merged with any free-text extras. Validates +
     * dedupes (case-insensitive), warns on bad addresses, and renders the PDF
     * once for the whole batch.
     *
     * @param  array<int, string>  $selected
     */
    private function sendStatementEmail(array $selected, ?string $extra, ?string $subject, ?string $message): void
    {
        $tenant = $this->record->company;
        if ($tenant === null) {
            // Defensive: a customer in a tenant panel always has its
            // company, but an orphaned / soft-deleted company would
            // otherwise hit TenantMailerFactory::for(Company)'s
            // non-nullable type and surface as a misleading "email
            // settings" error.
            Notification::make()
                ->title('Αποτυχία αποστολής')
                ->body('Ο πελάτης δεν είναι συνδεδεμένος με εταιρεία.')
                ->danger()
                ->send();

            return;
        }

        // Merge picked + free-text (comma/semicolon/whitespace separated),
        // validate, dedupe case-insensitively (keeping the first casing seen).
        $candidates = array_merge(
            $selected,
            preg_split('/[,;\s]+/', (string) $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );

        $valid = [];
        $invalid = [];
        foreach ($candidates as $addr) {
            $addr = trim((string) $addr);
            if ($addr === '') {
                continue;
            }
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $valid[mb_strtolower($addr)] ??= $addr;
            } else {
                $invalid[] = $addr;
            }
        }
        $recipients = array_values($valid);

        if ($invalid !== []) {
            Notification::make()
                ->title('Μη έγκυρα email')
                ->body('Αγνοήθηκαν: '.implode(', ', $invalid))
                ->warning()
                ->send();
        }

        if ($recipients === []) {
            Notification::make()
                ->title('Δεν επιλέχθηκε παραλήπτης')
                ->body('Επιλέξτε τουλάχιστον έναν έγκυρο παραλήπτη.')
                ->warning()
                ->send();

            return;
        }

        try {
            $bytes = app(CustomerStatementPdfRenderer::class)->render($this->record);

            $mail = new CustomerStatementMail(
                customer: $this->record,
                pdfBytes: $bytes,
                bodyMessage: $message,
                subjectLine: $subject ?: null,
            );

            app(TenantMailerFactory::class)->for($tenant)->to($recipients)->send($mail);

            Notification::make()
                ->title('Η καρτέλα στάλθηκε')
                ->body('Παραλήπτες: '.implode(', ', $recipients))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Log::error('Customer statement email failed', [
                'customer_id' => $this->record->getKey(),
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Αποτυχία αποστολής')
                ->body('Η καρτέλα δεν στάλθηκε. Ελέγξτε τις ρυθμίσεις email.')
                ->danger()
                ->send();
        }
    }

    /* ===================== WHMCS panel ===================== */

    public function toggleWhmcsPanel(): void
    {
        $this->showWhmcsPanel = ! $this->showWhmcsPanel;
        if ($this->showWhmcsPanel && $this->whmcsLedger === null) {
            $this->loadWhmcsLedger();
        }
    }

    public function refreshWhmcsLedger(): void
    {
        $this->whmcsLedger = null;
        $this->loadWhmcsLedger();
        Notification::make()->title('WHMCS data refreshed')->success()->send();
    }

    private function loadWhmcsLedger(): void
    {
        $this->whmcsLedger = app(CustomerWhmcsLedger::class)->fetchFor($this->record);
    }

    /* ===================== AADE crosscheck ===================== */

    private function canCrosscheckAade(): bool
    {
        if (! $this->record->afm) {
            return false;
        }
        $tenant = $this->record->company;
        if ($tenant === null) {
            return false;
        }
        if ($tenant->country_code !== 'GR') {
            return false;
        }

        return ! empty($tenant->gsis_username) && ! empty($tenant->gsis_password);
    }

    private function runAadeCrosscheck(): array
    {
        if ($this->aadeCrosscheckMemo !== null && $this->aadeCrosscheckMemo['error'] === null) {
            return $this->aadeCrosscheckMemo;
        }
        $tenant = $this->record->company;
        $afm = trim((string) $this->record->afm);

        try {
            $record = app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm, bypassCache: true);
        } catch (AadeRegistryException $e) {
            $d = $this->aadeExceptionDetails($e);

            return $this->aadeCrosscheckMemo = [
                'diffs' => [],
                'error' => $d['title'].': '.$d['body'],
                'is_active' => null,
                'status_descr' => null,
                'activities' => [],
            ];
        }

        $primaryActivity = $record->primaryActivity();
        $aadeOccupation = (string) ($primaryActivity['description'] ?? '');

        $candidates = [
            'name' => ['stored' => (string) $this->record->name, 'aade' => $record->name],
            'tax_office' => ['stored' => (string) $this->record->tax_office, 'aade' => $record->doy],
            'address1' => ['stored' => (string) $this->record->address1, 'aade' => $record->address],
            'city' => ['stored' => (string) $this->record->city, 'aade' => $record->city],
            'postcode' => ['stored' => (string) $this->record->postcode, 'aade' => $record->postcode],
            'occupation' => ['stored' => (string) $this->record->occupation, 'aade' => $aadeOccupation],
        ];

        $diffs = [];
        foreach ($candidates as $field => $pair) {
            $storedTrimmed = trim($pair['stored']);
            $aadeTrimmed = trim($pair['aade']);
            if ($storedTrimmed === $aadeTrimmed) {
                continue;
            }
            if ($aadeTrimmed === '' && $storedTrimmed !== '') {
                continue;
            }
            $diffs[$field] = $pair;
        }

        return $this->aadeCrosscheckMemo = [
            'diffs' => $diffs,
            'error' => null,
            'is_active' => $record->active,
            'status_descr' => $record->statusDescr,
            'activities' => $record->activities,
        ];
    }

    /**
     * @param  array<string, array{stored: string, aade: string}>  $diffs
     */
    private function applyAadeCrosscheck(array $diffs): void
    {
        if ($diffs === []) {
            return;
        }
        $update = [];
        foreach ($diffs as $field => $pair) {
            $update[$field] = $pair['aade'];
        }
        $this->record->update($update);
        $this->record->refresh();
    }

    private function loadDimensionLookups(): void
    {
        $driver = \DB::connection()->getDriverName();
        $yearExpr = match ($driver) {
            'mysql', 'mariadb' => 'YEAR(issued_at)',
            'sqlite' => 'CAST(strftime("%Y", issued_at) AS INTEGER)',
            'pgsql' => 'EXTRACT(YEAR FROM issued_at)',
            default => throw new \RuntimeException("Unsupported DB driver for Καρτέλα year-extract: {$driver}"),
        };

        $this->availableYears = \DB::table('invoices')
            ->where('company_id', $this->record->company_id)
            ->where('customer_id', $this->record->id)
            ->whereNull('deleted_at')
            ->selectRaw("DISTINCT {$yearExpr} AS year")
            ->orderByDesc('year')
            ->pluck('year')
            ->filter()
            ->map(fn ($y) => (int) $y)
            ->values()
            ->all();

        $this->availableInvoiceTypes = \DB::table('invoice_types')
            ->where('company_id', $this->record->company_id)
            ->orderBy('code')
            ->select('id', 'code')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code])
            ->all();
    }
}
