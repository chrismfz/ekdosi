<?php

namespace App\Filament\Resources\WorkCardEvents;

use App\Filament\Resources\WorkCardEvents\Pages\ListWorkCardEvents;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkCardEvent;
use App\Services\Ergani\LeaveErganiSubmitter;
use App\Services\Ergani\WorkCardService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «Κάρτες εργασίας» — every «χτύπημα» of the tenant (company_admin): who / when
 * / how, and its ΕΡΓΑΝΗ state; retry a failed one (late → with an f_aitiologia
 * code) and add a movement by hand. Read-only otherwise: a declared card can't
 * be withdrawn from ΕΡΓΑΝΗ, so ekdosi never edits or deletes one.
 */
class WorkCardEventResource extends Resource
{
    protected static ?string $model = WorkCardEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Κάρτες εργασίας';

    protected static ?string $modelLabel = 'κίνηση κάρτας';

    protected static ?string $pluralModelLabel = 'Κάρτες εργασίας';

    protected static ?int $navigationSort = 15;

    protected static bool $isGloballySearchable = false;

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'company']);
    }

    public static function erganiLabel(WorkCardEvent $record): string
    {
        $trial = $record->ergani_env === 'trial' ? ' (δοκ.)' : '';

        return match ($record->ergani_status) {
            'submitted' => 'Δηλώθηκε'.$trial.($record->late_reason ? ' · εκπρόθεσμα' : ''),
            'failed' => 'Αποτυχία',
            'unknown' => 'Άγνωστο — έλεγχος',
            'submitting' => 'Σε εξέλιξη…',
            default => '—',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Ώρα')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('employee.last_name')->label('Εργαζόμενος')
                    ->formatStateUsing(fn (WorkCardEvent $record): string => (string) $record->employee?->full_name)
                    ->searchable(['last_name', 'first_name']),
                TextColumn::make('type')->label('Κίνηση')->badge()
                    ->formatStateUsing(fn (WorkCardEvent $record): string => $record->typeLabel())
                    ->color(fn (string $state): string => $state === WorkCardEvent::IN ? 'success' : 'gray'),
                TextColumn::make('source')->label('Πηγή')->formatStateUsing(fn (WorkCardEvent $record): string => $record->sourceLabel()),
                TextColumn::make('ergani_status')->label('ΕΡΓΑΝΗ')->badge()
                    ->formatStateUsing(fn (?string $state, WorkCardEvent $record): string => self::erganiLabel($record))
                    ->color(fn (?string $state): string => match ($state) {
                        'submitted' => 'success',
                        'failed' => 'danger',
                        'unknown', 'submitting' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (WorkCardEvent $record): ?string => $record->ergani_error ?: $record->ergani_protocol)
                    ->placeholder('—'),
                TextColumn::make('note')->label('Σημείωση')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')->label('Εργαζόμενος')
                    ->options(fn (): array => Employee::query()->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name])->all()),
                SelectFilter::make('ergani_status')->label('ΕΡΓΑΝΗ')->options([
                    'submitted' => 'Δηλώθηκε', 'failed' => 'Αποτυχία', 'unknown' => 'Άγνωστο', 'submitting' => 'Σε εξέλιξη',
                ]),
                Filter::make('today')->label('Σήμερα')->query(fn (Builder $query): Builder => $query->where('occurred_at', '>=', now()->startOfDay())),
            ])
            ->recordActions([self::submitAction()]);
    }

    /** Declare (retry) one movement; late ones need an f_aitiologia code; «unknown» needs confirmation. */
    public static function submitAction(): Action
    {
        return Action::make('submitCard')
            ->label('Δήλωση στο ΕΡΓΑΝΗ')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('warning')
            ->visible(fn (WorkCardEvent $record): bool => (in_array($record->ergani_status, [null, 'failed', 'unknown'], true) || self::staleClaim($record))
                && WorkCardService::enabledFor($record->company)
                && (bool) $record->employee?->has_work_card
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (WorkCardEvent $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading(fn (WorkCardEvent $record): string => 'Δήλωση κάρτας — '.$record->employee?->full_name.' · '.$record->typeLabel().' '.$record->occurred_at->format('d/m H:i'))
            ->modalDescription(fn (WorkCardEvent $record): string => (self::needsConfirmation($record)
                    ? '⚠ Η προηγούμενη δήλωση έχει ΑΓΝΩΣΤΟ αποτέλεσμα. Συνεχίστε ΜΟΝΟ αν ελέγξατε στο ΕΡΓΑΝΗ ότι ΔΕΝ καταχωρήθηκε — μια κάρτα δεν ανακαλείται. '
                    : '')
                .($record->company?->ergani_mode === 'production' ? '⚠ ΠΑΡΑΓΩΓΗ — πραγματική δήλωση.' : 'Δοκιμαστικό περιβάλλον.'))
            ->fillForm(fn (WorkCardEvent $record): array => [
                'seen' => self::needsConfirmation($record) ? 'unknown' : 'plain',
                'seen_mode' => $record->company?->ergani_mode,
            ])
            ->schema(fn (WorkCardEvent $record): array => [
                Hidden::make('seen'),
                Hidden::make('seen_mode'),
                Select::make('late_reason')
                    ->label('Αιτιολογία εκπρόθεσμης υποβολής')
                    ->options(WorkCardEvent::LATE_REASONS)
                    ->required()
                    ->visible($record->isLate()),
            ])
            ->action(function (WorkCardEvent $record, array $data): void {
                if (($data['seen_mode'] ?? null) !== $record->company?->ergani_mode) {
                    Notification::make()->title('Το περιβάλλον ΕΡΓΑΝΗ άλλαξε στο μεταξύ — δεν στάλθηκε τίποτα.')->warning()->send();

                    return;
                }
                $ok = app(WorkCardService::class)->submit($record, (int) auth()->id(), $data['late_reason'] ?? null,
                    confirmedUnknown: ($data['seen'] ?? null) === 'unknown');
                $record->refresh();
                $n = Notification::make()->title($ok ? 'Δηλώθηκε — πρωτ. '.$record->ergani_protocol : 'Δεν δηλώθηκε')->body($ok ? null : $record->ergani_error);
                $ok ? $n->success()->send() : $n->danger()->persistent()->send();
            });
    }

    /** «Unknown», or a «submitting» claim left by a crashed attempt — may have landed. */
    public static function needsConfirmation(WorkCardEvent $record): bool
    {
        return $record->ergani_status === 'unknown' || self::staleClaim($record);
    }

    private static function staleClaim(WorkCardEvent $record): bool
    {
        return $record->ergani_status === 'submitting'
            && $record->updated_at?->lt(now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkCardEvents::route('/'),
        ];
    }
}
