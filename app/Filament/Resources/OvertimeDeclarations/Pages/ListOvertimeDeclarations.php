<?php

namespace App\Filament\Resources\OvertimeDeclarations\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\OvertimeDeclarations\OvertimeDeclarationResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeDeclaration;
use App\Services\Ergani\OvertimeRefused;
use App\Services\Ergani\OvertimeService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

class ListOvertimeDeclarations extends BaseListRecords
{
    protected static string $resource = OvertimeDeclarationResource::class;

    public function getSubheading(): ?string
    {
        return 'Η υπερωρία δηλώνεται στο ΕΡΓΑΝΗ ΠΡΙΝ ξεκινήσει (αλλιώς θεωρείται εκπρόθεσμη) και ΔΕΝ ανακαλείται μέσω ekdosi. '
            .'Ο λογιστής λαμβάνει ενημέρωση για τη μισθοδοσία.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('declareOvertime')
                ->label('Νέα υπερωρία')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => OvertimeService::enabledFor($this->tenant()))
                ->authorize(fn (): bool => auth()->user()?->can('create', OvertimeDeclaration::class) ?? false)
                ->modalHeading('Δήλωση υπερωρίας στο ΕΡΓΑΝΗ')
                ->modalDescription(fn (): string => ($this->tenant()->ergani_mode === 'production'
                    ? '⚠ ΠΑΡΑΓΩΓΗ — η υπερωρία δηλώνεται ΠΡΑΓΜΑΤΙΚΑ, αμέσως, και δεν ανακαλείται. '
                    : 'Δοκιμαστικό περιβάλλον ΕΡΓΑΝΗ. ')
                    .'Δηλώνεται μόνο αν δεν έχει ξεκινήσει ακόμη.')
                ->modalSubmitActionLabel('Δήλωση')
                ->fillForm(fn (): array => [
                    'work_date' => now('Europe/Athens')->toDateString(),
                    'seen_mode' => $this->tenant()->ergani_mode,
                ])
                ->schema([
                    Select::make('employee_id')->label('Εργαζόμενος')->required()->searchable()
                        ->options(fn (): array => Employee::query()->where('company_id', $this->tenant()->getKey())
                            ->where('is_active', true)->whereNotNull('afm')->orderBy('last_name')->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name])->all())
                        ->helperText('Μόνο ενεργοί με ΑΦΜ.'),
                    DatePicker::make('work_date')->label('Ημέρα')->required()->native(false)->displayFormat('d/m/Y')
                        ->minDate(now('Europe/Athens')->startOfDay()),
                    TextInput::make('from_time')->label('Από (ΩΩ:ΛΛ)')->required()->placeholder('18:00')
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->validationMessages(['regex' => 'Π.χ. 18:00']),
                    TextInput::make('to_time')->label('Έως (ΩΩ:ΛΛ)')->required()->placeholder('20:00')
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->validationMessages(['regex' => 'Π.χ. 20:00'])
                        ->helperText(fn (Get $get): ?string => preg_match('/^\d\d:\d\d$/', (string) $get('from_time')) && preg_match('/^\d\d:\d\d$/', (string) $get('to_time'))
                            && ($m = OvertimeDeclaration::minutes((string) $get('from_time'), (string) $get('to_time'))) > 0
                            ? 'Διάρκεια: '.intdiv($m, 60).'ω'.($m % 60 ? ' '.($m % 60).'\'' : '') : null)
                        ->live(onBlur: true),
                    TextInput::make('note')->label('Σημείωση (εσωτερική — δεν πάει στο ΕΡΓΑΝΗ)')->maxLength(200),
                    Hidden::make('seen_mode'),
                ])
                ->action(function (array $data): void {
                    if (($data['seen_mode'] ?? null) !== $this->tenant()->fresh()->ergani_mode) {
                        Notification::make()->title('Το περιβάλλον ΕΡΓΑΝΗ άλλαξε στο μεταξύ — δεν στάλθηκε τίποτα.')->warning()->send();

                        return;
                    }
                    $employee = Employee::query()->where('company_id', $this->tenant()->getKey())->findOrFail($data['employee_id']);
                    try {
                        $o = app(OvertimeService::class)->declare($employee, (string) $data['work_date'], (string) $data['from_time'],
                            (string) $data['to_time'], (int) auth()->id(), $data['note'] ?? null);
                    } catch (OvertimeRefused $e) {
                        Notification::make()->title('Δεν δηλώθηκε')->body($e->getMessage())->danger()->send();

                        return;
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Αβέβαιο αποτέλεσμα — ελέγξτε τη λίστα και το ΕΡΓΑΝΗ πριν ξαναδηλώσετε.')->danger()->persistent()->send();

                        return;
                    }
                    $n = Notification::make()
                        ->title($o->ergani_status === 'submitted' ? 'Δηλώθηκε — πρωτ. '.$o->ergani_protocol : 'Καταχωρήθηκε αλλά ΔΕΝ δηλώθηκε στο ΕΡΓΑΝΗ')
                        ->body($o->ergani_status === 'submitted' ? $employee->full_name.' · '.$o->slotLabel() : $o->ergani_error);
                    $o->ergani_status === 'submitted' ? $n->success()->send() : $n->danger()->persistent()->send();
                }),
        ];
    }

    private function tenant(): Company
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }
}
