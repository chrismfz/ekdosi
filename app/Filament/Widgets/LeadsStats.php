<?php

namespace App\Filament\Widgets;

use App\Enums\LeadActivityType;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Quote;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Leads L2 — the small dashboard card (docs/archive/leads-mini-crm.md §6): open leads,
 * overdue next steps, stale ones and this month's conversions, each linking to
 * the matching list tab. Only for users who may see leads (ViewAny:Lead).
 */
class LeadsStats extends StatsOverviewWidget
{
    // After the money + services headline cards.
    protected static ?int $sort = 3;

    protected ?string $heading = 'Leads';

    // Four COUNTs per refresh — 60s like the neighbouring cards, not Filament's 5s default.
    protected ?string $pollingInterval = '60s';

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

        // #8: αξία pipeline = Σ μικτής αξίας της ΤΕΛΕΥΤΑΙΑΣ ζωντανής προσφοράς
        // (draft/sent/accepted — όχι rejected/expired) ΑΝΑ ανοιχτό lead. Τα leads
        // δεν έχουν δικό τους πεδίο αξίας — η προσφορά είναι η ποσοτικοποίηση.
        // Μία-ανά-lead (η τρέχουσα προσφορά): έτσι μια αναθεωρημένη προσφορά που
        // δεν απορρίφθηκε δεν διπλομετράει το ίδιο deal· lead χωρίς ζωντανή
        // προσφορά δεν προσμετράται.
        $pipeline = Quote::query()
            ->where('company_id', $tenant->id)
            ->whereNotIn('status', [QuoteStatus::Rejected->value, QuoteStatus::Expired->value])
            ->whereHas('lead', fn ($q) => $q->open())
            ->get(['lead_id', 'gross_total', 'created_at', 'id'])
            ->groupBy('lead_id')
            ->sum(fn ($quotes): float => (float) $quotes
                ->sortByDesc(fn ($q): array => [$q->created_at?->getTimestamp() ?? 0, $q->id])
                ->first()->gross_total);

        // ListRecords binds its active tab to `?tab=` (#[Url(as: 'tab')]).
        $url = fn (string $tab): string => LeadResource::getUrl('index', ['tab' => $tab]);

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

            Stat::make('Αξία pipeline', Money::eur($pipeline))
                ->description('Μικτή αξία προσφορών ανοιχτών leads')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary')
                ->url($url('open')),
        ];
    }
}
