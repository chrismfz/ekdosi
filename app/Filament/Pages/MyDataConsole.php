<?php

namespace App\Filament\Pages;

use App\Enums\MyDataMode;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SalesReconciler;
use App\Services\MyData\SalesReconciliationResult;
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
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.my-data-console';

    /** dd/MM/yyyy window actually queried (for the results header). */
    public ?string $fromLabel = null;

    public ?string $toLabel = null;

    /** Serialized SalesReconciliationResult for the blade (Livewire-safe). */
    public ?array $result = null;

    public bool $ran = false;

    public ?string $error = null;

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
            Action::make('reconcile')
                ->label('Έλεγχος με AADE')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('primary')
                ->modalHeading('Έλεγχος με AADE')
                ->modalDescription('Λήψη των παραστατικών που έχουν υποβληθεί στο AADE για το διάστημα και σύγκριση με τα τοπικά δεδομένα.')
                ->modalSubmitActionLabel('Έλεγχος')
                ->schema([
                    DatePicker::make('from')
                        ->label('Από')
                        ->required()
                        ->default(now()->subMonth()->startOfMonth()),
                    DatePicker::make('to')
                        ->label('Έως')
                        ->required()
                        ->default(now()),
                ])
                ->action(fn (array $data) => $this->runReconciliation($data['from'], $data['to'])),
        ];
    }

    protected function runReconciliation(string $from, string $to): void
    {
        $tenant = Filament::getTenant();

        $this->ran = true;
        $this->error = null;
        $this->result = null;

        try {
            $reconciler = new SalesReconciler($tenant);
            $result = $reconciler->reconcile(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            $this->result = $this->serialize($result);
            $this->fromLabel = $result->from;
            $this->toLabel = $result->to;

            $msg = $result->hasDiscrepancies()
                ? $result->discrepancyCount().' ασυμφωνίες βρέθηκαν'
                : 'Όλα συμφωνούν με το AADE';

            Notification::make()
                ->title('Ο έλεγχος ολοκληρώθηκε')
                ->body($msg)
                ->{$result->hasDiscrepancies() ? 'warning' : 'success'}()
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
            'url' => $row->invoiceId ? $this->invoiceUrl($row->invoiceId) : null,
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
}
