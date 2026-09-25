<?php

namespace App\Filament\Resources\WorkCardEvents\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\WorkCardEvents\WorkCardEventResource;
use App\Models\Employee;
use App\Models\WorkCardEvent;
use App\Services\Ergani\WorkCardRefused;
use App\Services\Ergani\WorkCardService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

class ListWorkCardEvents extends BaseListRecords
{
    protected static string $resource = WorkCardEventResource::class;

    public function getSubheading(): ?string
    {
        return 'Μια κάρτα δεν ανακαλείται στο ΕΡΓΑΝΗ. Κινήσεις μπορούν να προστεθούν μόνο μετά την τελευταία κίνηση του εργαζομένου· '
            .'διορθώσεις σε παλαιότερες (ξεχασμένη έξοδος, λάθος ώρα) γίνονται απευθείας στο ΕΡΓΑΝΗ.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // A movement the employee couldn't punch (forgot the phone, power cut…):
            // recorded as «Διαχειριστής»; older than 15' → needs a late reason.
            Action::make('manualPunch')
                ->label('Χειροκίνητη κίνηση')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => auth()->user()?->can('create', WorkCardEvent::class) ?? false)
                ->modalDescription('Η κίνηση δηλώνεται στο ΕΡΓΑΝΗ (αν είναι ενεργή η αυτόματη υποβολή) και ΔΕΝ ανακαλείται.')
                ->schema([
                    Select::make('employee_id')->label('Εργαζόμενος')->required()->searchable()
                        ->options(fn (): array => Employee::query()->where('company_id', Filament::getTenant()?->getKey())
                            ->where('is_active', true)->orderBy('last_name')->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name])->all()),
                    Select::make('type')->label('Κίνηση')->required()
                        ->options([WorkCardEvent::IN => 'Είσοδος', WorkCardEvent::OUT => 'Έξοδος']),
                    DateTimePicker::make('occurred_at')->label('Ώρα')->required()->seconds(false)->native(false)
                        ->displayFormat('d/m/Y H:i')->default(now())->maxDate(now()->addMinute())->live(),
                    Select::make('late_reason')->label('Αιτιολογία εκπρόθεσμης υποβολής')
                        ->options(WorkCardEvent::LATE_REASONS)
                        ->helperText('Απαιτείται όταν η κίνηση είναι παλαιότερη των 15 λεπτών.')
                        ->required(fn (Get $get): bool => filled($get('occurred_at')) && CarbonImmutable::parse($get('occurred_at'))->lt(now()->subMinutes(WorkCardService::DEADLINE_MINUTES))),
                    TextInput::make('note')->label('Σημείωση')->maxLength(200),
                ])
                ->action(function (array $data): void {
                    $employee = Employee::query()->where('company_id', Filament::getTenant()?->getKey())->find($data['employee_id']);
                    if (! $employee instanceof Employee) {
                        return;
                    }
                    try {
                        $event = app(WorkCardService::class)->punch($employee, 'admin', (int) auth()->id(), $data['type'],
                            CarbonImmutable::parse($data['occurred_at']), $data['note'] ?? null, $data['late_reason'] ?? null);
                    } catch (WorkCardRefused $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $n = Notification::make()->title($event->typeLabel().' '.$event->occurred_at->format('d/m H:i').' καταχωρήθηκε')
                        ->body(match ($event->ergani_status) {
                            'submitted' => 'ΕΡΓΑΝΗ: πρωτ. '.$event->ergani_protocol,
                            'failed', 'unknown' => 'ΕΡΓΑΝΗ: '.$event->ergani_error,
                            default => null,
                        });
                    in_array($event->ergani_status, ['failed', 'unknown'], true) ? $n->danger()->persistent()->send() : $n->success()->send();
                }),
        ];
    }
}
