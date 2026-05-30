<?php

namespace App\Filament\Pages;

use App\Enums\MyDataMode;
use App\Filament\Pages\Concerns\RemembersLastFetch;
use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SalesReconciler;
use App\Services\MyData\SalesReconciliationResult;
use App\Support\MyData\Codes;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
     * Route-level authorization. shouldRegisterNavigation() only hides
     * the menu item — without this a user could hand-type the URL and
     * trigger a live AADE call with the tenant's credentials. Gate the
     * page itself to authenticated users of a Greek, non-Off tenant.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return auth()->check()
            && $tenant
            && $tenant->einvoice_provider === 'gr-mydata'
            && $tenant->mydata_mode_enum !== MyDataMode::Off;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Direction 1 — OUR records → myDATA. "Are the invoices we filed
            // actually at AADE and in the same state?"
            Action::make('reconcile')
                ->label('Έλεγχος δικών μας στο myDATA')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->modalHeading('Έλεγχος δικών μας στο myDATA')
                ->modalDescription('Παίρνει τα παραστατικά που υποβάλαμε εμείς και επιβεβαιώνει ότι υπάρχουν και συμφωνούν (καταστάσεις/ακυρώσεις) στο myDATA. Εντοπίζει ό,τι λείπει από το myDATA ή διαφέρει.')
                ->modalSubmitActionLabel('Έλεγχος')
                ->schema($this->windowSchema())
                ->action(fn (array $data) => $this->runReconciliation($data['from'], $data['to'], 'compare')),

            // Direction 2 — myDATA → US. The same RequestTransmittedDocs pull
            // read the other way: surfaces "αδέσποτα" — docs AADE holds for our
            // AFM with no local ekdosi record (issued via e-τιμολόγιο or other
            // software).
            Action::make('find_orphans')
                ->label('Αδέσποτα από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('warning')
                ->modalHeading('Αδέσποτα παραστατικά από myDATA')
                ->modalDescription('Κατεβάζει ό,τι έχει το myDATA για το ΑΦΜ μας και εντοπίζει «αδέσποτα»: παραστατικά που υπάρχουν στο myDATA αλλά ΟΧΙ στο ekdosi (π.χ. εκδόθηκαν από e-τιμολόγιο ΑΑΔΕ ή άλλο πρόγραμμα).')
                ->modalSubmitActionLabel('Λήψη')
                ->schema($this->windowSchema())
                ->action(fn (array $data) => $this->runReconciliation($data['from'], $data['to'], 'inbound')),
        ];
    }

    /**
     * Shared date-window form for both directions.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function windowSchema(): array
    {
        return [
            DatePicker::make('from')
                ->label('Από')
                ->required()
                ->default(now()->subMonth()->startOfMonth()),
            DatePicker::make('to')
                ->label('Έως')
                ->required()
                ->default(now()),
        ];
    }

    protected function runReconciliation(string $from, string $to, string $mode = 'compare'): void
    {
        $tenant = Filament::getTenant();

        $this->ran = true;
        $this->error = null;
        $this->result = null;
        $this->resultMode = $mode;
        $this->windowFrom = $from;
        $this->windowTo = $to;

        try {
            $reconciler = new SalesReconciler($tenant);
            $result = $reconciler->reconcile(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            $this->result = $this->serialize($result);
            $this->fromLabel = $result->from;
            $this->toLabel = $result->to;
            $this->rememberFetch();

            if ($mode === 'inbound') {
                // Bucket the orphans so a €5.000 payroll (17.1) or a Hetzner
                // expense (14.3) doesn't inflate the "missed sales" alarm.
                $byBucket = ['income' => 0, 'expense' => 0, 'other' => 0];
                foreach ($result->missingLocally as $row) {
                    $byBucket[Codes::transmittedDocBucket($row->invoiceType)]++;
                }
                $income = $byBucket['income'];
                $rest = $byBucket['expense'] + $byBucket['other'];

                $body = $income > 0
                    ? "{$income} αδέσποτα πωλήσεων (έσοδα χωρίς τοπική εγγραφή)"
                    : 'Καμία αδέσποτη πώληση.';
                if ($rest > 0) {
                    $body .= " — και {$rest} λοιπά (έξοδα/μισθοδοσία/τακτοποιήσεις, ενημερωτικά).";
                }

                Notification::make()
                    ->title('Η λήψη από myDATA ολοκληρώθηκε')
                    ->body($body)
                    ->{$income > 0 ? 'warning' : 'success'}()
                    ->send();
            } else {
                $msg = $result->hasDiscrepancies()
                    ? $result->discrepancyCount().' ασυμφωνίες βρέθηκαν'
                    : 'Όλα συμφωνούν με το AADE';

                Notification::make()
                    ->title('Ο έλεγχος ολοκληρώθηκε')
                    ->body($msg)
                    ->{$result->hasDiscrepancies() ? 'warning' : 'success'}()
                    ->send();
            }
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
