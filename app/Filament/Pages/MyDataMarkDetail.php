<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\MyData\EnrichInvoiceFromAade;
use App\Services\MyData\TransmittedDocReader;
use App\Support\MyData\MarkDetail;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
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

    /**
     * Result of the last «Άντληση/έλεγχος από ΑΑΔΕ» — the field-by-field
     * comparison (✓/⚠) rendered as a panel under the document. Shape:
     * {stamped_qr, filled[], comparison: [{label, local, aade, match}]}.
     *
     * @var array<string,mixed>|null
     */
    public ?array $enrichReport = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Route-level authorization. READ-ONLY drill-down linked from invoice rows;
     * the operator role holds View:MyDataMarkDetail directly (see
     * TenantRoleProvisioner::OPERATOR_PERMISSION_MAP), so no piggy-backing on
     * View:Invoice. The live-AADE orphan lookup inside load() is separately
     * gated on View:MyDataConsole (admin-only). Gate::can is 404-storm-safe; the
     * tenant must still be a live myDATA tenant (the page hits AADE).
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->isLiveMyDataTenant()
            && (bool) auth()->user()?->can('View:MyDataMarkDetail');
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

            // Live pull from AADE by MARK → stamp the QR (+ fill blanks) onto
            // the local invoice and show a field-by-field comparison. The whole
            // point for imported invoices (Epsilon/legacy) that have a MARK but
            // no QR. Gated on the console permission (it's a live, billable AADE
            // call) and only when a local invoice exists to enrich.
            Action::make('enrich_from_aade')
                ->label('Άντληση/έλεγχος από ΑΑΔΕ')
                ->icon('heroicon-o-qr-code')
                ->color('success')
                ->visible(fn (): bool => $this->invoiceId !== null && (bool) auth()->user()?->can('View:MyDataConsole'))
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-qr-code')
                ->modalHeading('Άντληση QR & σύγκριση με ΑΑΔΕ')
                ->modalDescription('Ζωντανή ανάκτηση του παραστατικού από το myDATA βάσει ΜΑΡΚ: συμπληρώνει το QR (και ό,τι λείπει) στο τοπικό παραστατικό και δείχνει τυχόν διαφορές. Δεν αλλάζει ήδη συμπληρωμένες τιμές.')
                ->modalSubmitActionLabel('Άντληση')
                ->action(fn () => $this->enrichFromAade()),

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

        // The orphan lookup is a LIVE AADE call with the tenant's credentials —
        // admin territory, same as the consoles. Operators reach this page (for
        // their own filed invoices) via View:MyDataMarkDetail, but must NOT be
        // able to trigger billable/rate-limited live AADE calls for arbitrary
        // hand-typed MARKs. Gate the live branch on the console permission.
        if (! auth()->user()?->can('View:MyDataConsole')) {
            $this->error = 'Το ΜΑΡΚ δεν αντιστοιχεί σε τοπικό παραστατικό. Η αναζήτηση στο myDATA είναι διαθέσιμη μόνο σε διαχειριστές.';

            return;
        }

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
     * Live-pull this MARK from AADE, enrich the local invoice (QR + fill-blanks)
     * and surface the field-by-field comparison both as a toast and the on-page
     * panel ($enrichReport). No-op-safe: guards on the console permission and a
     * resolvable local invoice; AADE/credential failures degrade to a Greek
     * notification, never a 500.
     */
    public function enrichFromAade(): void
    {
        $tenant = Filament::getTenant();

        if ($this->invoiceId === null || ! auth()->user()?->can('View:MyDataConsole')) {
            Notification::make()->title('Μη διαθέσιμο')->danger()
                ->body('Η άντληση από το ΑΑΔΕ είναι διαθέσιμη μόνο σε διαχειριστές, για τοπικό παραστατικό.')
                ->send();

            return;
        }

        $invoice = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($this->invoiceId)
            ->first();

        if ($invoice === null || blank($invoice->mydata_mark)) {
            Notification::make()->title('Δεν βρέθηκε παραστατικό με ΜΑΡΚ')->danger()->send();

            return;
        }

        [$from, $to] = $this->resolveWindow();

        try {
            $detail = (new TransmittedDocReader($tenant))->fetchDetailByMark((string) $this->mark, $from, $to);
        } catch (RuntimeException $e) {
            Notification::make()->title('Αποτυχία')->danger()->body($e->getMessage())->send();

            return;
        } catch (Throwable $e) {
            Log::warning('myDATA enrich fetch failed', [
                'company_id' => $tenant?->getKey(),
                'mark' => $this->mark,
                'exception' => $e::class,
            ]);
            Notification::make()->title('Αποτυχία')->danger()
                ->body('Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.')
                ->send();

            return;
        }

        if ($detail === null) {
            Notification::make()->title('Δεν βρέθηκε στο myDATA')->warning()
                ->body('Το ΜΑΡΚ δεν βρέθηκε για το διάστημα '.$from->format('d/m/Y').' – '.$to->format('d/m/Y')
                    .'. Δοκιμάστε «Αλλαγή διαστήματος».')
                ->send();

            return;
        }

        $this->enrichReport = app(EnrichInvoiceFromAade::class)->enrich($invoice, $detail);
        $this->load(); // re-read the local doc so the freshly-stamped QR shows

        $diffs = array_values(array_filter($this->enrichReport['comparison'], fn (array $r): bool => ! $r['match']));
        $notification = Notification::make()->title('Σύγκριση με ΑΑΔΕ ολοκληρώθηκε')->persistent();

        $bodyLines = [];
        if ($this->enrichReport['stamped_qr']) {
            $bodyLines[] = '✓ Συμπληρώθηκε το QR.';
        }
        if ($this->enrichReport['filled'] !== []) {
            $bodyLines[] = 'Συμπληρώθηκαν: '.implode(', ', $this->enrichReport['filled']).'.';
        }
        if ($diffs === []) {
            $bodyLines[] = '✓ Όλα τα πεδία συμφωνούν με το ΑΑΔΕ.';
            $notification->success();
        } else {
            $bodyLines[] = 'Διαφορές ('.count($diffs).'): '
                .implode(' · ', array_map(fn (array $r): string => $r['label'], $diffs)).'.';
            $notification->warning();
        }

        $notification->body(implode(' ', $bodyLines))->send();
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
