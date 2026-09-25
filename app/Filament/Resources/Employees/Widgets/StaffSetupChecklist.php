<?php

namespace App\Filament\Resources\Employees\Widgets;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\WorkCardKiosk;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkCardKioskDevice;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * «Ξεκίνημα Προσωπικού» — first-run checklist on the Εργαζόμενοι list: what is
 * still missing for leaves (and the card) to work end-to-end. When every step
 * is done it stays, collapsed, as «✓ όλα έτοιμα» (a reference, not a nag).
 * Read-only; links only to screens the viewer can open.
 */
class StaffSetupChecklist extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.staff-setup-checklist';

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && $tenant->hasErgani();
    }

    /** @return list<array{done: bool, title: string, hint: string, url: ?string, optional: bool}> */
    protected function steps(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $employees = Employee::query()->where('company_id', $company->getKey())->where('is_active', true);
        $active = (clone $employees)->count();
        $settingsUrl = CompanySettings::canAccess() ? CompanySettings::getUrl() : null;

        $steps = [
            [
                'done' => filled($company->ergani_username) && filled($company->ergani_password),
                'title' => 'Σύνδεση με το ΕΡΓΑΝΗ',
                'hint' => 'Κωδικοί e-ΕΦΚΑ στην Εταιρεία → καρτέλα «ΕΡΓΑΝΗ» (super admin) και «Test σύνδεσης».',
                'url' => null,
                'optional' => ! $company->ergani_submit_leaves && ! $company->ergani_submit_cards,
            ],
            [
                'done' => filled($company->leave_notify_email),
                'title' => 'Email λογιστή',
                'hint' => 'Για να ενημερώνεται αυτόματα για κάθε εγκεκριμένη/ακυρωμένη άδεια.',
                'url' => $settingsUrl,
                'optional' => false,
            ],
            [
                'done' => $active > 0,
                'title' => 'Εργαζόμενοι',
                'hint' => 'Προσθέστε όλους με ΑΦΜ όπως στο ΕΡΓΑΝΗ και τις ημέρες κανονικής άδειας.',
                'url' => null,
                'optional' => false,
            ],
            [
                'done' => $active > 0 && (clone $employees)->whereNotNull('user_id')->exists(),
                'title' => 'Λογαριασμοί εργαζομένων',
                'hint' => 'Για να ζητούν μόνοι τους άδειες: πατήστε «Σύνδεση» όπου η στήλη «Λογαριασμός» γράφει «Πρόταση», ή διαλέξτε λογαριασμό μέσα στην καρτέλα.',
                'url' => null,
                'optional' => true,
            ],
        ];

        if ($company->ergani_submit_cards) {
            $withoutPin = (clone $employees)->where('has_work_card', true)->whereNull('card_pin_hash')->count();
            $steps[] = [
                'done' => (clone $employees)->where('has_work_card', true)->exists() && $withoutPin === 0,
                'title' => 'Ψηφιακή κάρτα',
                'hint' => $withoutPin > 0
                    ? $withoutPin.' εργαζόμενος/οι με κάρτα χωρίς PIN ρολογιού.'
                    : 'Σημειώστε «Ψηφιακή κάρτα» και ορίστε PIN σε όσους έχει δηλώσει ο λογιστής με κάρτα.',
                'url' => null,
                'optional' => false,
            ];
            $steps[] = [
                'done' => WorkCardKioskDevice::query()->where('company_id', $company->getKey())->whereNull('revoked_at')->exists(),
                'title' => 'Tablet γραφείου',
                'hint' => 'Ενεργοποιήστε ένα tablet ως «ρολόι» (Σημείο κάρτας) — χτύπημα με όνομα + PIN, χωρίς κινητό.',
                'url' => WorkCardKiosk::canAccess() ? WorkCardKiosk::getUrl() : null,
                'optional' => true,
            ];
        }

        return $steps;
    }

    protected function getViewData(): array
    {
        $steps = $this->steps();

        return [
            'steps' => $steps,
            'complete' => collect($steps)->every(fn (array $s): bool => $s['done']),
        ];
    }
}
