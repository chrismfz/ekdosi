<?php

namespace App\Filament\Resources\Customers\Pages;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeRegistryException;
use App\Exceptions\Aade\AadeUnreachable;
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
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.customers.ledger';

    public ?Customer $record = null;

    public ?int $filterYear = null;

    public ?int $filterInvoiceTypeId = null;

    public ?string $filterPaidStatus = null;

    public bool $showWhmcsPanel = false;

    public ?CustomerLedgerResult $ledger = null;

    public ?CustomerWhmcsLedgerResult $whmcsLedger = null;

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

        // Authorisation: piggyback on the resource's view policy.
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
                    // The submit button only matters when AADE returned a
                    // record (no errors). We re-fetch + apply.
                    $result = $this->runAadeCrosscheck();
                    if ($result['error'] !== null) {
                        Notification::make()->title('Δεν εφαρμόστηκαν αλλαγές')->body($result['error'])->warning()->send();
                        return;
                    }
                    if (empty($result['diffs'])) {
                        Notification::make()->title('Τα στοιχεία είναι ήδη συγχρονισμένα')->success()->send();
                        return;
                    }
                    $this->applyAadeCrosscheck($result['record']);
                    Notification::make()
                        ->title('Στοιχεία πελάτη ενημερώθηκαν')
                        ->body(count($result['diffs']).' πεδίο/α ενημερώθηκαν από την ΑΑΔΕ.')
                        ->success()->send();
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
        $tenant = $this->record->company;
        $afm = trim((string) $this->record->afm);

        try {
            $record = app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm);
        } catch (AadeCredentialsInvalid) {
            return ['record' => null, 'diffs' => [], 'error' => 'GSIS credentials missing or invalid. Configure them on the Company → AADE registry tab.'];
        } catch (AadeAfmNotFound) {
            return ['record' => null, 'diffs' => [], 'error' => 'Η ΑΑΔΕ δεν αναγνωρίζει αυτό το ΑΦΜ.'];
        } catch (AadeUnreachable $e) {
            return ['record' => null, 'diffs' => [], 'error' => 'Η υπηρεσία ΑΑΔΕ δεν είναι προσβάσιμη: '.$e->getMessage()];
        } catch (AadeRegistryException $e) {
            return ['record' => null, 'diffs' => [], 'error' => 'Σφάλμα από ΑΑΔΕ: '.$e->getMessage()];
        }

        $primaryActivity = $record->primaryActivity();
        $aadeOccupation = $primaryActivity['descr'] ?? '';

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

        return ['record' => $record, 'diffs' => $diffs, 'error' => null];
    }

    private function applyAadeCrosscheck(AadeRegistryRecord $record): void
    {
        $primaryActivity = $record->primaryActivity();
        $this->record->update([
            'name'       => $record->name,
            'tax_office' => $record->doy,
            'address1'   => $record->address,
            'city'       => $record->city,
            'postcode'   => $record->postcode,
            'occupation' => $primaryActivity['descr'] ?? $this->record->occupation,
        ]);
        $this->record->refresh();
    }

    private function buildLedger(): void
    {
        $this->ledger = app(CustomerLedgerBuilder::class)->build(
            $this->record,
            [
                'year'            => $this->filterYear,
                'invoice_type_id' => $this->filterInvoiceTypeId,
                'paid_status'     => $this->filterPaidStatus,
            ],
        );
    }

    private function loadDimensionLookups(): void
    {
        $this->availableYears = \DB::table('invoices')
            ->where('company_id', $this->record->company_id)
            ->where('customer_id', $this->record->id)
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT CAST(strftime("%Y", issued_at) AS INTEGER) AS year')
            ->pluck('year')
            ->filter()
            ->sortDesc()
            ->values()
            ->all();

        // Note: strftime is SQLite. For MariaDB the same query uses
        // YEAR(issued_at). Detect engine + branch.
        $driver = \DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->availableYears = \DB::table('invoices')
                ->where('company_id', $this->record->company_id)
                ->where('customer_id', $this->record->id)
                ->whereNull('deleted_at')
                ->selectRaw('DISTINCT YEAR(issued_at) AS year')
                ->orderByDesc('year')
                ->pluck('year')
                ->filter()
                ->values()
                ->all();
        }

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
