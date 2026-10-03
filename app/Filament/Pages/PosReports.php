<?php

namespace App\Filament\Pages;

use App\Http\Controllers\PosSessionReportController;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PosSession;
use App\Models\Scopes\CompanyScope;
use App\Services\Pos\PosReports as ReportBuilder;
use App\Services\Pos\TillSessions;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Αναφορές Ταμείου» — the back-office view of the till (company_admin / super_admin:
 * View:PosReports): a period's sales/returns per day, payment method, VAT rate and
 * CASHIER, and every till session with its count (expand one: its documents, cash
 * movements, the report). Read-only; built on App\Services\Pos\PosReports. Same
 * period/filter shape as «Απολογισμός πωλήσεων».
 */
class PosReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?string $navigationLabel = 'Αναφορές ταμείου';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.pos-reports';

    /** today / week / last_week / month / last_month / custom */
    public string $period = 'today';

    public ?string $from = null;

    public ?string $to = null;

    /** Cashier filter (user id as string), '' = everyone. */
    public string $cashier = '';

    /** The till session whose documents are shown (expanded row). */
    public ?int $openSession = null;

    private ?array $result = null;

    public function getTitle(): string
    {
        return 'Αναφορές ταμείου';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasPos()
            && (bool) auth()->user()?->can('View:PosReports');
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

    public function toggleSession(int $sessionId): void
    {
        $this->openSession = $this->openSession === $sessionId ? null : $sessionId;
    }

    private function applyPeriod(): void
    {
        $now = now();
        if ($this->period === 'custom') {
            $this->from ??= $now->toDateString();
            $this->to ??= $now->toDateString();

            return;
        }

        [$from, $to] = match ($this->period) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthsNoOverflow(1)->startOfMonth(), $now->copy()->subMonthsNoOverflow(1)->endOfMonth()],
            default => [$now->copy(), $now->copy()],   // today
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** @return array{0: Carbon, 1: Carbon} the period (a malformed date → today; reversed → swapped) */
    private function range(): array
    {
        try {
            $from = Carbon::parse($this->from ?: 'today');
            $to = Carbon::parse($this->to ?: 'today');
        } catch (\Throwable) {
            $from = now();
            $to = now();
        }

        [$from, $to] = $to->lt($from) ? [$to, $from] : [$from, $to];

        // At most a year at a time (the page loads every receipt of the period).
        return [$from->lt($to->copy()->subYear()) ? $to->copy()->subYear()->addDay() : $from, $to];
    }

    /** @return array<int, string> the company's users (cashier filter) */
    public function getCashierOptions(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant->users()->orderBy('name')->pluck('users.name', 'users.id')->all();
    }

    /** Memoised per request. */
    public function getResult(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        [$from, $to] = $this->range();
        $cashier = ctype_digit($this->cashier) ? (int) $this->cashier : null;
        // Never a user outside the tenant (a crafted id would scope to a stranger).
        if ($cashier !== null && ! array_key_exists($cashier, $this->getCashierOptions())) {
            $cashier = null;
        }

        return $this->result = app(ReportBuilder::class)->build($tenant, $from, $to, $cashier) + ['from' => $from, 'to' => $to];
    }

    /** The expanded session: its documents, report and a fresh link to print it. */
    public function getSessionDetail(): ?array
    {
        if ($this->openSession === null) {
            return null;
        }
        $session = PosSession::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', Filament::getTenant()?->getKey())
            ->find($this->openSession);
        if ($session === null) {
            return null;
        }

        /** @var Collection<int, Invoice> $docs */
        $docs = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $session->company_id)
            ->where('pos_session_id', $session->getKey())
            ->with(['invoiceType', 'posCashier'])
            ->orderBy('id')
            ->get();

        return [
            'session' => $session,
            'report' => app(TillSessions::class)->report($session),
            'docs' => $docs,
            'print_url' => PosSessionReportController::signedUrl($session->getKey(), 12 * 60),
        ];
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
        $r = $this->getResult();
        $name = 'tameio-'.$r['from']->format('Y-m-d').'_'.$r['to']->format('Y-m-d').'.csv';
        $safe = fn (?string $v): string => (($v = (string) $v) !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'".$v : $v;
        $n = fn (float|int|string|null $v): string => number_format((float) $v, 2, ',', '');

        return response()->streamDownload(function () use ($r, $safe, $n): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
            $row = fn (array $cols) => fputcsv($h, $cols, ';', escape: '');
            $row(['Περίοδος', $r['from']->format('d/m/Y').' – '.$r['to']->format('d/m/Y')]);
            $row(['Πωλήσεις', $r['totals']['sales_count'], $n($r['totals']['sales_total'])]);
            $row(['Επιστροφές', $r['totals']['refunds_count'], $n($r['totals']['refunds_total'])]);
            $row(['Καθαρός τζίρος', '', $n($r['totals']['net_total'])]);
            $row([]);
            $row(['Ημέρα', 'Αποδείξεις', 'Πωλήσεις', 'Επιστροφές', 'Καθαρά']);
            foreach ($r['by_day'] as $d) {
                $row([Carbon::parse($d['date'])->format('d/m/Y'), $d['sales_count'], $n($d['sales']), $n($d['refunds']), $n($d['net'])]);
            }
            $row([]);
            $row(['Ταμίας', 'Αποδείξεις', 'Πωλήσεις', 'Επιστροφές', 'Καθαρά', 'Εκπτώσεις', 'Κλεισίματα', 'Διαφορά ταμείου']);
            foreach ($r['by_cashier'] as $c) {
                $row([$safe($c['name']), $c['sales_count'], $n($c['sales']), $n($c['refunds']), $n($c['net']), $n($c['discounts']), $c['sessions_closed'], $n($c['difference'])]);
            }
            $row([]);
            $row(['Ταμείο', 'Άνοιγμα', 'Από', 'Κλείσιμο', 'Από', 'Καθαρά', 'Αναμενόμενα', 'Μετρήθηκαν', 'Διαφορά']);
            foreach ($r['sessions'] as $s) {
                $rep = is_array($s->closing_report) ? $s->closing_report : [];
                $row(['#'.$s->id, $s->opened_at?->format('d/m/Y H:i'), $safe($s->opener?->name), $s->closed_at?->format('d/m/Y H:i') ?? 'ανοιχτό', $safe($s->closer?->name),
                    $s->isOpen() ? '' : $n($rep['net_total'] ?? null), $s->isOpen() ? '' : $n($s->expected_cash), $s->isOpen() ? '' : $n($s->counted_cash),
                    $s->isOpen() ? '' : $n((float) $s->counted_cash - (float) $s->expected_cash)]);
            }
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
