<?php

namespace App\Filament\Resources\Customers\Pages;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeRegistryException;
use App\Filament\Concerns\HandlesAadeRegistryExceptions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\AadeRegistryLookup;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\CustomerLedger\CustomerLedgerResult;
use App\Services\Whmcs\CustomerWhmcsLedger;
use App\Services\Whmcs\CustomerWhmcsLedgerResult;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * Καρτέλα Πελάτη: full customer financial dashboard.
 *
 * Sections:
 *   1. Header card - identity + key facts + balance highlighted
 *   2. Quick stats - YTD net/gross/paid, balance, oldest unpaid days,
 *                    last activity
 *   3. Aging buckets - 0-30 / 31-60 / 61-90 / 90+ days outstanding
 *   4. Yearly breakdown - per-year totals + year-end balance
 *   5. Chronological ledger - merged invoices + payments with running
 *      balance (the main feature - what Greek accountants call "Καρτέλα")
 *   6. WHMCS comparison (collapsible) - what WHMCS has for this customer
 *      cross-referenced with ekdosi-side state
 *
 * Read-only. Operator clicks "Open Καρτέλα" from EditCustomer or
 * navigates to /admin/{tenant}/customers/{id}/ledger directly.
 *
 * Filters live on the URL query (?year=2025&invoice_type=3&paid=unpaid)
 * so operators can bookmark a specific view.
 */
class CustomerLedger extends Page
{
    use HandlesAadeRegistryExceptions;

    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.customers.ledger';

    public ?Customer $record = null;

    public ?int $filterYear = null;

    public ?int $filterInvoiceTypeId = null;

    public ?string $filterPaidStatus = null;

    public bool $showWhmcsPanel = false;

    public ?CustomerLedgerResult $ledger = null;

    /**
     * Filter-independent sections of the ledger (stats / aging /
     * yearly). Computed ONCE on mount and reused across filter
     * changes so the operator clicking a filter doesn't re-run 4
     * O(N) passes that don't depend on the filter values.
     *
     * @var array{stats: array, aging: array, yearly: array}|null
     */
    public ?array $cachedStatsBlock = null;

    public ?CustomerWhmcsLedgerResult $whmcsLedger = null;

    /**
     * Memoised AADE crosscheck result, shared across the modalContent
     * render and the action submit so we don't (a) double-fetch from
     * AADE on cold cache, or (b) hit a race where the modal preview
     * and the apply ran against different AADE responses.
     *
     * @var array{record: ?\App\DTOs\AadeRegistryRecord, diffs: array, error: ?string}|null
     */
    public ?array $aadeCrosscheckMemo = null;

    /**
     * @var array<int, array{id: int, code: string}>
     */
    public array $availableYears = [];

    /**
     * @var array<int, array{id: int, code: string}>
     */
    public array $availableInvoiceTypes = [];

    public function mount(int|string $record): void
    {
        $this->record = Customer::query()->where('id', (int) $record)->firstOrFail();

        // Defense in depth #1: the Customer model has no global
        // BelongsToCompany scope (tracked in CLAUDE.md), so a raw
        // ::query() bypasses Filament's panel tenant scope. If panel
        // scope is ever bypassed (Octane boot ordering, future
        // non-panel caller, scope removal) the policy check below
        // is tenant-blind and would render cross-tenant data. Refuse
        // explicitly before any expensive work.
        $currentTenantId = \Filament\Facades\Filament::getTenant()?->getKey();
        abort_unless(
            $currentTenantId !== null && (int) $this->record->company_id === (int) $currentTenantId,
            404,    // 404 not 403: don't disclose that the record exists for a different tenant
        );

        // Defense in depth #2: policy gate (per-user permission).
        abort_unless(auth()->user()?->can('view', $this->record) ?? false, 403);

        // Read filters from query string.
        $this->filterYear = request()->integer('year') ?: null;
        $this->filterInvoiceTypeId = request()->integer('invoice_type') ?: null;
        $this->filterPaidStatus = in_array(request()->string('paid')->toString(), ['paid', 'unpaid'], true)
            ? request()->string('paid')->toString()
            : null;

        $this->buildLedger();
        $this->loadDimensionLookups();
    }

    /**
     * Trigger a re-build when any filter property changes (Livewire
     * hook). Filament/Livewire calls updatedFilterYear etc on
     * property-update; this single hook covers all three.
     */
    public function updated($name, $value): void
    {
        if (in_array($name, ['filterYear', 'filterInvoiceTypeId', 'filterPaidStatus'], true)) {
            $this->buildLedger();
        }
    }

