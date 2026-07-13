<?php

namespace App\Filament\PaymentSync;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Whmcs\PaymentPushResult;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentPusher;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * OUTBOUND list on «Συγχρονισμός πληρωμών» — invoices settled HERE (επί
 * πιστώσει, πληρωμένα στο ekdosi) whose WHMCS side hasn't been marked paid yet.
 * «Σήμανση Paid στο WHMCS» writes to the customer's WHMCS via WhmcsPaymentPusher
 * (opt-in / idempotent / anti-echo). Auto-push usually clears these; this list
 * is the manual/retry surface. Shown only to opted-in tenants.
 */
class WhmcsOutboundPaymentsTable extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) Filament::getTenant()?->whmcs_push_payments;
    }

    public function getTableHeading(): string
    {
        return 'Προς ενημέρωση στο WHMCS (εξοφλήθηκαν εδώ)';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return $table->query(Invoice::query()->whereRaw('1 = 0'));
        }

        $ids = WhmcsPaymentSyncCache::outboundIds($tenant);
        $query = $ids === []
            ? Invoice::query()->whereRaw('1 = 0')
            : Invoice::query()
                ->where('invoices.company_id', $tenant->id)
                ->whereIn('invoices.id', $ids)
                ->with(['customer', 'whmcsPending']);

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('invcode')
                    ->label('Παραστατικό')
                    ->color('primary')
                    ->url(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r, 'tenant' => $r->company])),
                TextColumn::make('customer.name')->label('Πελάτης')->limit(35)->placeholder('—'),
                TextColumn::make('whmcs')
                    ->label('WHMCS')
                    ->state(fn (Invoice $r) => ($w = $r->whmcsPending?->whmcs_invoice_id) ? '#'.$w : '—'),
                TextColumn::make('gross_total')->label('Σύνολο')->money('EUR')->alignEnd(),
            ])
            ->recordActions([
                Action::make('mark_paid_at_whmcs')
                    ->label('Σήμανση Paid στο WHMCS')
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Σήμανση πληρωμένου στο WHMCS')
                    ->modalDescription('Ενημερώνει το WHMCS του πελάτη ότι το τιμολόγιο εξοφλήθηκε (γράφει στο σύστημα του πελάτη, μία φορά).')
                    ->modalSubmitActionLabel('Σήμανση')
                    ->action(fn (Invoice $record) => $this->push($record)),
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Invoice $r) => InvoiceResource::getUrl('view', ['record' => $r, 'tenant' => $r->company])),
            ])
            ->emptyStateHeading('Καμία εκκρεμότητα')
            ->emptyStateDescription('Δεν υπάρχουν τοπικά εξοφλημένα τιμολόγια που να χρειάζονται ενημέρωση στο WHMCS.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    private function push(Invoice $record): void
    {
        $tenant = $record->company;
        $fetch = app(WhmcsInvoiceFetcher::class)->for($tenant);
        $push = app(WhmcsPaymentPusherFactory::class)->for($tenant);
        if ($fetch === null || $push === null) {
            Notification::make()->title('Δεν έχει ρυθμιστεί WHMCS')->warning()->send();

            return;
        }

        $result = app(WhmcsPaymentPusher::class)->push($record, $fetch, $push);

        if ($result === PaymentPushResult::Pushed || $result === PaymentPushResult::AlreadyPaid) {
            WhmcsPaymentSyncCache::removeOutbound($tenant, (int) $record->id);
        }

        match ($result) {
            PaymentPushResult::Pushed => Notification::make()->title('Ενημερώθηκε το WHMCS')->success()->send(),
            PaymentPushResult::AlreadyPaid => Notification::make()->title('Ήταν ήδη πληρωμένο')->info()->send(),
            PaymentPushResult::Failed => Notification::make()->title('Απέτυχε — δοκιμάστε ξανά')->danger()->persistent()->send(),
            PaymentPushResult::Skipped => Notification::make()->title('Δεν έγινε σήμανση')->warning()->send(),
        };
    }
}
