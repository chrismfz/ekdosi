<?php

namespace App\Services\Hr;

use App\Enums\LeaveStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\CalendarFeed;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\OvertimeDeclaration;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Spatie\Permission\PermissionRegistrar;

/**
 * «Το ημερολόγιό μου» — the RFC 5545 (.ics) body of a user's feed: my leads'
 * next steps, my leaves (with type), colleagues' approved leaves (ONLY «Άδεια» —
 * the type, e.g. sickness = health data, never leaves ekdosi for an external
 * calendar, whoever subscribes), holidays, my overtime. Every section is gated
 * on the user's CURRENT rights in that company (re-checked on each fetch).
 * Window: 90 days back → 1 year ahead.
 */
class CalendarFeedBuilder
{
    public function build(CalendarFeed $feed): string
    {
        $company = $feed->company;
        $user = $feed->user;
        $from = CarbonImmutable::today()->subDays(90);
        $to = CarbonImmutable::today()->addYear();

        // Rights are team-scoped: evaluate them in THIS company.
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $employee = Employee::forUser($user, (int) $company->getKey());
        $canCalendar = $user->can('View:LeaveCalendar');            // colleagues' absences = what the panel grid shows
        $canLeaves = $canCalendar || $user->can('ViewAny:LeaveRequest');   // own leaves / overtime

        $events = [];
        if ($feed->include_leads && $user->can('ViewAny:Lead')) {
            $events = array_merge($events, $this->leads($company, $user, $from, $to));
        }
        if ($canLeaves && $company->hasErgani()) {
            if ($feed->include_leaves && $employee) {
                $events = array_merge($events, $this->myLeaves($employee, $from, $to));
            }
            if ($feed->include_team && $canCalendar) {
                $events = array_merge($events, $this->teamLeaves($company, $employee, $from, $to));
            }
            if ($feed->include_holidays) {
                $events = array_merge($events, $this->holidays($company, $from, $to));
            }
            if ($feed->include_overtime && $employee) {
                $events = array_merge($events, $this->overtime($employee, $from, $to));
            }
        }

        return $this->calendar($company, $events);
    }

    /** @return list<array<string, string>> */
    private function leads(Company $company, User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Lead::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('assigned_user_id', $user->getKey())
            ->open()
            ->whereNotNull('next_action_at')
            ->whereBetween('next_action_at', [$from, $to->endOfDay()])
            ->get()
            ->map(fn (Lead $l): array => [
                'uid' => 'lead-'.$l->getKey(),
                'start' => CarbonImmutable::parse($l->next_action_at)->utc()->format('Ymd\THis\Z'),
                'end' => CarbonImmutable::parse($l->next_action_at)->addMinutes(30)->utc()->format('Ymd\THis\Z'),
                'summary' => 'Lead: '.$l->name.' — επόμενο βήμα',
                'description' => trim(($l->contact_person ? 'Επαφή: '.$l->contact_person : '')),
                'url' => LeadResource::getUrl('edit', ['record' => $l], panel: 'admin', tenant: $company),
            ])->values()->all();
    }

    /** @return list<array<string, string>> */
    private function myLeaves(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LeaveRequest::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [LeaveStatus::Approved->value, LeaveStatus::Pending->value])
            ->overlapping($from, $to)
            ->get()
            ->map(fn (LeaveRequest $l): array => $this->allDay('leave-'.$l->getKey(), $l->starts_on, $l->ends_on,
                'Άδεια: '.$l->type?->getLabel().($l->isPending() ? ' (σε αναμονή)' : '')))
            ->values()->all();
    }

    /** Colleagues' APPROVED leaves — name only, never the type. @return list<array<string, string>> */
    private function teamLeaves(Company $company, ?Employee $me, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LeaveRequest::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('status', LeaveStatus::Approved->value)
            ->when($me !== null, fn ($q) => $q->where('employee_id', '!=', $me->getKey()))
            ->overlapping($from, $to)
            // Only the company filter is lifted (no ambient tenant here) — the soft-delete
            // scope stays, so ex-employees (deleted) drop out, like the panel grid.
            // (the relation itself is withTrashed() — say it explicitly)
            ->whereHas('employee', fn ($q) => $q->withoutGlobalScope(CompanyScope::class)->withoutTrashed())
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])
            ->get()
            ->map(fn (LeaveRequest $l): array => $this->allDay('team-leave-'.$l->getKey(), $l->starts_on, $l->ends_on,
                'Άδεια — '.$l->employee?->full_name))
            ->values()->all();
    }

    /** @return list<array<string, string>> */
    private function holidays(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendar = WorkingDays::for((int) $company->getKey());
        $out = [];
        foreach (range((int) $from->format('Y'), (int) $to->format('Y')) as $year) {
            foreach ($calendar->holidays($year) as $date => $name) {
                $d = CarbonImmutable::parse($date);
                if ($d->betweenIncluded($from, $to)) {
                    $out[] = $this->allDay('holiday-'.$d->format('Ymd'), $d, $d, 'Αργία: '.$name);
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private function overtime(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return OvertimeDeclaration::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', '!=', 'superseded'))
            ->get()
            ->map(fn (OvertimeDeclaration $o): array => [
                'uid' => 'overtime-'.$o->getKey(),
                'start' => $o->startsAt()->utc()->format('Ymd\THis\Z'),
                'end' => CarbonImmutable::parse($o->work_date->toDateString().' '.$o->to_time, 'Europe/Athens')->utc()->format('Ymd\THis\Z'),
                'summary' => 'Υπερωρία'.($o->ergani_status === 'submitted' ? '' : ' (όχι επιβεβαιωμένη στο ΕΡΓΑΝΗ)'),
                'description' => '',
                'url' => '',
            ])->values()->all();
    }

    /** @return array<string, string> all-day event, DTEND exclusive (RFC 5545) */
    private function allDay(string $uid, mixed $start, mixed $end, string $summary): array
    {
        return [
            'uid' => $uid,
            'start' => CarbonImmutable::parse($start)->format('Ymd'),
            'end' => CarbonImmutable::parse($end)->addDay()->format('Ymd'),
            'summary' => $summary,
            'description' => '',
            'url' => '',
            'allday' => '1',
        ];
    }

    /** @param list<array<string, string>> $events */
    private function calendar(Company $company, array $events): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'ekdosi';
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ekdosi//Το ημερολόγιό μου//EL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape('ekdosi — '.$company->name), 'X-WR-TIMEZONE:Europe/Athens',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
        ];
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e['uid'].'-c'.$company->getKey().'@'.$host;
            $lines[] = 'DTSTAMP:'.$stamp;
            if (($e['allday'] ?? '') === '1') {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e['start'];
                $lines[] = 'DTEND;VALUE=DATE:'.$e['end'];
                $lines[] = 'TRANSP:TRANSPARENT';
            } else {
                $lines[] = 'DTSTART:'.$e['start'];
                $lines[] = 'DTEND:'.$e['end'];
            }
            $lines[] = 'SUMMARY:'.self::escape($e['summary']);
            if ($e['description'] !== '') {
                $lines[] = 'DESCRIPTION:'.self::escape($e['description']);
            }
            if ($e['url'] !== '') {
                $lines[] = 'URL:'.$e['url'];
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn (string $l): string => self::fold($l), $lines))."\r\n";
    }

    /** RFC 5545 §3.3.11 TEXT escaping. */
    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', ''], $text);
    }

    /** RFC 5545 §3.1: lines longer than 75 octets are folded (CRLF + space), never inside a UTF-8 char. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $char;
        }

        return $out.($out === '' ? '' : "\r\n ").$current;
    }
}
