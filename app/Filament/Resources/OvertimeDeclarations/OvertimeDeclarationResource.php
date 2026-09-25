<?php

namespace App\Filament\Resources\OvertimeDeclarations;

use App\Filament\Resources\OvertimeDeclarations\Pages\ListOvertimeDeclarations;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDeclaration;
use App\Services\Ergani\LeaveErganiSubmitter;
use App\Services\Ergani\OvertimeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Υπερωρίες» — declare an overtime slot to ΕΡΓΑΝΗ (WTOOv) BEFORE it starts
 * (company_admin), see every declaration and its ΕΡΓΑΝΗ state, retry a failed
 * one while it hasn't started. Never edited/deleted: a WTOOv can't be withdrawn.
 */
class OvertimeDeclarationResource extends Resource
{
    protected static ?string $model = OvertimeDeclaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Υπερωρίες';

    protected static ?string $modelLabel = 'υπερωρία';

    protected static ?string $pluralModelLabel = 'Υπερωρίες';

    protected static ?int $navigationSort = 17;

    protected static bool $isGloballySearchable = false;

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && parent::canAccess();
    }

    public static function erganiLabel(OvertimeDeclaration $record): string
    {
        $trial = $record->ergani_env === 'trial' ? ' (δοκιμαστικό)' : '';

        if (self::staleClaim($record)) {
            return 'Αβέβαιο — ελέγξτε στο ΕΡΓΑΝΗ';   // a crashed attempt: may have landed
        }

        return match ($record->ergani_status) {
            'submitted' => 'Δηλώθηκε'.$trial,
            'failed' => 'Δεν δηλώθηκε',
            'unknown' => 'Αβέβαιο — ελέγξτε στο ΕΡΓΑΝΗ',
            'submitting' => 'Σε εξέλιξη…',
            'superseded' => 'Αντικαταστάθηκε',
            default => '—',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->columns([
                TextColumn::make('work_date')->label('Ημέρα')->date('D d/m/Y')->sortable(),
                TextColumn::make('from_time')->label('Ώρες')
                    ->formatStateUsing(fn (OvertimeDeclaration $record): string => $record->from_time.'–'.$record->to_time),
                TextColumn::make('employee.last_name')->label('Εργαζόμενος')
                    ->formatStateUsing(fn (OvertimeDeclaration $record): string => (string) $record->employee?->full_name)
                    ->searchable(['last_name', 'first_name']),
                TextColumn::make('ergani_status')->label('ΕΡΓΑΝΗ')->badge()
                    ->formatStateUsing(fn (?string $state, OvertimeDeclaration $record): string => self::erganiLabel($record))
                    ->color(fn (?string $state, OvertimeDeclaration $record): string => self::staleClaim($record) ? 'warning' : match ($state) {
                        'submitted' => 'success',
                        'failed' => 'danger',
                        'unknown', 'submitting' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (OvertimeDeclaration $record): ?string => $record->ergani_protocol ? 'πρωτ. '.$record->ergani_protocol : null)
                    ->tooltip(fn (OvertimeDeclaration $record): ?string => $record->ergani_error)
                    ->placeholder('—'),
                TextColumn::make('createdBy.name')->label('Από')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')->label('Σημείωση')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')->label('Εργαζόμενος')
                    ->options(fn (): array => Employee::query()->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name])->all()),
            ])
            ->emptyStateHeading('Καμία υπερωρία')
            ->emptyStateDescription('Η υπερωρία δηλώνεται στο ΕΡΓΑΝΗ ΠΡΙΝ ξεκινήσει — πατήστε «Νέα υπερωρία» μόλις αποφασιστεί.')
            ->recordActions([self::retryAction()]);
    }

    /** Retry a failed / uncertain one — only while it hasn't started (else ΕΡΓΑΝΗ: εκπρόθεσμη). */
    public static function retryAction(): Action
    {
        return Action::make('retryOvertime')
            ->label('Δήλωση στο ΕΡΓΑΝΗ')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('warning')
            ->visible(fn (OvertimeDeclaration $record): bool => (in_array($record->ergani_status, [null, 'failed', 'unknown'], true) || self::staleClaim($record))
                && ! $record->hasStarted()
                && OvertimeService::enabledFor($record->company)
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (OvertimeDeclaration $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading(fn (OvertimeDeclaration $record): string => 'Δήλωση υπερωρίας — '.$record->employee?->full_name.' · '.$record->slotLabel())
            ->modalDescription(fn (OvertimeDeclaration $record): string => (self::needsConfirmation($record)
                    ? '⚠ Η προηγούμενη δήλωση έχει ΑΓΝΩΣΤΟ αποτέλεσμα. Συνεχίστε ΜΟΝΟ αν ελέγξατε στο ΕΡΓΑΝΗ ότι ΔΕΝ καταχωρήθηκε — δεν ανακαλείται. '
                    : '')
                .($record->company?->ergani_mode === 'production' ? '⚠ ΠΑΡΑΓΩΓΗ — πραγματική δήλωση.' : 'Δοκιμαστικό περιβάλλον.'))
            ->fillForm(fn (OvertimeDeclaration $record): array => [
                'seen' => self::needsConfirmation($record) ? 'unknown' : 'plain',
                'seen_mode' => $record->company?->ergani_mode,
            ])
            ->schema([Hidden::make('seen'), Hidden::make('seen_mode')])
            ->action(function (OvertimeDeclaration $record, array $data): void {
                if (($data['seen_mode'] ?? null) !== $record->company?->ergani_mode) {
                    Notification::make()->title('Το περιβάλλον ΕΡΓΑΝΗ άλλαξε στο μεταξύ — δεν στάλθηκε τίποτα.')->warning()->send();

                    return;
                }
                $ok = app(OvertimeService::class)->submit($record, (int) auth()->id(), confirmedUnknown: ($data['seen'] ?? null) === 'unknown');
                $record->refresh();
                $n = Notification::make()->title($ok ? 'Δηλώθηκε — πρωτ. '.$record->ergani_protocol : 'Δεν δηλώθηκε')->body($ok ? null : $record->ergani_error);
                $ok ? $n->success()->send() : $n->danger()->persistent()->send();
            });
    }

    /** «Unknown», or a «submitting» claim left by a crashed attempt — may have landed. */
    public static function needsConfirmation(OvertimeDeclaration $record): bool
    {
        return $record->ergani_status === 'unknown' || self::staleClaim($record);
    }

    public static function staleClaim(OvertimeDeclaration $record): bool
    {
        return $record->ergani_status === 'submitting'
            && $record->updated_at?->lt(now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeDeclarations::route('/'),
        ];
    }
}
