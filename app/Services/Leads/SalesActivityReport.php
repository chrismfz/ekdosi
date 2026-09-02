<?php

namespace App\Services\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * «Απολογισμός πωλήσεων» — «δούλεψε ο άνθρωπος;» (docs/leads-mini-crm.md §7).
 *
 * Read-only aggregates over `lead_activities` + `leads` for a period, per
 * operator: new leads, calls (answered), emails (replied), meetings (held),
 * quotes, conversions, lost — plus the operator's OPEN leads right now (a
 * snapshot, not a period figure), a funnel (leads per status, snapshot) and
 * the day log (every timeline row of the period, newest first, capped).
 *
 * The timeline is small per tenant (a handful of rows per lead), so the
 * period's rows are read once and reduced in PHP — one query, no JSON-path
 * SQL for the status-change «to» (portable across MariaDB/sqlite). No new
 * table, nothing written.
 */
class SalesActivityReport
{
    /** Day-log cap: the page is a report, not a browser of the whole history. */
    public const LOG_LIMIT = 300;

    public function build(Company $company, CarbonInterface $from, CarbonInterface $to, ?int $userId = null): SalesActivityResult
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $rows = LeadActivity::query()
            ->where('lead_activities.company_id', $company->id)
            ->whereBetween('happened_at', [$from, $to])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->with(['lead:id,name', 'user:id,name'])
            ->get(['id', 'lead_id', 'user_id', 'type', 'direction', 'outcome', 'happened_at', 'body', 'meta']);

        /** @var array<int|string, array<string, int>> $per */
        $per = [];
        $bump = function (?int $uid, string $key) use (&$per): void {
            $k = $uid ?? 0;
            $per[$k] ??= SalesActivityResult::emptyCounters();
            $per[$k][$key]++;
        };

        foreach ($rows as $row) {
            $uid = $row->user_id !== null ? (int) $row->user_id : null;
            switch ($row->type) {
                case LeadActivityType::Call:
                    $bump($uid, 'calls');
                    if ($row->outcome === 'answered') {
                        $bump($uid, 'calls_answered');
                    }
                    break;
                case LeadActivityType::Email:
                    $bump($uid, 'emails');
                    if ($row->outcome === 'replied') {
                        $bump($uid, 'emails_replied');
                    }
                    break;
                case LeadActivityType::Meeting:
                    $bump($uid, 'meetings');
                    if ($row->outcome === 'held') {
                        $bump($uid, 'meetings_held');
                    }
                    break;
                case LeadActivityType::Quote:
                    $bump($uid, 'quotes');
                    break;
                case LeadActivityType::Converted:
                    $bump($uid, 'conversions');
                    break;
                case LeadActivityType::StatusChange:
                    if (($row->meta['to'] ?? null) === LeadStatus::Lost->value) {
                        $bump($uid, 'lost');
                    }
                    break;
                default:
                    break;
            }
        }

        // New leads in the period, by the operator they are assigned to
        // (a lead carries no «created by» — assignment is the ownership).
        $newLeads = Lead::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$from, $to])
            ->when($userId !== null, fn ($q) => $q->where('assigned_user_id', $userId))
            ->groupBy('assigned_user_id')
            ->pluck(DB::raw('count(*) as c'), 'assigned_user_id');
        foreach ($newLeads as $uid => $count) {
            $k = $uid === null ? 0 : (int) $uid;
            $per[$k] ??= SalesActivityResult::emptyCounters();
            $per[$k]['new_leads'] = (int) $count;
        }

        // Open leads NOW per operator (snapshot).
        $openLeads = Lead::query()
            ->where('company_id', $company->id)
            ->open()
            ->when($userId !== null, fn ($q) => $q->where('assigned_user_id', $userId))
            ->groupBy('assigned_user_id')
            ->pluck(DB::raw('count(*) as c'), 'assigned_user_id');
        foreach ($openLeads as $uid => $count) {
            $k = $uid === null ? 0 : (int) $uid;
            $per[$k] ??= SalesActivityResult::emptyCounters();
            $per[$k]['open'] = (int) $count;
        }

        // Operator names: the tenant's users (a user who left keeps their rows
        // under their name if still resolvable, else «#id»).
        $names = User::query()
            ->whereIn('id', array_filter(array_keys($per), fn ($k): bool => $k !== 0))
            ->pluck('name', 'id')
            ->all();

        $operators = [];
        foreach ($per as $uid => $counters) {
            $operators[] = new SalesOperatorRow(
                userId: $uid === 0 ? null : (int) $uid,
                name: $uid === 0 ? '— χωρίς χειριστή —' : ($names[$uid] ?? '#'.$uid),
                counters: $counters,
            );
        }
        usort($operators, fn (SalesOperatorRow $a, SalesOperatorRow $b): int => [$a->userId === null, $a->name] <=> [$b->userId === null, $b->name]);

        // Funnel (snapshot of every lead of the tenant, or of the operator).
        $funnel = array_fill_keys(array_map(fn (LeadStatus $s): string => $s->value, LeadStatus::cases()), 0);
        $byStatus = Lead::query()
            ->where('company_id', $company->id)
            ->when($userId !== null, fn ($q) => $q->where('assigned_user_id', $userId))
            ->groupBy('status')
            ->pluck(DB::raw('count(*) as c'), 'status');
        foreach ($byStatus as $status => $count) {
            $funnel[(string) $status] = (int) $count;
        }

        return new SalesActivityResult(
            from: $from,
            to: $to,
            operators: $operators,
            funnel: $funnel,
            log: $rows->take(self::LOG_LIMIT)->values(),
            logTruncated: $rows->count() > self::LOG_LIMIT,
        );
    }
}
