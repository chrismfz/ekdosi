<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Dashboard\VatPeriodReport;
use App\Services\Dashboard\VatPeriodSummary;
use App\Support\Accounting\IncomeTaxProfile;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * «Φορολογικά» — «τι χρωστάμε» in one place, per year: ΦΠΑ ανά μήνα + τρίμηνο
 * (with the πιστωτικό carried forward), the income-tax ESTIMATE
 * ({@see IncomeTaxEstimate}: φόρος, προκαταβολή, παρακρατήσεις, υπόλοιπο, προβολή
 * 31/12), the expense side by economic bucket (μισθοδοσία / ΕΦΚΑ as the accountant
 * posts them) and a multi-year table. Local data only, no AADE call. The
 * «Φορολογικό προφίλ» (rates + ΒΕΒΑΙΩΜΕΝΕΣ προκαταβολές) is editable by whoever
 * may edit the company settings. Gated on View:TaxOverview (shield:generate +
 * re-provision after deploy).
 */
class TaxOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?string $navigationLabel = 'Φορολογικά';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.tax-overview';

    /** Years in the «ανά έτος» table (the dropdown still reaches every year). */
    private const SUMMARY_YEARS = 6;

    public int $year;

    private ?array $estimate = null;

    /** Per-request memo: one service (its per-year memo is shared by the headline
     *  estimate AND the multi-year table), years and the table built once per render. */
    private ?IncomeTaxEstimate $service = null;

    private ?array $years = null;

    private ?array $summary = null;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function getTitle(): string
    {
        return 'Φορολογικά';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        // Greek tenants only: the estimate is Greek company tax (22%/80%) and
        // Greek quarterly ΦΠΑ — meaningless for the Estonian tenant.
        return $tenant instanceof Company
            && $tenant->country_code === 'GR'
            && (bool) auth()->user()?->can('View:TaxOverview');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function updated(): void
    {
        $this->estimate = null;
    }

    private function tenant(): Company
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }

    private function service(): IncomeTaxEstimate
    {
        return $this->service ??= new IncomeTaxEstimate($this->tenant());
    }

    /** @return array<int, int> */
    public function availableYears(): array
    {
        $years = $this->years ??= $this->service()->availableYears();

        return array_combine($years, $years);
    }

    public function estimate(): array
    {
        return $this->estimate ??= $this->service()->forYear($this->year);
    }

    /** @return list<array> newest first */
    public function yearsSummary(): array
    {
        return $this->summary ??= $this->service()->yearsSummary(array_slice(array_keys($this->availableYears()), 0, self::SUMMARY_YEARS));
    }

    /**
     * ΦΠΑ per quarter with its three months. `payable` = what the quarter's
     * return actually asks for once an earlier πιστωτικό is carried forward
     * (a credit never becomes a payment; it rolls into the next quarter).
     *
     * @return array{quarters: list<array{q: VatPeriodSummary, months: list<VatPeriodSummary>, carried_in: float, payable: float, carry_out: float}>, year: VatPeriodSummary, payable_total: float}
     */
    public function vat(): array
    {
        $report = new VatPeriodReport($this->tenant());
        $carry = 0.0;
        $quarters = [];
        $payableTotal = 0.0;

        foreach ([1, 2, 3, 4] as $q) {
            $start = CarbonImmutable::create($this->year, ($q - 1) * 3 + 1, 1);
            $summary = $report->forPeriod($start, $start->addMonths(2)->endOfMonth(), "Τρίμηνο {$q}");
            $afterCarry = round($summary->netVat() - $carry, 2);
            $payable = max(0.0, $afterCarry);
            $quarters[] = [
                'q' => $summary,
                'months' => $report->monthsOfQuarter($start),
                'carried_in' => $carry,
                'payable' => $payable,
                'carry_out' => $afterCarry < 0 ? -$afterCarry : 0.0,
            ];
            $carry = $afterCarry < 0 ? -$afterCarry : 0.0;
            $payableTotal += $payable;
        }

        return [
            'quarters' => $quarters,
            'year' => $report->forPeriod(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31)->endOfDay(), (string) $this->year),
            'payable_total' => round($payableTotal, 2),
        ];
    }

    public function fmt(float $v): string
    {
        return Money::eur($v);
    }

    /** «01/2026» (monthsOfQuarter's label) → «Ιανουάριος». */
    public function monthName(VatPeriodSummary $m): string
    {
        $names = [1 => 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος',
            'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];

        return $names[(int) explode('/', $m->label)[0]] ?? $m->label;
    }

    public static function canEditProfile(): bool
    {
        return CompanySettings::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tax_profile')
                ->label('Φορολογικό προφίλ')
                ->icon('heroicon-o-cog-6-tooth')
                ->visible(fn () => static::canEditProfile())
                ->modalHeading('Φορολογικό προφίλ')
                ->modalDescription('Οι συντελεστές της εκτίμησης. Επιβεβαιώστε τους με τον λογιστή. Η «βεβαιωμένη προκαταβολή» ενός έτους είναι αυτή που γράφει το εκκαθαριστικό της προηγούμενης δήλωσης. Μόνο αυτή αφαιρείται από τον φόρο· αν λείπει, μετράει 0.')
                ->fillForm(function (): array {
                    $p = IncomeTaxProfile::for($this->tenant());
                    $rows = [];
                    foreach ($p->assessedPrepayments as $y => $amount) {
                        $rows[] = ['year' => $y, 'amount' => $amount];
                    }

                    return ['rate' => $p->rate, 'prepayment_rate' => $p->prepaymentRate, 'assessed' => $rows];
                })
                ->schema([
                    TextInput::make('rate')->label('Συντελεστής φόρου')->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                    TextInput::make('prepayment_rate')->label('Προκαταβολή φόρου')->numeric()->minValue(0)->maxValue(100)->suffix('%')->required()
                        ->helperText('Συνήθως 80%. Οι νέες επιχειρήσεις πληρώνουν τη μισή τα πρώτα έτη, οπότε βάλτε το μειωμένο ποσοστό.'),
                    Repeater::make('assessed')
                        ->label('Βεβαιωμένη προκαταβολή ανά έτος')
                        ->schema([
                            TextInput::make('year')->label('Για το έτος')->numeric()->integer()->minValue(2000)->maxValue(2100)->required()->distinct(),
                            TextInput::make('amount')->label('Ποσό (€)')->numeric()->minValue(0)->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Προσθήκη έτους'),
                ])
                ->action(function (array $data): void {
                    abort_unless(static::canEditProfile(), 403);

                    $assessed = [];
                    foreach ($data['assessed'] ?? [] as $row) {
                        if (is_numeric($row['year'] ?? null) && is_numeric($row['amount'] ?? null)) {
                            $assessed[(int) $row['year']] = (float) $row['amount'];
                        }
                    }
                    $profile = new IncomeTaxProfile((float) $data['rate'], (float) $data['prepayment_rate'], $assessed);

                    // Not fillable on purpose: written only here, as a validated whole.
                    $this->tenant()->forceFill(['income_tax_profile' => $profile->toArray()])->save();
                    // The profile changes every figure: drop the memos so this render recomputes.
                    $this->estimate = $this->summary = null;
                    $this->service = null;

                    Notification::make()->title('Το φορολογικό προφίλ αποθηκεύτηκε')->success()->send();
                }),
        ];
    }
}
