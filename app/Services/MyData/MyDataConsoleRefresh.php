<?php

namespace App\Services\MyData;

use App\Filament\Pages\MyDataConsole;
use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Pages\MyDataE3Overview;
use App\Models\Company;
use App\Support\MyData\VatPictureCache;
use Carbon\Carbon;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * «Ανανέωση όλων» — the one-fetch-to-rule-them-all behind the Κονσόλα myDATA.
 *
 * Runs the four AADE pulls the console surfaces — Πωλήσεις (RequestTransmittedDocs),
 * Έξοδα (RequestDocs), Ε3 (RequestE3Info), εικόνα ΦΠΑ (RequestVatInfo) — SEQUENTIALLY
 * (one at a time = friendly to the AADE rate limit) and writes each tab's own
 * snapshot cache, so all three tabs + the dashboard ΦΠΑ box refresh from a single
 * operator click instead of four separate buttons.
 *
 * Resilient: each step is isolated — a 429 / credential error on one (say Ε3) is
 * recorded as a warn/error and the rest still run. The caller renders the per-step
 * RefreshStep list as one summary.
 */
class MyDataConsoleRefresh
{
    /**
     * @param  \GuzzleHttp\Handler\MockHandler|null  $handler  test seam, threaded to every
     *                                                         reconciler + the VAT aggregator
     */
    public function __construct(
        private readonly ?\GuzzleHttp\Handler\MockHandler $handler = null,
    ) {}

    /**
     * Refresh every console snapshot for the tenant + window. The εικόνα ΦΠΑ step
     * always covers the current month + quarter (its own fixed periods), not the
     * passed window.
     *
     * @return list<RefreshStep>
     */
    public function refreshAll(Company $tenant, Carbon $from, Carbon $to): array
    {
        return [
            $this->step('Πωλήσεις', fn () => MyDataConsole::refreshSnapshot($tenant, $from, $to, $this->handler)),
            $this->step('Έξοδα', fn () => MyDataConsoleExpenses::refreshSnapshot($tenant, $from, $to, $this->handler)),
            $this->step('Επισκόπηση Ε3', fn () => MyDataE3Overview::refreshSnapshot($tenant, $from, $to, $this->handler)),
            $this->step('Εικόνα ΦΠΑ', fn () => $this->refreshVatPicture($tenant)),
        ];
    }

    /** Refresh the current month + quarter VAT picture into the shared cache. */
    private function refreshVatPicture(Company $tenant): void
    {
        $aggregator = new MyDataVatAggregator($tenant, $this->handler);
        $now = now();

        VatPictureCache::put($tenant, 'month',
            $aggregator->forPeriod($now->copy()->startOfMonth(), $now->copy()->endOfMonth()));
        VatPictureCache::put($tenant, 'quarter',
            $aggregator->forPeriod($now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()));
    }

    /** Run one step in isolation, mapping any failure to a RefreshStep status. */
    private function step(string $label, callable $run): RefreshStep
    {
        try {
            $run();

            return new RefreshStep($label, 'ok');
        } catch (RateLimitExceededException) {
            return new RefreshStep($label, 'warn', 'προσωρινό όριο myDATA');
        } catch (RuntimeException $e) {
            // Our own guard messages (mode off / missing creds) — safe Greek strings.
            return new RefreshStep($label, 'warn', $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('myDATA console refresh step failed', [
                'step' => $label, 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);

            return new RefreshStep($label, 'error', 'σφάλμα σύνδεσης');
        }
    }
}
