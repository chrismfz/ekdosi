<?php

namespace App\Filament\Support;

use App\Models\Company;
use App\Services\Import\CsvImportPlan;
use App\Services\Import\CsvTable;
use App\Services\Import\EntityCsvImporter;
use App\Services\Import\PlannedRow;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * «Εισαγωγή CSV» header action for a list page: upload → a dry-run preview
 * (columns recognised, what each row will do, row-level problems) → «Εισαγωγή».
 * The preview is computed once per upload and kept as plain data (rendered with
 * escaping); the import re-reads the file and re-plans against current data.
 */
final class CsvImportAction
{
    /** Rows with notes listed in the preview; the rest are summarised. */
    private const PREVIEW_NOTES = 15;

    public static function make(EntityCsvImporter $importer, Closure $visible, string $templateName): Action
    {
        // Creating needs the create right ($visible); filling an EXISTING record's
        // blanks is an edit, so it follows that record's update policy.
        $importer->withFillGate(fn (Model $record): bool => Gate::allows('update', $record));

        return Action::make('import_csv')
            ->label('Εισαγωγή CSV')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible($visible)
            ->modalHeading('Εισαγωγή '.$importer->entityLabel().' από CSV')
            ->modalDescription('Οι στήλες αναγνωρίζονται από την επικεφαλίδα — ελληνικά, αγγλικά ή οι στήλες του δικού μας export (κατέβασε το πρότυπο για έτοιμη μορφή). Όσα υπάρχουν ήδη συμπληρώνονται ΜΟΝΟ στα κενά πεδία· τίποτα δεν αντικαθίσταται. Δες την προεπισκόπηση πριν την «Εισαγωγή».')
            ->modalSubmitActionLabel('Εισαγωγή')
            ->modalWidth('3xl')
            ->schema([
                FileUpload::make('file')
                    ->label('Αρχείο CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'])
                    ->maxSize(5120)
                    ->storeFiles(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('preview', self::previewData($importer, $state))),
                Hidden::make('preview')->dehydrated(false),
                Placeholder::make('preview_view')
                    ->label('Προεπισκόπηση')
                    ->content(fn (Get $get): HtmlString => self::renderPreview($get('preview')))
                    ->visible(fn (Get $get): bool => is_array($get('preview'))),
            ])
            ->extraModalFooterActions([
                Action::make('import_csv_template')
                    ->label('Κατέβασμα προτύπου')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => response()->streamDownload(
                        fn () => print ($importer->template()),
                        $templateName,
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    )),
            ])
            ->action(function (array $data, Action $action) use ($importer): void {
                /** @var Company $tenant */
                $tenant = Filament::getTenant();

                try {
                    $plan = $importer->import($tenant, CsvTable::fromFile(self::uploadedPath($data['file'] ?? null)));
                } catch (RuntimeException|QueryException $e) {
                    Notification::make()
                        ->title('Η εισαγωγή δεν έγινε — δεν γράφτηκε τίποτα')
                        ->body($e instanceof QueryException ? 'Σφάλμα βάσης δεδομένων. Έλεγξε το αρχείο και ξαναδοκίμασε.' : $e->getMessage())
                        ->danger()
                        ->send();
                    report($e);
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Εισαγωγή '.$importer->entityLabel().' ολοκληρώθηκε')
                    ->body(self::summaryLine($plan).'.')
                    ->status($plan->count(PlannedRow::ERROR) > 0 ? 'warning' : 'success')
                    ->send();
            });
    }

    /** @return array<string, mixed> plain, serialisable preview data (or an error) */
    private static function previewData(EntityCsvImporter $importer, mixed $state): array
    {
        try {
            /** @var Company $tenant */
            $tenant = Filament::getTenant();
            $plan = $importer->plan($tenant, CsvTable::fromFile(self::uploadedPath($state)));
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $labels = array_map(static fn (array $def): string => $def['label'], $importer->fields());
        $notes = array_map(static fn (PlannedRow $r): array => [
            'line' => $r->line,
            'label' => $r->label,
            'error' => $r->failed(),
            'text' => implode(' ', [...$r->errors, ...$r->warnings]),
        ], $plan->rowsWithNotes());

        return [
            'summary' => self::summaryLine($plan),
            'mapped' => array_map(
                static fn (string $field, string $header): string => ($labels[$field] ?? $field).' ← «'.$header.'»',
                array_keys($plan->mapping),
                $plan->mapping,
            ),
            'ignored' => $plan->ignored,
            'notes' => array_slice($notes, 0, self::PREVIEW_NOTES),
            'more_notes' => max(0, count($notes) - self::PREVIEW_NOTES),
            'has_work' => $plan->hasWork(),
        ];
    }

    private static function renderPreview(mixed $data): HtmlString
    {
        if (! is_array($data)) {
            return new HtmlString('');
        }
        if (isset($data['error'])) {
            return new HtmlString('<p style="color:#b91c1c">⚠ '.e((string) $data['error']).'</p>');
        }

        $html = '<p><strong>'.e((string) $data['summary']).'</strong></p>';
        $html .= '<p style="margin-top:.5rem">Στήλες: '.e(implode(' · ', $data['mapped'] ?? [])).'</p>';
        if (($data['ignored'] ?? []) !== []) {
            $html .= '<p style="margin-top:.25rem;opacity:.75">Αγνοούνται: '.e(implode(', ', $data['ignored'])).'</p>';
        }
        if (($data['notes'] ?? []) !== []) {
            $html .= '<ul style="margin-top:.5rem;list-style:disc;padding-left:1.25rem">';
            foreach ($data['notes'] as $note) {
                $mark = $note['error'] ? '✗ ' : '⚠ ';
                $html .= '<li'.($note['error'] ? ' style="color:#b91c1c"' : '').'>'
                    .e($mark.'Γραμμή '.$note['line'].' ('.$note['label'].'): '.$note['text']).'</li>';
            }
            $html .= '</ul>';
            if (($data['more_notes'] ?? 0) > 0) {
                $html .= '<p style="opacity:.75">…και άλλες '.(int) $data['more_notes'].' γραμμές με σημειώσεις.</p>';
            }
        }
        if (! ($data['has_work'] ?? false)) {
            $html .= '<p style="margin-top:.5rem">Δεν υπάρχει κάτι να εισαχθεί.</p>';
        }

        return new HtmlString($html);
    }

    private static function summaryLine(CsvImportPlan $plan): string
    {
        return 'Νέα: '.$plan->count(PlannedRow::CREATE)
            .' · Συμπλήρωση κενών: '.$plan->count(PlannedRow::FILL)
            .' · Χωρίς αλλαγή: '.$plan->count(PlannedRow::UNCHANGED)
            .' · Παραλείπονται (σφάλμα): '.$plan->count(PlannedRow::ERROR);
    }

    /** The real path of the (single) uploaded temporary file. */
    private static function uploadedPath(mixed $state): string
    {
        $file = is_array($state) ? reset($state) : $state;
        if (! is_object($file) || ! method_exists($file, 'getRealPath') || ($path = $file->getRealPath()) === false) {
            throw new RuntimeException('Ανέβασε πρώτα ένα αρχείο CSV.');
        }

        return $path;
    }
}
