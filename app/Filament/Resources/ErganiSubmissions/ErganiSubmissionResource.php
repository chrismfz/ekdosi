<?php

namespace App\Filament\Resources\ErganiSubmissions;

use App\Filament\Resources\ErganiSubmissions\Pages\ListErganiSubmissions;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\OvertimeDeclarations\OvertimeDeclarationResource;
use App\Filament\Resources\WorkCardEvents\WorkCardEventResource;
use App\Models\Company;
use App\Models\ErganiSubmission;
use App\Services\Ergani\ErganiPdf;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * «Ιστορικό ΕΡΓΑΝΗ» — every call ekdosi made to ΕΡΓΑΝΗ (the append-only
 * ergani_submissions audit): when, who, which document, environment, result,
 * protocol, the related leave/card/overtime, and the official PDF. Read-only.
 */
class ErganiSubmissionResource extends Resource
{
    protected static ?string $model = ErganiSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Ιστορικό ΕΡΓΑΝΗ';

    protected static ?string $modelLabel = 'κλήση ΕΡΓΑΝΗ';

    protected static ?string $pluralModelLabel = 'Ιστορικό ΕΡΓΑΝΗ';

    protected static ?int $navigationSort = 45;

    protected static bool $isGloballySearchable = false;

    public const DOCUMENT_LABELS = [
        'WTOLeave' => 'Άδεια',
        'WTOOv' => 'Υπερωρία',
        'WRKCardSE' => 'Κάρτα εργασίας',
    ];

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && parent::canAccess();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'leaveRequest.employee', 'workCardEvent.employee', 'overtimeDeclaration.employee']))
            ->columns([
                TextColumn::make('created_at')->label('Πότε')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('document')->label('Τι')
                    ->formatStateUsing(fn (string $state, ErganiSubmission $record): string => (self::DOCUMENT_LABELS[$state] ?? $state)
                        .match ($record->action) {
                            'cancel' => ' — ανάκληση',
                            'manual' => ' — καταχώριση πρωτοκόλλου (χειροκίνητα)',
                            default => '',
                        }),
                TextColumn::make('subject')->label('Εργαζόμενος')
                    ->state(fn (ErganiSubmission $record): string => (string) ($record->leaveRequest?->employee?->full_name
                        ?? $record->workCardEvent?->employee?->full_name
                        ?? $record->overtimeDeclaration?->employee?->full_name ?? '—'))
                    ->url(fn (ErganiSubmission $record): ?string => self::relatedUrl($record)),
                TextColumn::make('environment')->label('Περιβάλλον')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'production' ? 'Παραγωγή' : 'Δοκιμαστικό')
                    ->color(fn (string $state): string => $state === 'production' ? 'success' : 'warning'),
                TextColumn::make('ok')->label('Αποτέλεσμα')->badge()
                    ->formatStateUsing(fn (bool $state, ErganiSubmission $record): string => $state ? 'OK' : ($record->http_status ? 'Σφάλμα '.$record->http_status : 'Δεν στάλθηκε / αβέβαιο'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->tooltip(fn (ErganiSubmission $record): ?string => $record->message),
                TextColumn::make('protocol')->label('Πρωτόκολλο')->placeholder('—')->copyable(),
                TextColumn::make('user.name')->label('Από')->placeholder('Σύστημα')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('document')->label('Τι')->options(self::DOCUMENT_LABELS),
                SelectFilter::make('environment')->label('Περιβάλλον')->options(['production' => 'Παραγωγή', 'trial' => 'Δοκιμαστικό']),
                TernaryFilter::make('ok')->label('Αποτέλεσμα')->trueLabel('Μόνο επιτυχίες')->falseLabel('Μόνο αποτυχίες'),
            ])
            ->emptyStateHeading('Καμία κλήση στο ΕΡΓΑΝΗ ακόμη')
            ->emptyStateDescription('Εδώ καταγράφεται κάθε δήλωση/ανάκληση που κάνει το ekdosi στο ΕΡΓΑΝΗ — με το πρωτόκολλο και το επίσημο PDF.')
            ->recordActions([self::pdfAction(), self::detailsAction()]);
    }

    public static function relatedUrl(ErganiSubmission $record): ?string
    {
        return match (true) {
            $record->leave_request_id !== null && LeaveRequestResource::canAccess() => LeaveRequestResource::getUrl('view', ['record' => $record->leave_request_id]),
            $record->work_card_event_id !== null && WorkCardEventResource::canAccess() => WorkCardEventResource::getUrl('index'),
            $record->overtime_declaration_id !== null && OvertimeDeclarationResource::canAccess() => OvertimeDeclarationResource::getUrl('index'),
            default => null,
        };
    }

    /** The official ΕΡΓΑΝΗ PDF of a successful submission — fetched live from the environment it went to. */
    public static function pdfAction(): Action
    {
        return Action::make('erganiPdf')
            ->label('PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (ErganiSubmission $record): bool => $record->ok && in_array($record->action, ['submit', 'manual'], true)
                && filled($record->protocol) && in_array($record->document, ErganiPdf::DOCUMENTS, true))
            ->authorize(fn (ErganiSubmission $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->action(function (ErganiSubmission $record) {
                $pdf = app(ErganiPdf::class)->fetch($record);
                if ($pdf === null) {
                    Notification::make()->title('Το ΕΡΓΑΝΗ δεν επέστρεψε PDF')->body('Μια ανακληθείσα δήλωση δεν έχει πια PDF.')->warning()->send();

                    return null;
                }

                return response()->streamDownload(fn () => print ($pdf),
                    'ergani-'.$record->document.'-'.preg_replace('/[^\w]+/u', '-', (string) $record->protocol).'.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    /** What was sent and what came back — the audit detail (admins only, same policy). */
    public static function detailsAction(): Action
    {
        return Action::make('details')
            ->label('Λεπτομέρειες')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->modalHeading(fn (ErganiSubmission $record): string => (self::DOCUMENT_LABELS[$record->document] ?? $record->document).' · '.$record->created_at?->format('d/m/Y H:i'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Κλείσιμο')
            ->modalContent(fn (ErganiSubmission $record): HtmlString => new HtmlString(
                '<div style="display:grid;gap:.6rem;font-size:.85rem">'
                .'<div><strong>Μήνυμα:</strong> '.e((string) ($record->message ?? '—')).'</div>'
                .'<div><strong>Αίτημα:</strong><pre style="white-space:pre-wrap;max-height:18rem;overflow:auto;background:rgba(0,0,0,.04);padding:.5rem;border-radius:.4rem">'
                .e((string) json_encode($record->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre></div>'
                .'<div><strong>Απάντηση:</strong><pre style="white-space:pre-wrap;max-height:12rem;overflow:auto;background:rgba(0,0,0,.04);padding:.5rem;border-radius:.4rem">'
                .e(mb_substr((string) $record->response, 0, 5000)).'</pre></div></div>'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErganiSubmissions::route('/'),
        ];
    }
}
