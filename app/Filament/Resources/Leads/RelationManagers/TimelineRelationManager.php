<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadActivity;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Χρονολόγιο — the lead's contact log, newest first, «στυλ calendar απλό σε
 * γραμμές». Four quick-add buttons (Τηλέφωνο / Email / Ραντεβού / Σημείωση)
 * open a pre-typed modal; each row can carry an «επόμενο βήμα» that lands on
 * the lead's `next_action_at`.
 *
 * Small convenience: logging a real contact (an answered call, a replied
 * email, a held meeting) on a lead that is still «Νέο» moves it to «Επικοινωνήσαμε» — the
 * funnel stays honest without an extra click. Status rows (auto) are shown but
 * not editable; manual rows are freely editable/deletable (owner decision).
 */
class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timeline';

    protected static ?string $title = 'Χρονολόγιο';

    protected static ?string $recordTitleAttribute = 'body';

    /** No LeadActivity policy — the parent page's authorization gates access. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    /** Edit form for an existing (manual) row. */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Είδος')
                    ->options(LeadActivityType::manualOptions())
                    ->required()
                    ->live(),

                DateTimePicker::make('happened_at')
                    ->label('Πότε')
                    ->seconds(false)
                    ->required(),

                Select::make('direction')
                    ->label('Κατεύθυνση')
                    ->options(LeadActivity::directionOptions())
                    ->visible(fn (Get $get): bool => self::typeOf($get('type'))?->hasDirection() ?? false),

                Select::make('outcome')
                    ->label('Αποτέλεσμα')
                    ->options(fn (Get $get): array => self::typeOf($get('type'))?->outcomes() ?? [])
                    ->visible(fn (Get $get): bool => (self::typeOf($get('type'))?->outcomes() ?? []) !== []),

                Textarea::make('body')
                    ->label('Τι ειπώθηκε / σημείωση')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('happened_at')
                    ->label('Πότε')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Είδος')
                    ->badge(),

                TextColumn::make('direction')
                    ->label('Κατεύθυνση')
                    ->formatStateUsing(fn (LeadActivity $record): ?string => $record->directionLabel())
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('outcome')
                    ->label('Αποτέλεσμα')
                    ->formatStateUsing(fn (LeadActivity $record): ?string => $record->outcomeLabel())
                    ->placeholder('—'),

                TextColumn::make('body')
                    ->label('Τι ειπώθηκε')
                    ->formatStateUsing(fn (LeadActivity $record): string => self::describe($record))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(200)
                    ->tooltip(fn (LeadActivity $record): ?string => mb_strlen((string) $record->body) > 200 ? $record->body : null),

                TextColumn::make('user.name')
                    ->label('Ποιος')
                    ->placeholder('Σύστημα'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Είδος')
                    ->options(collect(LeadActivityType::cases())
                        ->mapWithKeys(fn (LeadActivityType $t): array => [$t->value => $t->getLabel()])
                        ->all()),
            ])
            ->headerActions([
                $this->quickAdd(LeadActivityType::Call, 'primary'),
                $this->quickAdd(LeadActivityType::Email, 'info'),
                $this->quickAdd(LeadActivityType::Meeting, 'warning'),
                $this->quickAdd(LeadActivityType::Note, 'gray'),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (LeadActivity $record): bool => $record->type?->isManual() ?? false),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('happened_at', 'desc')
            ->emptyStateHeading('Καμία επαφή ακόμα')
            ->emptyStateDescription('Κατέγραψε το πρώτο τηλέφωνο ή email με τα κουμπιά πάνω δεξιά.');
    }

    /** One pre-typed «log a contact» button. */
    private function quickAdd(LeadActivityType $type, string $color): Action
    {
        return Action::make('log_'.$type->value)
            ->label($type->getLabel())
            ->icon($type->getIcon())
            ->color($color)
            ->modalHeading('Καταγραφή: '.$type->getLabel())
            ->modalSubmitActionLabel('Καταγραφή')
            ->modalWidth('lg')
            ->schema(array_values(array_filter([
                DateTimePicker::make('happened_at')
                    ->label('Πότε')
                    ->seconds(false)
                    ->default(now())
                    ->required(),

                $type->hasDirection()
                    ? Select::make('direction')
                        ->label('Κατεύθυνση')
                        ->options(LeadActivity::directionOptions())
                        ->default(LeadActivity::DIRECTION_OUTBOUND)
                        ->required()
                    : null,

                $type->outcomes() !== []
                    ? Select::make('outcome')
                        ->label('Αποτέλεσμα')
                        ->options($type->outcomes())
                        ->default(array_key_first($type->outcomes()))
                        ->required()
                    : null,

                Textarea::make('body')
                    ->label($type === LeadActivityType::Note ? 'Σημείωση' : 'Τι ειπώθηκε')
                    ->rows(3)
                    ->required($type === LeadActivityType::Note)
                    ->columnSpanFull(),

                DateTimePicker::make('next_action_at')
                    ->label('Επόμενο βήμα (προαιρετικό)')
                    ->seconds(false)
                    ->helperText('Ενημερώνει το «Επόμενο βήμα» του lead.')
                    ->columnSpanFull(),
            ])))
            ->action(fn (array $data) => $this->log($type, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(LeadActivityType $type, array $data): void
    {
        /** @var Lead $lead */
        $lead = $this->getOwnerRecord();

        $lead->timeline()->create([
            'company_id' => $lead->company_id,
            'user_id' => auth()->id(),
            'type' => $type->value,
            'direction' => $data['direction'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'happened_at' => $data['happened_at'] ?? now(),
            'body' => filled($data['body'] ?? null) ? $data['body'] : null,
        ]);

        $updates = [];

        if (filled($data['next_action_at'] ?? null)) {
            $updates['next_action_at'] = Carbon::parse($data['next_action_at']);
        }

        if ($lead->status === LeadStatus::New && self::countsAsContact($type, $data['outcome'] ?? null)) {
            $updates['status'] = LeadStatus::Contacted;
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        Notification::make()
            ->title('Καταγράφηκε: '.$type->getLabel())
            ->success()
            ->send();
    }

    /** A real two-way contact happened (not a missed call / bounce / no-show). */
    private static function countsAsContact(LeadActivityType $type, ?string $outcome): bool
    {
        return match ($type) {
            LeadActivityType::Call => in_array($outcome, ['answered', 'callback'], true),
            LeadActivityType::Email => $outcome === 'replied',
            LeadActivityType::Meeting => $outcome === 'held',
            default => false,
        };
    }

    /** Human line for system rows (status change «Νέο → Επικοινωνήσαμε»). */
    private static function describe(LeadActivity $record): string
    {
        if ($record->type === LeadActivityType::StatusChange) {
            $from = LeadStatus::tryFrom((string) ($record->meta['from'] ?? ''))?->getLabel() ?? '—';
            $to = LeadStatus::tryFrom((string) ($record->meta['to'] ?? ''))?->getLabel() ?? '—';
            $line = $from.' → '.$to;

            return $record->body ? $line.' — '.$record->body : $line;
        }

        return (string) $record->body;
    }

    private static function typeOf(mixed $value): ?LeadActivityType
    {
        return $value instanceof LeadActivityType ? $value : LeadActivityType::tryFrom((string) $value);
    }
}
