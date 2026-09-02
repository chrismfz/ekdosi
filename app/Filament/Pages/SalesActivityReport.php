<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Leads\SalesActivityReport as ReportBuilder;
use App\Services\Leads\SalesActivityResult;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Απολογισμός πωλήσεων» — Leads L2 (docs/leads-mini-crm.md §7): per-operator
 * activity counts for a period (calls/emails/meetings/quotes/conversions), the
 * status funnel and the day log, with a CSV export. Read-only, built on
 * App\Services\Leads\SalesActivityReport. Gated on View:SalesActivityReport
 * (company_admin by default — run shield:generate after deploy).
 */
class SalesActivityReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Απολογισμός πωλήσεων';

    // Right after the Leads resource (which sits at -1 among the party resources).
    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.sales-activity-report';

    /** Quick-period preset (today / week / last_week / month / last_month / custom). */
    public string $period = 'week';

    public ?string $from = null;

    public ?string $to = null;

    /** Operator filter (user id as string for the select), '' = everyone. */
    public string $operator = '';

    private ?SalesActivityResult $result = null;

    /** @var array<int, string>|null */
    private ?array $operatorOptions = null;

    public function getTitle(): string
    {
        return 'Απολογισμός πωλήσεων';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:SalesActivityReport');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->applyPeriod();
    }

    public function updatedPeriod(): void
    {
        $this->applyPeriod();
    }

    public function updatedFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedTo(): void
    {
        $this->period = 'custom';
    }

    /** Resolve the preset into from/to (ISO week = Δευτέρα–Κυριακή). */
    private function applyPeriod(): void
    {
        $now = now();

        if ($this->period === 'custom') {
            $this->from ??= $now->copy()->startOfWeek()->toDateString();
            $this->to ??= $now->copy()->endOfWeek()->toDateString();

            return;
        }

        [$from, $to] = match ($this->period) {
            'today' => [$now->copy(), $now->copy()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [
                $now->copy()->subMonthsNoOverflow(1)->startOfMonth(),
                $now->copy()->subMonthsNoOverflow(1)->endOfMonth(),
            ],
            default => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()], // week
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /**
     * Operator picker options: the tenant's users.
     *
     * @return array<int, string>
     */
    public function getOperatorOptions(): array
    {
        if ($this->operatorOptions !== null) {
            return $this->operatorOptions;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->operatorOptions = $tenant->users()->orderBy('name')->pluck('users.name', 'users.id')->all();
    }

    /** Memoised per request. A malformed date falls back to the current week. */
    public function getResult(): SalesActivityResult
    {
        if ($this->result !== null) {
            return $this->result;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        try {
            $from = Carbon::parse($this->from ?: 'monday this week');
            $to = Carbon::parse($this->to ?: 'sunday this week');
        } catch (\Throwable) {
            $from = now()->startOfWeek();
            $to = now()->endOfWeek();
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $operator = ctype_digit($this->operator) ? (int) $this->operator : null;
        // Never a user outside the tenant (a crafted id would otherwise scope
        // the report to a stranger — harmless data-wise, but wrong).
        if ($operator !== null && ! array_key_exists($operator, $this->getOperatorOptions())) {
            $operator = null;
        }

        return $this->result = app(ReportBuilder::class)->build($tenant, $from, $to, $operator);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Εξαγωγή CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $result = $this->getResult();
        $name = 'apologismos-poliseon-'.$result->from->format('Y-m-d').'_'.$result->to->format('Y-m-d').'.csv';
        // Neutralise CSV formula injection in free text (=,+,-,@ → quote).
        $safe = fn (?string $v): string => (($v = (string) $v) !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;

        return response()->streamDownload(function () use ($result, $safe): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Περίοδος', $result->periodLabel()], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Χειριστής', ...array_values(SalesActivityResult::COLUMNS)], ';', escape: '');
            foreach ($result->operators as $row) {
                fputcsv($h, [$safe($row->name), ...array_values($row->counters)], ';', escape: '');
            }
            fputcsv($h, ['Σύνολα', ...array_values($result->totals())], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Ημερολόγιο', 'Πότε', 'Χειριστής', 'Lead', 'Τύπος', 'Κατεύθυνση', 'Αποτέλεσμα', 'Κείμενο'], ';', escape: '');
            foreach ($result->log as $row) {
                fputcsv($h, [
                    '',
                    $row->happened_at?->format('d/m/Y H:i') ?? '',
                    $safe($row->user?->name ?? 'Σύστημα'),
                    $safe($row->lead?->name ?? '#'.$row->lead_id),
                    $row->type?->getLabel() ?? (string) $row->type,
                    $row->directionLabel() ?? '',
                    $row->outcomeLabel() ?? '',
                    $safe($row->body),
                ], ';', escape: '');
            }
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