    public function resetFilters(): void
    {
        $this->filterYear = null;
        $this->filterInvoiceTypeId = null;
        $this->filterPaidStatus = null;
        $this->buildLedger();
    }

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
        Notification::make()
            ->title('WHMCS data refreshed')
            ->success()
            ->send();
    }

    public function getTitle(): string
    {
        return 'Καρτέλα: '.($this->record?->name ?? '(unknown)');
    }

    public function getBreadcrumb(): string
    {
        return 'Καρτέλα';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Διασταύρωση with AADE registry: compare stored customer
            // identity against the live GSIS record, surface drifts,
            // optionally apply updates. Visible only when the customer
            // has an AFM, the tenant is Greek, and GSIS is configured -
            // otherwise the call would just throw or return useless data.
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
                        'customer'   => $this->record,
                        'result'     => $result,
                    ]);
                })
                ->action(function () {
                    // Re-fetch is memoised (see runAadeCrosscheck) so
                    // this is a cache hit when the modal preview already
                    // ran; closes the race window where the operator
                    // approved diffs A but the apply ran against diffs B.
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
                    // Reset the memo so the next modal open re-fetches.
                    $this->aadeCrosscheckMemo = null;
                }),

            Action::make('edit')
                ->label('Επεξεργασία')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->url(fn () => CustomerResource::getUrl('edit', ['record' => $this->record])),
            Action::make('back_to_list')
                ->label('Λίστα πελατών')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => CustomerResource::getUrl('index')),
        ];
    }

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

    /**
     * Fetch from AADE + compute the diff against the stored customer.
     *
     * Memoised on the Livewire instance ($this->aadeCrosscheckMemo)
     * so the modalContent render and the action submit share ONE
     * AADE fetch + ONE diff computation. The memo is cleared after a
     * successful apply (so the next modal open re-fetches).
     *
     * Returned shape:
     *   [
     *     'record' => ?AadeRegistryRecord,
     *     'diffs'  => array<string, ['stored' => string, 'aade' => string]>,
     *     'error'  => ?string,
     *   ]
     *
     * 'diffs' is keyed by our customer column name; absent key means
     * the field already matches. Empty diffs = nothing to apply.
     */
    private function runAadeCrosscheck(): array
    {
        if ($this->aadeCrosscheckMemo !== null) {
            return $this->aadeCrosscheckMemo;
        }
        $tenant = $this->record->company;
        $afm = trim((string) $this->record->afm);

        try {
            // bypassCache: true — the action label "Διασταύρωση με ΑΑΔΕ"
            // promises a LIVE comparison; if we served the 24h cache
            // here, drifts that AADE just published would silently not
            // surface and the operator would think the customer record
            // is current when it isn't.
            $record = app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm, bypassCache: true);
        } catch (AadeRegistryException $e) {
            // Single catch via the trait - all four subclasses
            // (AadeCredentialsInvalid / AadeAfmNotFound / AadeUnreachable
            // / AadeRegistryException itself) flow through one mapping.
            $d = $this->aadeExceptionDetails($e);
            return $this->aadeCrosscheckMemo = [
                'record' => null,
                'diffs' => [],
                'error' => $d['title'].': '.$d['body'],
            ];
        }

        // primaryActivity() shape: ['code', 'description', 'kind']
        // (verified in app/DTOs/AadeRegistryRecord.php). Earlier
        // commit read ['descr'] which never exists, causing every
        // crosscheck to surface a phantom occupation diff.
        $primaryActivity = $record->primaryActivity();
        $aadeOccupation = (string) ($primaryActivity['description'] ?? '');

        // Field-by-field comparison. Trimmed string compare; case-
        // sensitive (Greek names sometimes vary by case but AADE is
        // authoritative). Operator decides whether the casing drift
        // is worth a sync.
        $candidates = [
            'name'       => ['stored' => (string) $this->record->name, 'aade' => $record->name],
            'tax_office' => ['stored' => (string) $this->record->tax_office, 'aade' => $record->doy],
            'address1'   => ['stored' => (string) $this->record->address1, 'aade' => $record->address],
            'city'       => ['stored' => (string) $this->record->city, 'aade' => $record->city],
            'postcode'   => ['stored' => (string) $this->record->postcode, 'aade' => $record->postcode],
            'occupation' => ['stored' => (string) $this->record->occupation, 'aade' => $aadeOccupation],
        ];

        $diffs = [];
        foreach ($candidates as $field => $pair) {
            if (trim($pair['stored']) !== trim($pair['aade'])) {
                $diffs[$field] = $pair;
            }
        }

        return $this->aadeCrosscheckMemo = ['record' => $record, 'diffs' => $diffs, 'error' => null];
    }

    /**
     * Apply ONLY the fields that drifted (per the computed diff).
     * The previous implementation blindly overwrote all 6 fields,
     * which destroyed valid operator-entered data whenever AADE
     * returned blank values for fields the operator had filled in
     * (common case: inactive AFMs with stripped registry data).
     *
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

    private function buildLedger(): void
    {
        $filters = [
            'year'            => $this->filterYear,
            'invoice_type_id' => $this->filterInvoiceTypeId,
            'paid_status'     => $this->filterPaidStatus,
        ];

        $builder = app(CustomerLedgerBuilder::class);

        // First load: compute the filter-independent block ONCE and
        // cache on the Livewire instance. Subsequent filter changes
        // skip the stats/aging/yearly recomputation (3 O(N) passes
        // over invoices+payments).
        if ($this->cachedStatsBlock === null) {
            $this->cachedStatsBlock = $builder->buildStatsBlock($this->record);
        }

        $this->ledger = new CustomerLedgerResult(
            stats:          $this->cachedStatsBlock['stats'],
            aging:          $this->cachedStatsBlock['aging'],
            yearly:         $this->cachedStatsBlock['yearly'],
            ledger:         $builder->buildLedgerOnly($this->record, $filters),
            appliedFilters: $filters,
        );
    }

    private function loadDimensionLookups(): void
    {
        // Branch on driver BEFORE issuing the query. The previous code
        // ran the SQLite strftime() query unconditionally then
        // overrode it on MariaDB - but strftime is not a MariaDB
        // function, so the first query threw "FUNCTION strftime does
        // not exist" before the override could execute. Result: 500
        // on every production page load. Tests passed because phpunit
        // uses SQLite where strftime IS native.
        $driver = \DB::connection()->getDriverName();
        $yearExpr = match ($driver) {
            'mysql', 'mariadb' => 'YEAR(issued_at)',
            'sqlite'           => 'CAST(strftime("%Y", issued_at) AS INTEGER)',
            'pgsql'            => 'EXTRACT(YEAR FROM issued_at)',
            default            => throw new \RuntimeException("Unsupported DB driver for Καρτέλα year-extract: {$driver}"),
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

    private function loadWhmcsLedger(): void
    {
        $this->whmcsLedger = app(CustomerWhmcsLedger::class)->fetchFor($this->record);
    }
}
