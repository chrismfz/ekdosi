<?php

namespace App\Filament\Pages;

use App\Enums\MyDataMode;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\MyData\TransmittedDocReader;
use App\Support\MyData\MarkDetail;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * "MARK detail" — the full picture behind ONE myDATA MARK, reached by
 * clicking a MARK anywhere in the app (myDATA console, submission-history
 * relation manager, latest-invoices widget).
 *
 * Resolves the MARK two ways:
 *   - LOCAL: a local invoice carries this mydata_mark → show the document
 *     we filed (header, lines, totals) + the request/response XML kept in
 *     mydata_marks, with a link straight to the invoice.
 *   - ORPHAN: no local record → fetch the full document from AADE
 *     (RequestTransmittedDocs, line-level) within the date window and show
 *     it read-only. "Καταχώριση τοπικά" is the (future) hook to import it.
 *
 * mark / from / to ride in the query string (#[Url]) so the page is
 * bookmarkable and the "change window" action just rewrites them. Not in
 * the navigation — link-only.
 *
 * Gated to gr-mydata, non-Off tenants: an orphan lookup triggers a live
 * AADE call with the tenant's credentials.
 */
class MyDataMarkDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected string $view = 'filament.pages.my-data-mark-detail';

    #[Url]
    public ?string $mark = null;

    /** Date window (Y-m-d) used for the orphan AADE lookup. */
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /** Livewire-safe detail array (App\Support\MyData\MarkDetail shape). */
    public ?array $doc = null;

    public ?int $invoiceId = null;

    public bool $isOrphan = false;

    public ?string $error = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Route-level authorization — same tenant gate as the myDATA console (an
     * orphan lookup hits AADE with the tenant's credentials, so a hand-typed URL
     * must be blocked for non-Greek / Off tenants). Unlike the consoles this is a
     * READ-ONLY drill-down linked from invoice rows, so operators reach it too:
     * View:MyDataMarkDetail (admin page perm) OR View:Invoice (operators have it).
     * Gate::can is 404-storm-safe.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return $tenant
            && $tenant->einvoice_provider === 'gr-mydata'
            && $tenant->mydata_mode_enum !== MyDataMode::Off
            && (bool) ($user?->can('View:MyDataMarkDetail') || $user?->can('View:Invoice'));
    }

    public function getTitle(): string
    {
        return $this->mark ? 'ΜΑΡΚ '.$this->mark : 'ΜΑΡΚ';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (blank($this->mark)) {
            abort(404);
        }

        $this->load();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_invoice')
                ->label('Άνοιγμα παραστατικού')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->visible(fn (): bool => $this->invoiceId !== null)
                ->url(fn (): ?string => $this->invoiceId === null ? null : InvoiceResource::getUrl('view', [
                    'record' => $this->invoiceId,
                    'tenant' => Filament::getTenant(),
                ])),

            Action::make('change_window')
                ->label('Αλλαγή διαστήματος')
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->visible(fn (): bool => $this->isOrphan)
                ->modalHeading('Αναζήτηση αδέσποτου σε άλλο διάστημα')
                ->modalDescription('Το myDATA επιστρέφει παραστατικά ανά διάστημα. Αν το ΜΑΡΚ δεν βρέθηκε, διευρύνετε το διάστημα.')
                ->modalSubmitActionLabel('Αναζήτηση')
                ->schema([
                    DatePicker::make('from')
                        ->label('Από')
                        ->required()
                        ->default($this->from ?? now()->subMonths(13)->format('Y-m-d')),
                    DatePicker::make('to')
                        ->label('Έως')
                        ->required()
                        ->default($this->to ?? now()->format('Y-m-d')),
                ])
                ->action(function (array $data): void {
                    $this->from = $data['from'];
                    $this->to = $data['to'];
                    $this->load();
                }),

            // Placeholder for the income-side mirror of ExpenseImporter.
            // Intentionally does nothing yet — it only documents the next
            // step so operators know orphans aren't silently importable.
            Action::make('import_local')
                ->label('Καταχώριση τοπικά')
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('gray')
                ->visible(fn (): bool => $this->isOrphan && $this->doc !== null)
                ->modalHeading('Καταχώριση αδέσποτου ως τοπικό παραστατικό')
                ->modalDescription('Η αυτόματη καταχώριση αδέσποτων πωλήσεων στο ekdosi δεν είναι ακόμη διαθέσιμη (έπεται — ο αντίστοιχος μηχανισμός υπάρχει ήδη για τα Έξοδα). Προς το παρόν καταχωρίστε το χειροκίνητα ή αγνοήστε το.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Κλείσιμο'),
        ];
    }

    /**
     * Resolve the MARK: local invoice first, else an AADE orphan lookup.
     * Re-runnable (the "change window" action calls it again).
     */
    public function load(): void
    {
        $this->error = null;
        $this->doc = null;

        $tenant = Filament::getTenant();
        $mark = (string) $this->mark;

        $invoice = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->where('mydata_mark', $mark)
            ->with([
                // Stable, insertion-ordered lines so the displayed numbering
                // matches the filed document (no stored line_number column).
                'lines' => fn ($q) => $q->orderBy('id')->with('product'),
                'invoiceType',
                'company',
            ])
            ->first();

        if ($invoice !== null) {
            $this->isOrphan = false;
            $this->invoiceId = $invoice->id;
            $this->doc = MarkDetail::fromInvoice($invoice);

            $markRow = MyDataMark::query()
                ->where('company_id', $tenant->getKey())
                ->where('invoice_id', $invoice->id)
                ->where('mark', $mark)
                ->latest('id')
                ->first();

            // Single home for the audit XML: the detail array (the orphan
            // path already carries response XML there). Avoids serialising it
            // into a second Livewire prop.
            $this->doc['requestXml'] = $markRow?->request;
            $this->doc['responseXml'] = $markRow?->response;

            return;
        }

        // Orphan — pull the full document from AADE for the window.
        $this->isOrphan = true;
        $this->invoiceId = null;

        [$from, $to] = $this->resolveWindow();

        try {
            $detail = (new TransmittedDocReader($tenant))->fetchDetailByMark($mark, $from, $to);

            if ($detail === null) {
                $this->error = 'Το ΜΑΡΚ δεν βρέθηκε στο myDATA για το διάστημα '
                    .$from->format('d/m/Y').' – '.$to->format('d/m/Y')
                    .'. Δοκιμάστε «Αλλαγή διαστήματος».';

                return;
            }

            $this->doc = $detail;
        } catch (RuntimeException $e) {
            // Our own guard messages (provider/mode/credentials) — safe Greek.
            $this->error = $e->getMessage();
        } catch (Throwable $e) {
            Log::warning('myDATA mark detail fetch failed', [
                'company_id' => $tenant?->getKey(),
                'mark' => $mark,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->error = 'Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.';
        }
    }

    /**
     * Window for the orphan lookup: the from/to query params when present
     * (e.g. carried from the console link), else the last ~13 months — wide
     * enough to catch a bookmarked MARK without a window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(): array
    {
        $from = $this->from
            ? Carbon::parse($this->from)->startOfDay()
            : now()->subMonths(13)->startOfDay();

        $to = $this->to
            ? Carbon::parse($this->to)->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }
}
