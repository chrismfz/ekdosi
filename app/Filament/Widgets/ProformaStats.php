<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * «Προτιμολόγια» — the documents a customer is currently being asked to settle.
 *
 * They are deliberately invisible to «Απαιτήσεις» while unpaid: nothing is owed
 * yet, because a προτιμολόγιο is not a παραστατικό and either side may still call
 * the service off. That correctness comes at a cost — without this widget an
 * operator has no way to notice that 2, 3 or 10 of them are sitting out there.
 *
 * The two halves answer different questions:
 *  - ΑΠΛΗΡΩΤΑ  → who hasn't paid yet (chase, or withdraw the offer)
 *  - ΠΛΗΡΩΜΕΝΑ → the queue of documents that still need ISSUING (the money has
 *                arrived, the legal document hasn't been produced)
 *
 * Each stat links to the invoices list pre-filtered, so the count is actionable
 * rather than just informative.
 */
class ProformaStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    // After the money + recurring-revenue headlines.
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '60s';

    /**
     * Memoised per request: canView() and getStats() both need the figure, and it is
     * a GROUP BY with a correlated EXISTS on a widget that polls every 60 seconds —
     * no reason to run it twice per render.
     *
     * @var array<string, array{unpaid_count:int, unpaid_gross:float, paid_count:int, paid_gross:float}>
     */
    private static array $pipelineCache = [];

    private static function pipeline(Company $tenant): array
    {
        return self::$pipelineCache[$tenant->getKey()]
            ??= (new DashboardMetrics($tenant))->proformaPipeline();
    }

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return false;
        }

        // Nothing to say when the tenant has never offered one — don't spend a row
        // of the dashboard on a permanent pair of zeros.
        $p = self::pipeline($tenant);

        return ($p['unpaid_count'] + $p['paid_count']) > 0;
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $p = self::pipeline($tenant);

        return [
            Stat::make('Απλήρωτα προτιμολόγια', (string) $p['unpaid_count'])
                ->description($this->eur($p['unpaid_gross']).' σε αναμονή πληρωμής')
                ->descriptionIcon('heroicon-m-clock')
                ->color($p['unpaid_count'] > 0 ? 'warning' : 'gray')
                ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['proforma' => ['value' => 'unpaid']]])),

            Stat::make('Πληρωμένα — προς έκδοση', (string) $p['paid_count'])
                ->description($p['paid_count'] > 0
                    ? $this->eur($p['paid_gross']).' εισπράχθηκαν, εκκρεμεί η έκδοση'
                    : 'Κανένα σε εκκρεμότητα')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($p['paid_count'] > 0 ? 'success' : 'gray')
                ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['proforma' => ['value' => 'paid']]])),
        ];
    }
}
