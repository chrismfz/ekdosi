<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AgedReceivables;
use App\Filament\Pages\MyDataConsole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * «Καθημερινές εργασίες» — a compact quick-launch panel of the operator's
 * most-frequent actions, pinned to the TOP of the dashboard (sort 0, above the
 * headline stats). Curated/static (no per-user config — quick by design; a
 * per-user «bookmarks» version is a deliberate follow-up, not built).
 *
 * Every button is gated on the SAME permission as its destination (canCreate /
 * canViewAny / page canAccess), so an operator only ever sees what they may
 * actually do — no dead links, no leaking a screen they can't open.
 */
class QuickActionsWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-actions';

    public static function canView(): bool
    {
        // Only inside a tenant (the URLs are tenant-scoped); hide otherwise.
        return Filament::getTenant() instanceof Company;
    }

    /**
     * The visible actions for this operator, in display order.
     *
     * @return list<array{label: string, url: string, icon: string, color: string, badge: ?int}>
     */
    public function actions(): array
    {
        $out = [];

        $add = function (bool $allowed, string $label, string $url, string $icon, string $color = 'gray', ?int $badge = null) use (&$out): void {
            if ($allowed) {
                $out[] = compact('label', 'url', 'icon', 'color', 'badge');
            }
        };

        // Δημιουργία — gated on canCreate().
        $add(InvoiceResource::canCreate(), 'Νέο Παραστατικό', InvoiceResource::getUrl('create'), 'heroicon-o-document-plus', 'primary');
        $add(PaymentResource::canCreate(), 'Νέα Είσπραξη', PaymentResource::getUrl('create'), 'heroicon-o-banknotes', 'primary');
        $add(QuoteResource::canCreate(), 'Νέα Προσφορά', QuoteResource::getUrl('create'), 'heroicon-o-document-text', 'primary');
        $add(CustomerResource::canCreate(), 'Νέος Πελάτης', CustomerResource::getUrl('create'), 'heroicon-o-user-plus', 'primary');

        // Καθημερινός έλεγχος — gated on canViewAny() / page canAccess().
        $add(WhmcsInboxResource::canViewAny(), 'WHMCS Εισερχόμενα', WhmcsInboxResource::getUrl('index'), 'heroicon-o-inbox-arrow-down', 'gray', $this->whmcsPending());
        $add(InvoiceResource::canViewAny(), 'Παραστατικά', InvoiceResource::getUrl('index'), 'heroicon-o-rectangle-stack', 'gray');
        $add(MyDataConsole::canAccess(), 'Κονσόλα myDATA', MyDataConsole::getUrl(), 'heroicon-o-cloud', 'gray');
        $add(AgedReceivables::canAccess(), 'Ηλικίωση οφειλών', AgedReceivables::getUrl(), 'heroicon-o-clock', 'gray');

        return $out;
    }

    /** Pending-review count for the WHMCS inbox badge (null when zero / no tenant). */
    private function whmcsPending(): ?int
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return null;
        }

        $count = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->count();

        return $count > 0 ? $count : null;
    }
}
