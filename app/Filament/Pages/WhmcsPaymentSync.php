<?php

namespace App\Filament\Pages;

use App\Filament\PaymentSync\WhmcsOutboundPaymentsTable;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentReconciler;
use App\Services\Whmcs\WhmcsPaymentSyncer;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «Συγχρονισμός πληρωμών WHMCS» — the central, «εύκαιρο» worklist of open
 * (επί πιστώσει) invoices that WHMCS now reports Paid, so the operator can
 * close each receivable with one click instead of hunting invoice-by-invoice.
 *
 * The list is the cached worklist (WhmcsPaymentReconciler, scheduled or the
 * «Ανανέωση τώρα» action). The per-row «Καταγραφή πληρωμής» re-checks WHMCS
 * LIVE and records via WhmcsPaymentSyncer (money-write in ekdosi only, the same
 * idempotent/only-if-open path as the per-invoice button and the cron).
 *
 * Phase 1 shows the INBOUND direction; Phase 2 adds the outbound list
 * («εξοφλήθηκαν εδώ, ενημέρωσε το WHMCS»).
 */
class WhmcsPaymentSync extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'myDATA & Διασυνδέσεις';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.whmcs-payment-sync';

    public static function getNavigationLabel(): string
    {
        return 'Συγχρονισμός πληρωμών';
    }

    public function getTitle(): string
    {
        return 'Συγχρονισμός πληρωμών WHMCS';
    }

    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return null;
        }
        $n = count(WhmcsPaymentSyncCache::inboundIds($tenant));

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    /** Hide the menu item + block the route for tenants without WHMCS / access. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && ($tenant->hasWhmcsIntegration() || $tenant->whmcs_fetch_via_bridge)
            // Reuse the inbox permission — whoever may see the WHMCS inbox may
            // see (and act on) this worklist. No new permission to provision.
            && (bool) auth()->user()?->can('ViewAny:PendingWhmcsInvoice');
    }

    /**
     * The OUTBOUND list («εξοφλήθηκαν εδώ → ενημέρωσε το WHMCS») renders below
     * the inbound table. It's a footer widget (self-gated to opted-in tenants),
     * kept out of app/Filament/Widgets so it never leaks onto the dashboard.
     */
    protected function getFooterWidgets(): array
    {
        return [WhmcsOutboundPaymentsTable::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Ανανέωση τώρα')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant instanceof Company) {
                        return;
                    }
                    $fetch = app(WhmcsInvoiceFetcher::class)->for($tenant);
                    if ($fetch === null) {
                        Notification::make()
                            ->title('Δεν έχει ρυθμιστεί WHMCS')
                            ->warning()->send();

                        return;
                    }
                    $inbound = app(WhmcsPaymentReconciler::class)->reconcile($tenant, $fetch);
                    Notification::make()
                        ->title('Ανανεώθηκε')
                        ->body(count($inbound) > 0
                            ? count($inbound).' τιμολόγια πληρωμένα στο WHMCS προς καταγραφή.'
                            : 'Κανένα ανοιχτό τιμολόγιο δεν φαίνεται πληρωμένο στο WHMCS.')
                        ->success()->send();
                }),
        ];
    }

    /** Tenant-scoped invoices in the cached inbound worklist. */
    protected function inboundQuery(): Builder
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return Invoice::query()->whereRaw('1 = 0');
        }

        $ids = WhmcsPaymentSyncCache::inboundIds($tenant);
        if ($ids === []) {
            return Invoice::query()->whereRaw('1 = 0');
        }

        return Invoice::query()
            ->where('invoices.company_id', $tenant->id)
            ->whereIn('invoices.id', $ids)
            ->with(['customer', 'paymentMethod', 'whmcsPending']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->inboundQuery())
            ->columns([
                TextColumn::make('invcode')
                    ->label('Παραστατικό')
                    ->color('primary')
                    ->url(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r, 'tenant' => $r->company])),
                TextColumn::make('customer.name')->label('Πελάτης')->limit(35)->placeholder('—'),
                TextColumn::make('whmcs')
                    ->label('WHMCS')
                    ->state(fn (Invoice $r) => ($w = $r->whmcsPending?->whmcs_invoice_id) ? '#'.$w : '—'),
                TextColumn::make('balance')
                    ->label('Υπόλοιπο')
                    ->alignEnd()
                    ->money('EUR')
                    ->weight('bold')
                    ->color('success')
                    ->state(fn (Invoice $r) => round($r->balanceData()->balance, 2)),
            ])
            ->recordActions([
                Action::make('record_whmcs_payment')
                    ->label('Καταγραφή πληρωμής')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    // Money-write: same per-record gate as the invoice actions —
                    // ViewAny (page access) is not enough to record a payment.
                    ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Καταγραφή πληρωμής από το WHMCS')
                    ->modalDescription('Επιβεβαιώνεται ζωντανά στο WHMCS και, αν είναι πληρωμένο, κλείνει το υπόλοιπο καταγράφοντας πληρωμή στο ekdosi. Καμία αλλαγή στο WHMCS του πελάτη.')
                    ->modalSubmitActionLabel('Καταγραφή')
                    ->action(function (Invoice $record): void {
                        $tenant = $record->company;
                        $fetch = app(WhmcsInvoiceFetcher::class)->for($tenant);
                        if ($fetch === null) {
                            Notification::make()->title('Δεν έχει ρυθμιστεί WHMCS')->warning()->send();

                            return;
                        }
                        $amount = app(WhmcsPaymentSyncer::class)->syncInvoice($record, $fetch);
                        // Either way it's no longer actionable in the list.
                        WhmcsPaymentSyncCache::removeInbound($tenant, (int) $record->id);

                        if ($amount > 0.005) {
                            Notification::make()
                                ->title('Καταγράφηκε πληρωμή')
                                ->body('Εξοφλήθηκε — πληρωμή '.number_format($amount, 2, ',', '.').' €.')
                                ->success()->send();
                        } else {
                            Notification::make()
                                ->title('Δεν καταγράφηκε')
                                ->body('Το WHMCS δεν το επιστρέφει πλέον ως πληρωμένο (ή έκλεισε ήδη).')
                                ->info()->send();
                        }
                    }),
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r, 'tenant' => $r->company])),
            ])
            ->emptyStateHeading('Καμία εκκρεμότητα')
            ->emptyStateDescription('Κανένα ανοιχτό (επί πιστώσει) τιμολόγιο δεν φαίνεται πληρωμένο στο WHMCS. Πάτησε «Ανανέωση τώρα» για ζωντανό έλεγχο.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
