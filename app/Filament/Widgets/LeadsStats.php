<?php

namespace App\Filament\Widgets;

use App\Enums\LeadActivityType;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Leads L2 — the small dashboard card (docs/leads-mini-crm.md §6): open leads,
 * overdue next steps, stale ones and this month's conversions, each linking to
 * the matching list tab. Only for users who may see leads (ViewAny:Lead).
 */
class LeadsStats extends StatsOverviewWidget
{
    // After the money + services headline cards.
    protected static ?int $sort = 3;

    protected ?string $heading = 'Leads';

    public static function canView(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('ViewAny:Lead');
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $base = fn () => Lead::query()->where('company_id', $tenant->id);
        $open = $base()->open()->count();
        $overdue = $base()->overdue()->count();
        $stale = $base()->stale(ListLeads::STALE_DAYS)->count();
        $converted = LeadActivity::query()
            ->where('company_id', $tenant->id)
            ->where('type', LeadActivityType::Converted->value)
            ->whereBetween('happened_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $url = fn (string $tab): string => LeadResource::getUrl('index', ['activeTab' => $tab]);

        return [
            Stat::make('Ανοιχτά leads', (string) $open)
                ->description('Σε εξέλιξη')
                ->descriptionIcon('heroicon-m-funnel')
                ->color('primary')
                ->url($url('open')),

            Stat::make('Ληξιπρόθεσμα βήματα', (string) $overdue)
                ->description($stale > 0 ? "{$stale} αδρανή (χωρίς επαφή ".ListLeads::STALE_DAYS.' ημ.)' : 'Πέρασε το επόμενο βήμα')
                ->descriptionIcon($overdue > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdue > 0 ? 'danger' : ($stale > 0 ? 'warning' : 'success'))
                ->url($url($overdue > 0 ? 'overdue' : 'stale')),

            Stat::make('Μετατροπές μήνα', (string) $converted)
                ->description('Leads που έγιναν πελάτες')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->url($url('won')),
        ];
    }
}
