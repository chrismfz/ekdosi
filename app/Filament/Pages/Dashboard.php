<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Greek-titled dashboard. Widgets are auto-discovered from
 * app/Filament/Widgets (see AdminPanelProvider::discoverWidgets) and
 * ordered by each widget's $sort.
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
}
