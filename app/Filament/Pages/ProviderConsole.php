<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\MyDataMark;
use App\Services\EInvoice\ProviderPreflight;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Support\EInvoice\ProviderCredentials;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;
use UnitEnum;

/**
 * «Πάροχος — Έλεγχος» (P4): the read-only verification surface for a ΥΠΑΗΕΣ provider
 * tenant. Shows the readiness preflight (ProviderPreflight, no network) + the recent
 * provider submissions (mydata_marks with a provider_key) + a reachability «Έλεγχος
 * σύνδεσης» action. Filing itself stays on the invoice lifecycle; this is the
 * "is everything wired and did it land" console.
 *
 * Visible only for gr-provider tenants. No secrets are rendered (the preflight
 * reports counts, never credential values).
 */
class ProviderConsole extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = 'myDATA & Διασυνδέσεις';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Πάροχος';

    protected string $view = 'filament.pages.provider-console';

    public function getTitle(): string
    {
        return 'Πάροχος — Έλεγχος';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        // Admin-only, like MyDataConsole — canAccess() is the route-level guard, so
        // a non-permitted user can't reach it by hand-typing the URL either. Uses
        // Gate::can (→ false on a missing permission, never a 404-storm throw).
        return $tenant instanceof Company
            && $tenant->einvoice_provider === 'gr-provider'
            && (bool) auth()->user()?->can('View:ProviderConsole');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ping')
                ->label('Έλεγχος σύνδεσης')
                ->icon('heroicon-o-bolt')
                ->action(function () {
                    /** @var Company $tenant */
                    $tenant = Filament::getTenant();
                    $transport = app(ProviderTransportRegistry::class)->for((string) $tenant->einvoice_provider_key);

                    if ($transport instanceof NullProviderTransport) {
                        Notification::make()
                            ->title('Ο πάροχος δεν έχει ενεργοποιηθεί ακόμη')
                            ->body('Η τεχνική σύνδεση ενεργοποιείται σε επόμενη έκδοση.')
                            ->warning()->send();

                        return;
                    }

                    try {
                        $ok = $transport->ping(ProviderCredentials::fromCompany($tenant));
                    } catch (Throwable $e) {
                        Notification::make()->title('Αποτυχία σύνδεσης με τον πάροχο')->body($e->getMessage())->warning()->send();

                        return;
                    }

                    $ok
                        ? Notification::make()->title('Ο πάροχος είναι προσβάσιμος')->body('Τα διαπιστευτήρια επαληθεύονται οριστικά στην πρώτη πραγματική αποστολή.')->success()->send()
                        : Notification::make()->title('Ο πάροχος απέρριψε τα στοιχεία')->danger()->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return [
            'tenant' => $tenant,
            'checks' => app(ProviderPreflight::class)->audit($tenant),
            'marks' => MyDataMark::query()
                ->where('company_id', $tenant->id)
                ->whereNotNull('provider_key')
                ->latest('id')
                ->limit(20)
                ->get(),
        ];
    }
}
