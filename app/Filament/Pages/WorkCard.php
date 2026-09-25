<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkCardEvent;
use App\Services\Ergani\WorkCardRefused;
use App\Services\Ergani\WorkCardService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * «Κάρτα εργασίας» — the employee's own punch screen (operator or the
 * leave-only `ergani` role): one big «Είσοδος / Έξοδος» button (the next
 * movement is derived from the last one) + today's movements and their
 * ΕΡΓΑΝΗ state. Opened from the office QR (?k=token) the punch is recorded as
 * «QR γραφείου» (proof of presence); a tenant may require that.
 */
class WorkCard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Χτύπημα κάρτας';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'work-card';

    protected string $view = 'filament.pages.work-card';

    /** Kiosk token from the office QR. */
    #[Url(as: 'k')]
    public ?string $kiosk = null;

    public function getTitle(): string
    {
        return 'Κάρτα εργασίας';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasErgani()
            && (bool) auth()->user()?->can('View:WorkCard');
    }

    /** In the menu only for people who have an employee record (admins without one don't punch). */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess()
            && Employee::forUser(auth()->user(), (int) Filament::getTenant()->getKey()) !== null;
    }

    protected function getViewData(): array
    {
        $employee = $this->employee();
        $next = $employee ? app(WorkCardService::class)->nextType($employee) : null;

        return [
            'employee' => $employee,
            'next' => $next,
            // «Μέσα από 09:02» — the open «in» (can be yesterday's, a night shift).
            'inSince' => $next === WorkCardEvent::OUT ? WorkCardEvent::query()
                ->where('employee_id', $employee->getKey())->where('type', WorkCardEvent::IN)
                ->latest('occurred_at')->value('occurred_at') : null,
            // A QR link whose token has rotated (the photo/tab is older than ~1').
            'qrExpired' => filled($this->kiosk) && ! $this->viaKiosk(),
            'today' => $employee ? $this->todayEvents($employee) : collect(),
            'viaKiosk' => $this->viaKiosk(),
            'kioskRequired' => (bool) $this->tenant()->ergani_card_requires_kiosk,
            'submits' => WorkCardService::enabledFor($this->tenant()) && (bool) $employee?->has_work_card,
        ];
    }

    public function punchAction(): Action
    {
        return Action::make('punch')
            ->label(fn (): string => ($e = $this->employee()) && app(WorkCardService::class)->nextType($e) === WorkCardEvent::OUT ? 'Έξοδος' : 'Είσοδος')
            ->icon('heroicon-o-finger-print')
            ->size('xl')
            ->color(fn (): string => ($e = $this->employee()) && app(WorkCardService::class)->nextType($e) === WorkCardEvent::OUT ? 'danger' : 'success')
            ->visible(fn (): bool => $this->employee() !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Επιβεβαίωση: '.(($e = $this->employee()) && app(WorkCardService::class)->nextType($e) === WorkCardEvent::OUT ? 'ΕΞΟΔΟΣ' : 'ΕΙΣΟΔΟΣ').' '.now()->format('H:i'))
            ->modalDescription(fn (): string => WorkCardService::enabledFor($this->tenant()) && (bool) $this->employee()?->has_work_card
                ? 'Η κίνηση δηλώνεται αμέσως στο ΕΡΓΑΝΗ και ΔΕΝ ανακαλείται — βεβαιωθείτε ότι είναι σωστή.'
                : 'Η κίνηση καταγράφεται με την τρέχουσα ώρα.')
            ->modalSubmitActionLabel('Καταχώριση')
            // Bind the confirmation to the movement the modal SHOWED (another tab /
            // a re-scan may have punched meanwhile).
            ->fillForm(fn (): array => ['seen_type' => ($e = $this->employee()) ? app(WorkCardService::class)->nextType($e) : null])
            ->schema([Hidden::make('seen_type')])
            ->action(function (array $data): void {
                $employee = $this->employee();
                if ($employee === null) {
                    return;
                }
                try {
                    $event = app(WorkCardService::class)->punch($employee, $this->viaKiosk() ? 'kiosk' : 'self', (int) auth()->id(),
                        in_array($data['seen_type'] ?? null, [WorkCardEvent::IN, WorkCardEvent::OUT], true) ? $data['seen_type'] : null);
                } catch (WorkCardRefused $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title('Αβέβαιο αποτέλεσμα — η κίνηση ίσως καταγράφηκε. Ενημερώστε τον διαχειριστή (Προσωπικό → Κινήσεις κάρτας) πριν ξαναχτυπήσετε.')->danger()->persistent()->send();

                    return;
                }

                // The QR proved presence for THIS punch; the token rotates within the
                // minute, so drop it (no stale «QR έληξε», next punch re-scans).
                $this->kiosk = null;

                $title = $event->typeLabel().' '.$event->occurred_at->format('H:i').' καταχωρήθηκε';
                match ($event->ergani_status) {
                    'submitted' => Notification::make()->title($title)->body('ΕΡΓΑΝΗ: πρωτ. '.$event->ergani_protocol.($event->ergani_env === 'trial' ? ' (δοκιμαστικό)' : ''))->success()->send(),
                    'failed', 'unknown' => Notification::make()->title($title.' — δεν δηλώθηκε στο ΕΡΓΑΝΗ')->body($event->ergani_error.' Η κίνηση κρατήθηκε και ο διαχειριστής ειδοποιήθηκε — μην ξαναχτυπήσετε.')->danger()->persistent()->send(),
                    default => Notification::make()->title($title)->success()->send(),
                };
            });
    }

    /** Per-request memo (label/colour/heading/form all ask) — protected props aren't dehydrated. */
    protected ?Employee $employeeMemo = null;

    protected bool $employeeResolved = false;

    protected function employee(): ?Employee
    {
        if (! $this->employeeResolved) {
            $this->employeeMemo = Employee::forUser(auth()->user(), (int) $this->tenant()->getKey());
            $this->employeeResolved = true;
        }

        return $this->employeeMemo;
    }

    private function viaKiosk(): bool
    {
        return app(WorkCardService::class)->kioskTokenValid($this->tenant(), $this->kiosk);
    }

    /** @return Collection<int, WorkCardEvent> */
    private function todayEvents(Employee $employee): Collection
    {
        return WorkCardEvent::query()
            ->where('employee_id', $employee->getKey())
            ->where('occurred_at', '>=', now()->startOfDay())
            ->orderBy('occurred_at')
            ->get();
    }

    private function tenant(): Company
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }
}
