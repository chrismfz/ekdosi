<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Ergani\CardKioskController;
use App\Models\Company;
use App\Models\WorkCardKioskDevice;
use App\Services\Ergani\WorkCardService;
use App\Support\MyData\QrImage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cookie;
use UnitEnum;

/**
 * «Σημείο κάρτας (QR)» — open it full-screen on the office tablet. It shows a
 * QR that changes every WorkCardService::KIOSK_WINDOW seconds; an employee
 * scans it with their phone (logged in to ekdosi) → «Κάρτα εργασίας» opens
 * with a short-lived token → the punch counts as made AT the office.
 * Admin-only (View:WorkCardKiosk).
 */
class WorkCardKiosk extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Σημείο κάρτας (QR)';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'card-kiosk';

    protected string $view = 'filament.pages.work-card-kiosk';

    public function getTitle(): string
    {
        return 'Σημείο κάρτας';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasErgani()
            && (bool) auth()->user()?->can('View:WorkCardKiosk');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getViewData(): array
    {
        $tenant = $this->tenantRecord();
        $url = WorkCard::getUrl(['k' => app(WorkCardService::class)->kioskToken($tenant)], tenant: $tenant);

        return [
            'qr' => QrImage::dataUri($url, 420),
            'company' => $tenant->name,
            'kioskUrl' => route('ergani.card-kiosk'),
            'devices' => WorkCardKioskDevice::query()
                ->where('company_id', $tenant->getKey())
                ->active()
                ->with('activatedBy:id,name')
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * Tablet setup: an admin logs in ON the tablet once, presses «Ενεργοποίηση
     * αυτής της συσκευής» (named device + httpOnly cookie with its own token) and
     * logs out — the tablet keeps showing /card-kiosk without any session. Each
     * device is listed and revocable on its own.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('activateDevice')
                ->label('Ενεργοποίηση αυτής της συσκευής')
                ->icon('heroicon-o-device-tablet')
                ->modalDescription('Κάντε το ΑΠΟ ΤΟ TABLET του γραφείου. Θα αποσυνδεθείτε αυτόματα — η συσκευή θα συνεχίσει να δείχνει μόνο το ρολόι/QR στο '.route('ergani.card-kiosk').', χωρίς λογαριασμό.')
                ->schema([
                    TextInput::make('name')->label('Όνομα συσκευής')->placeholder('π.χ. Tablet ρεσεψιόν')->required()->maxLength(80),
                ])
                ->modalSubmitActionLabel('Ενεργοποίηση & αποσύνδεση')
                ->action(function (array $data) {
                    [, $token] = WorkCardKioskDevice::activate($this->tenantRecord(), (string) $data['name'], (int) auth()->id());
                    Cookie::queue(CardKioskController::deviceCookie($token, request()->isSecure()));

                    // A wall tablet must never keep the admin's session: log out
                    // here, the device cookie alone keeps /card-kiosk working.
                    Filament::auth()->logout();
                    session()->invalidate();
                    session()->regenerateToken();

                    return redirect()->route('ergani.card-kiosk');
                }),
            Action::make('deactivateDevices')
                ->label('Απενεργοποίηση όλων')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => WorkCardKioskDevice::query()->where('company_id', $this->tenantRecord()->getKey())->active()->exists())
                ->requiresConfirmation()
                ->modalDescription('Όλες οι ενεργοποιημένες συσκευές σταματούν αμέσως να λειτουργούν ως σημείο κάρτας.')
                ->action(function (): void {
                    WorkCardKioskDevice::query()->where('company_id', $this->tenantRecord()->getKey())->active()->update(['revoked_at' => now()]);
                    Notification::make()->title('Όλες οι συσκευές απενεργοποιήθηκαν')->success()->send();
                }),
        ];
    }

    /** Revoke ONE device (e.g. a lost tablet) — rendered per row in the view. */
    public function revokeDeviceAction(): Action
    {
        return Action::make('revokeDevice')
            ->label('Απενεργοποίηση')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Απενεργοποίηση: '.(WorkCardKioskDevice::query()
                ->where('company_id', $this->tenantRecord()->getKey())->find($arguments['device'] ?? 0)?->name ?? ''))
            ->action(function (array $arguments): void {
                WorkCardKioskDevice::query()
                    ->where('company_id', $this->tenantRecord()->getKey())   // never another tenant's device
                    ->whereKey($arguments['device'] ?? 0)
                    ->update(['revoked_at' => now()]);
                Notification::make()->title('Η συσκευή απενεργοποιήθηκε')->success()->send();
            });
    }

    private function tenantRecord(): Company
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }
}
