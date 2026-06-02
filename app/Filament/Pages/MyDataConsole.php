<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RemembersLastFetch;
use App\Filament\Pages\Concerns\ResolvesReconcileWindow;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SalesReconciler;
use App\Services\MyData\SalesReconciliationResult;
use App\Support\MyData\Codes;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * Phase 2 — LIVE myDATA console ("Κονσόλα myDATA").
 *
 * Calls AADE (RequestTransmittedDocs via App\Services\MyData\SalesReconciler)
 * and cross-checks what AADE actually holds against our local invoices
 * for a date window. Read-only worklist: every discrepancy row links
 * to the invoice, where the operator uses the existing per-invoice
 * actions (submit / cancel-via-myDATA) to resolve it.
 *
 * Distinct from MyDataReconciliation (Phase 1), which only cross-checks
 * our two internal columns and never touches AADE.
 *
 * Hidden for non-gr-mydata tenants and for Off-mode tenants (no AADE
 * endpoint to call).
 */
class MyDataConsole extends Page
{
    use RemembersLastFetch;
    use ResolvesReconcileWindow;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.my-data-console';

    /** dd/MM/yyyy window actually queried (for the results header). */
    public ?string $fromLabel = null;

    public ?string $toLabel = null;

    /** Serialized SalesReconciliationResult for the blade (Livewire-safe). */
    public ?array $result = null;

    /**
     * Which lens the current $result is shown through:
     *   - 'compare' : OUR filed invoices → myDATA (are they all there / in
     *                 sync?). Foregrounds discrepancies.
     *   - 'inbound' : myDATA → US. Foregrounds "αδέσποτα" — docs myDATA holds
     *                 for our AFM with no local ekdosi record (e-τιμολόγιο /
     *                 other software). Same RequestTransmittedDocs fetch, read
     *                 the other way round.
     */
    public ?string $resultMode = null;

    /** Y-m-d window actually queried — carried into each MARK detail link. */
    public ?string $windowFrom = null;

    public ?string $windowTo = null;

    public bool $ran = false;

    public ?string $error = null;

    public function mount(): void
    {
        $this->restoreFetch();
    }

    protected function cachedFetchProps(): array
    {
        return ['result', 'resultMode', 'fromLabel', 'toLabel', 'windowFrom', 'windowTo', 'ran'];
    }

    public static function getNavigationLabel(): string
    {
        return 'Κονσόλα myDATA';
    }

    public function getTitle(): string
    {
        return 'Κονσόλα myDATA';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Route-level authorization. shouldRegisterNavigation() only hides the menu
     * item — without this a user could hand-type the URL and trigger a live AADE
     * call with the tenant's credentials. Live myDATA reconciliation is admin
     * territory: gated on View:MyDataConsole, which company_admin (all perms) and
     * super_admin (Gate::before) hold but operators don't. Gate::can is
     * 404-storm-safe (missing permission → false, not a throw). The tenant must
     * still be a live (Greek, non-Off) myDATA tenant.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->isLiveMyDataTenant()
            && (bool) auth()->user()?->can('View:MyDataConsole');
    }

    protected function getHeaderActions(): array
    {
        return [
            // ONE fetch (RequestTransmittedDocs), BOTH directions shown together:
            // «τα δικά μας» (υπάρχουν/συμφωνούν στο myDATA;) + «αδέσποτα» (το
            // myDATA έχει για το ΑΦΜ μας χωρίς τοπική εγγραφή). Same SalesReconciler
            // call served two ways — one button, half the AADE calls.
            Action::make('reconcile')
                ->label('Έλεγχος myDATA')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->modalHeading('Έλεγχος myDATA')
                ->modalDescription('Κατεβάζει ό,τι έχει το myDATA για το ΑΦΜ μας στο διάστημα και δείχνει μαζί: αν τα δικά μας υπάρχουν/συμφωνούν, ΚΑΙ τυχόν «αδέσποτα» (στο myDATA αλλά όχι στο ekdosi). Δεν τροποποιεί τίποτα.')
                ->modalSubmitActionLabel('Έλεγχος')
                ->schema($this->windowSchema())
                ->action(function (array $data): void {
                    [$from, $to] = $this->resolveWindow($data);
                    $this->runReconciliation($from, $to);
                }),
        ];
    }

