<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\E3YearTotals;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Dashboard\VatPeriodSummary;
use App\Services\Dashboard\VatYearOverview;
use App\Support\Accounting\IncomeTaxProfile;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
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

    /** Test seam: a MockHandler threaded into the Ε3 fetch (no network). */
    public static ?MockHandler $testHandler = null;

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
     * ΦΠΑ per quarter (+ months) with the πιστωτικό carried forward — see
     * {@see VatYearOverview} (shared with the `tax_overview` tool).
     *
     * @return array{quarters: list<array{q: VatPeriodSummary, months: list<VatPeriodSummary>, carried_in: float, payable: float, carry_out: float}>, year: VatPeriodSummary, payable_total: float}
     */
    public function vat(): array
    {
        return VatYearOverview::for($this->tenant(), $this->year);
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

    /** Can this tenant read its own picture back from myDATA (direct or via a provider)? */
    public function canReadMyData(): bool
    {
        return $this->tenant()->canReadMyData();
    }

    private function refreshE3(): void
    {
        try {
            $snap = E3YearTotals::refresh($this->tenant(), $this->year, static::$testHandler);
            $this->estimate = $this->summary = null;
            $this->service = null;

            Notification::make()
                ->title("Ε3 {$this->year} από την ΑΑΔΕ")
                ->body('Έσοδα '.$this->fmt((float) $snap->income).' · Έξοδα '.$this->fmt((float) $snap->expense)
                    .' · Αγορές παγίων '.$this->fmt((float) $snap->capex).' ('.$snap->doc_count.' εγγραφές).')
                ->success()
                ->send();
        } catch (RateLimitExceededException) {
            Notification::make()->title('Προσωρινό όριο myDATA')->body('Δοκιμάστε ξανά σε λίγα λεπτά.')->warning()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Η λήψη Ε3 απέτυχε')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Log::warning('Tax overview E3 refresh failed', [
                'company_id' => $this->tenant()->getKey(), 'year' => $this->year,
                'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            Notification::make()->title('Η λήψη Ε3 απέτυχε')->body('Η σύνδεση με την ΑΑΔΕ απέτυχε.')->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh_e3')
                ->label('Ανανέωση Ε3 (ΑΑΔΕ)')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->canReadMyData())
                ->requiresConfirmation()
                ->modalHeading(fn () => "Ε3 {$this->year} από την ΑΑΔΕ")
                ->modalDescription('Κατεβάζει το Ε3 του έτους από το myDATA (μόνο ανάγνωση), δηλαδή τον τελικό χαρακτηρισμό του λογιστή. Για κλεισμένα έτη η εκτίμηση βασίζεται σε αυτό.')
                ->action(fn () => $this->refreshE3()),
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

                    return ['rate' => $p->rate, 'prepayment_rate' => $p->prepaymentRate, 'assessed' => $rows,
                        'expected_partner_insurance' => $p->expectedPartnerInsurance ?: null];
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
                    TextInput::make('expected_partner_insurance')
                        ->label('Αναμενόμενες ετήσιες εισφορές εταίρων (ΕΦΚΑ)')->numeric()->minValue(0)->suffix('€')
                        ->helperText('Αν ο λογιστής περνά τον ΕΦΚΑ των εταίρων στο κλείσιμο της χρονιάς: το ποσό για όλο το έτος. Η προβολή 31/12 προσθέτει όσο δεν έχει εμφανιστεί ακόμα (Ε3 E3_585_007 ή ΕΦΚΑ 14.5) — δεν μετράει διπλά όταν περαστεί.'),
                ])
                ->action(function (array $data): void {
                    abort_unless(static::canEditProfile(), 403);

                    $assessed = [];
                    foreach ($data['assessed'] ?? [] as $row) {
                        if (is_numeric($row['year'] ?? null) && is_numeric($row['amount'] ?? null)) {
                            $assessed[(int) $row['year']] = (float) $row['amount'];
                        }
                    }
                    $profile = new IncomeTaxProfile((float) $data['rate'], (float) $data['prepayment_rate'], $assessed,
                        max(0.0, (float) ($data['expected_partner_insurance'] ?? 0)));

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
