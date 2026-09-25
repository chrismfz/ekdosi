<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Support\Hr\ErganiStaff;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Greek-titled dashboard. Widgets are auto-discovered from
 * app/Filament/Widgets (see AdminPanelProvider::discoverWidgets) and
 * ordered by each widget's $sort.
 *
 * There is no period filter: the two charts are self-anchored on "now"
 * (trailing 12 months / this year vs last), and the headline cards encode
 * their own comparison windows — so a dashboard-wide period selector had
 * nothing meaningful left to drive and was removed.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return 'Πίνακας ελέγχου';
    }

    public static function getNavigationLabel(): string
    {
        return 'Πίνακας ελέγχου';
    }

    /** The money widgets are ungated by design — never for the leave-only staff role. */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return ! ($tenant instanceof Company && ErganiStaff::isRestricted(auth()->user(), $tenant));
    }
}