    protected function runReconciliation(string $from, string $to): void
    {
        $tenant = Filament::getTenant();

        // NB: do NOT wipe $this->result here. On a failed fetch (e.g. AADE 429)
        // we keep the last cached result + the «as of …» banner instead of
        // blanking the page; result/labels are overwritten only on success.
        $this->error = null;

        try {
            $reconciler = new SalesReconciler($tenant);
            $result = $reconciler->reconcile(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            $this->ran = true;
            $this->resultMode = 'both';
            $this->windowFrom = $from;
            $this->windowTo = $to;
            $this->result = $this->serialize($result);
            $this->fromLabel = $result->from;
            $this->toLabel = $result->to;
            $this->rememberFetch();

            // One combined toast covering BOTH directions: our-docs discrepancies
            // + αδέσποτα πωλήσεων (the actionable income bucket; payroll/expense
            // orphans are informational, not a "missed sale" alarm).
            $discrepancies = $result->discrepancyCount();
            $orphanIncome = 0;
            foreach ($result->missingLocally as $row) {
                if (Codes::transmittedDocBucket($row->invoiceType) === 'income') {
                    $orphanIncome++;
                }
            }

            $parts = [];
            $parts[] = $discrepancies > 0 ? "{$discrepancies} ασυμφωνίες" : 'καμία ασυμφωνία';
            $parts[] = $orphanIncome > 0 ? "{$orphanIncome} αδέσποτα πωλήσεων" : 'κανένα αδέσποτο πώλησης';

            Notification::make()
                ->title('Ο έλεγχος ολοκληρώθηκε')
                ->body(ucfirst(implode(' · ', $parts)).'.')
                ->{($discrepancies > 0 || $orphanIncome > 0) ? 'warning' : 'success'}()
                ->send();
        } catch (RateLimitExceededException $e) {
            // AADE 429 — keep whatever was on screen (preserved above) and tell
            // the operator when to retry, instead of a scary credentials error.
            $this->error = $this->rateLimitMessage($e->getMessage());

            Notification::make()
                ->title('Προσωρινό όριο myDATA')
                ->body($this->error)
                ->warning()
                ->send();
        } catch (RuntimeException $e) {
            // Our own guard messages (provider/mode/credentials) — safe
            // Greek strings meant for the operator.
            $this->error = $e->getMessage();

            Notification::make()
                ->title('Ο έλεγχος απέτυχε')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $e) {
            // firebed / Guzzle / parsing failures — the message can carry
            // the endpoint URL and internal context. Log the detail, show
            // the operator a generic line.
            Log::warning('myDATA sales reconciliation failed', [
                'company_id' => $tenant?->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->error = 'Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.';

            Notification::make()
                ->title('Ο έλεγχος απέτυχε')
                ->body($this->error)
                ->danger()
                ->send();
        }
    }

    private function serialize(SalesReconciliationResult $r): array
    {
        $rows = fn (array $rows) => array_map($this->rowToArray(...), $rows);

        return [
            'from' => $r->from,
            'to' => $r->to,
            'aadeTotal' => $r->aadeTotal,
            'localTotal' => $r->localTotal,
            'discrepancyCount' => $r->discrepancyCount(),
            'matched' => $rows($r->matched),
            'stateMismatch' => $rows($r->stateMismatch),
            'missingAtAade' => $rows($r->missingAtAade),
            'missingLocally' => $rows($r->missingLocally),
            'duplicateLocal' => $rows($r->duplicateLocal),
        ];
    }

    private function rowToArray(ReconciliationRow $row): array
    {
        return [
            'mark' => $row->mark,
            'uid' => $row->uid,
            'invoiceId' => $row->invoiceId,
            'invcode' => $row->invcode,
            'issuedAt' => $row->issuedAt,
            'counterpartName' => $row->counterpartName,
            'gross' => $row->gross,
            'localState' => $row->localState,
            'localStatus' => $row->localStatus,
            'aadeState' => $row->aadeState,
            'cancelledByMark' => $row->cancelledByMark,
            'problem' => $row->problem,
            'invoiceType' => $row->invoiceType,
            'invoiceTypeLabel' => $row->invoiceTypeLabel,
            // Economic bucket for orphan grouping: income / expense / other.
            'bucket' => Codes::transmittedDocBucket($row->invoiceType),
            'url' => $row->invoiceId ? $this->invoiceUrl($row->invoiceId) : null,
            // Every MARK (linked or orphan) gets a detail link, carrying the
            // queried window so an orphan lookup re-fetches the right page.
            'markUrl' => $this->markUrl($row->mark),
        ];
    }

    private function invoiceUrl(int $invoiceId): ?string
    {
        $tenant = Filament::getTenant();

        return InvoiceResource::getUrl('view', [
            'record' => $invoiceId,
            'tenant' => $tenant,
        ]);
    }

    private function markUrl(string $mark): ?string
    {
        if ($mark === '') {
            return null;
        }

        return MyDataMarkDetail::getUrl([
            'mark' => $mark,
            'tenant' => Filament::getTenant(),
            'from' => $this->windowFrom,
            'to' => $this->windowTo,
        ]);
    }
}
