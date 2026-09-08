<?php

namespace App\Services\Leads;

use App\Models\LeadActivity;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Output of SalesActivityReport::build() — one period, per-operator rows,
 * the status funnel and the day log. Plain value object for the page + CSV.
 */
final class SalesActivityResult
{
    /** Counter keys, in the column order of the report (label => key). */
    public const COLUMNS = [
        'new_leads' => 'Νέα leads',
        'calls' => 'Τηλέφωνα',
        'calls_answered' => '…απάντησαν',
        'emails' => 'Emails',
        'emails_replied' => '…απάντησαν',
        'meetings' => 'Ραντεβού',
        'meetings_held' => '…έγιναν',
        'quotes' => 'Προσφορές',
        'conversions' => 'Μετατροπές',
        'lost' => 'Χάθηκαν',
        'open' => 'Ανοιχτά (τώρα)',
    ];

    /**
     * @param  list<SalesOperatorRow>  $operators
     * @param  array<string, int>  $funnel  status value => count (snapshot)
     * @param  Collection<int, LeadActivity>  $log  ALL the period's rows, newest first (the CSV)
     */
    public function __construct(
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly array $operators,
        public readonly array $funnel,
        public readonly Collection $log,
    ) {}

    /**
     * What the page renders: the first SalesActivityReport::LOG_LIMIT rows.
     *
     * @return Collection<int, LeadActivity>
     */
    public function logPreview(): Collection
    {
        return $this->log->take(SalesActivityReport::LOG_LIMIT)->values();
    }

    public function logTruncated(): bool
    {
        return $this->log->count() > SalesActivityReport::LOG_LIMIT;
    }

    /** @return array<string, int> */
    public static function emptyCounters(): array
    {
        return array_fill_keys(array_keys(self::COLUMNS), 0);
    }

    /** Column totals across operators. @return array<string, int> */
    public function totals(): array
    {
        $totals = self::emptyCounters();
        foreach ($this->operators as $row) {
            foreach ($row->counters as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return $totals;
    }

    public function periodLabel(): string
    {
        return $this->from->isSameDay($this->to)
            ? $this->from->format('d/m/Y')
            : $this->from->format('d/m/Y').' – '.$this->to->format('d/m/Y');
    }
}
