<?php

namespace App\Services\Assistant\Tools;

use App\Enums\LeadActivityType;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Leads\SalesActivityReport;
use App\Services\Leads\SalesOperatorRow;

/**
 * «Ασχολήθηκε κανείς με τα leads;» — ο σφυγμός του mini-CRM με μια ματιά:
 * πόσα ανοιχτά / νέα / ληξιπρόθεσμα, τι έκανε ΚΑΘΕ χειριστής στην περίοδο
 * (τηλέφωνα, emails, ραντεβού, προσφορές, μετατροπές), ποιος άνοιξε τα
 * τελευταία leads και πότε, και οι τελευταίες γραμμές χρονολογίου.
 *
 * Χτισμένο πάνω στο ΙΔΙΟ `SalesActivityReport` που τροφοδοτεί τη σελίδα
 * «Απολογισμός πωλήσεων», ώστε τα νούμερα να συμφωνούν πάντα με το panel.
 * Read-only, tenant-scoped, gated on ViewAny:Lead.
 */
class LeadsPulseTool implements AssistantTool
{
    /** Πόσες γραμμές χρονολογίου / νέα leads επιστρέφουμε το πολύ. */
    private const RECENT_LIMIT = 15;

    /** Το μεγαλύτερο παράθυρο που δέχεται ένας «σφυγμός» (βλ. run()). */
    private const MAX_DAYS = 90;

    public function name(): string
    {
        return 'leads_pulse';
    }

    public function description(): string
    {
        return 'Σφυγμός των leads (mini-CRM) της εταιρείας: πόσα ανοιχτά / νέα / ληξιπρόθεσμα / '
            .'αδρανή, τι έκανε κάθε χειριστής στην περίοδο (τηλέφωνα, emails, ραντεβού, προσφορές, '
            .'μετατροπές), ποιος άνοιξε τα τελευταία leads και πότε, και οι τελευταίες κινήσεις. '
            .'Για «ασχολήθηκε κανείς με τα leads;», «τι έγινε αυτή την εβδομάδα», «ποιος το άνοιξε». '
            .'Προαιρετικό `days` (προεπιλογή 7, μέγιστο 90).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'Πόσες ημέρες πίσω (προεπιλογή 7, μέγιστο 90).'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'ViewAny:Lead';
    }

    public function run(Company $tenant, array $input): array
    {
        // Capped at a quarter on purpose: the report reduces EVERY timeline row
        // of the window in PHP, and a «σφυγμός» never needs a year in memory to
        // print a handful of lines (a long report is the panel's CSV job).
        $days = max(1, min((int) ($input['days'] ?? 7), self::MAX_DAYS));
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $report = app(SalesActivityReport::class)->build($tenant, $from, $to);

        $base = fn () => Lead::query()->where('company_id', $tenant->getKey());

        return [
            'period' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'open' => $base()->open()->count(),
                'new_in_period' => $base()->whereBetween('created_at', [$from, $to])->count(),
                'overdue' => $base()->overdue()->count(),
                'due_today_or_earlier' => $base()->due()->count(),
                'stale' => $base()->stale(ListLeads::STALE_DAYS)->count(),
                'without_next_step' => $base()->open()->whereNull('next_action_at')->count(),
                'converted_in_period' => LeadActivity::query()
                    ->where('company_id', $tenant->getKey())
                    ->where('type', LeadActivityType::Converted->value)
                    ->whereBetween('happened_at', [$from, $to])
                    ->count(),
            ],
            // Did anyone actually work them? Zero rows across the board is the answer.
            'per_operator' => array_map(fn (SalesOperatorRow $row): array => [
                'operator' => $row->name,
                'new_leads' => $row->get('new_leads'),
                'calls' => $row->get('calls'),
                'calls_answered' => $row->get('calls_answered'),
                'emails' => $row->get('emails'),
                'emails_replied' => $row->get('emails_replied'),
                'meetings' => $row->get('meetings'),
                'quotes' => $row->get('quotes'),
                'conversions' => $row->get('conversions'),
                'lost' => $row->get('lost'),
                'open_now' => $row->get('open'),
            ], $report->operators),
            'funnel' => $report->funnel,
            'newest_leads' => $this->newestLeads($tenant),
            'recent_activity' => $report->log->take(self::RECENT_LIMIT)->map(fn (LeadActivity $row): array => [
                'at' => $row->happened_at?->toIso8601String(),
                'lead' => $row->lead?->name ?? ('#'.$row->lead_id),
                'lead_id' => $row->lead_id,
                'by' => $row->user?->name ?? 'Σύστημα',
                'what' => $row->type?->getLabel() ?? (string) $row->type,
                'outcome' => $row->outcomeLabel(),
                'note' => $row->body,
            ])->values()->all(),
        ];
    }

    /**
     * The most recently created leads with WHO created them. `leads` has no
     * «created_by» column — the author comes from the activity log's `created`
     * row (TracksActivity), which is the honest source.
     *
     * @return list<array<string, mixed>>
     */
    private function newestLeads(Company $tenant): array
    {
        $leads = Lead::query()
            ->where('company_id', $tenant->getKey())
            ->with('assignedTo:id,name')
            ->latest('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        if ($leads->isEmpty()) {
            return [];
        }

        $creators = Activity::query()
            ->where('company_id', $tenant->getKey())
            ->where('subject_type', Lead::class)
            ->whereIn('subject_id', $leads->pluck('id'))
            ->where('event', 'created')
            ->with('causer')
            ->get()
            ->keyBy('subject_id');

        return $leads->map(fn (Lead $lead): array => [
            'id' => $lead->id,
            'name' => $lead->name,
            'status' => $lead->status?->getLabel(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'created_by' => $creators->get($lead->id)?->causer?->name ?? 'άγνωστο (πριν το ιστορικό ή από σύστημα)',
            'assigned_to' => $lead->assignedTo?->name,
            'next_action_at' => $lead->next_action_at?->toIso8601String(),
            'last_activity_at' => $lead->last_activity_at?->toIso8601String(),
        ])->all();
    }
}
