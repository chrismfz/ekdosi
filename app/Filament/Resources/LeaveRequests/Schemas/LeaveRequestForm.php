<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use App\Enums\LeaveType;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Models\Employee;
use App\Policies\LeaveRequestPolicy;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * «Νέο αίτημα άδειας». Staff file for themselves (employee picker hidden — the
 * page binds their own record); approvers pick anyone and may approve at once.
 * `days` is pre-filled with the working-day count (weekends + national +
 * company holidays excluded) — approvers may adjust it, staff may not (the
 * create page recomputes it server-side for them).
 */
class LeaveRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('employee_id')
                        ->label('Εργαζόμενος')
                        ->options(fn (): array => Employee::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('is_active', true)
                            ->orderBy('last_name')->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->visible(fn (): bool => self::approver())
                        ->columnSpanFull(),

                    Select::make('type')
                        ->label('Είδος άδειας')
                        ->options(LeaveType::class)
                        ->default(LeaveType::Annual->value)
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    DatePicker::make('starts_on')
                        ->label('Από')
                        ->minDate(fn (): string => now()->subYears(2)->startOfYear()->toDateString())
                        ->maxDate(fn (): string => now()->addYears(2)->endOfYear()->toDateString())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recount($get, $set)),

                    DatePicker::make('ends_on')
                        ->label('Έως (και)')
                        ->minDate(fn (): string => now()->subYears(2)->startOfYear()->toDateString())
                        ->maxDate(fn (): string => now()->addYears(2)->endOfYear()->toDateString())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->afterOrEqual('starts_on')
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recount($get, $set)),

                    TextInput::make('days')
                        ->label('Εργάσιμες ημέρες')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(366)
                        ->required()
                        ->disabled(fn (): bool => ! self::approver())
                        ->dehydrated()
                        ->helperText('Χωρίς Σαββατοκύριακα, εθνικές και τοπικές αργίες της εταιρείας.'),

                    Placeholder::make('balance')
                        ->label('Υπόλοιπο κανονικής άδειας')
                        ->content(fn (Get $get): string => self::balanceText($get)),

                    Textarea::make('reason')
                        ->label('Σημείωση')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),

                    Toggle::make('approve_now')
                        ->label('Έγκριση αμέσως')
                        ->helperText('Καταχωρεί την άδεια ως εγκεκριμένη και ενημερώνει τον λογιστή.')
                        ->visible(fn (): bool => self::approver())
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function approver(): bool
    {
        return LeaveRequestPolicy::isApprover(auth()->user());
    }

    private static function recount(Get $get, Set $set): void
    {
        $from = $get('starts_on');
        $to = $get('ends_on');
        $tenant = Filament::getTenant();
        if (blank($from) || $tenant === null) {
            return;
        }
        if (blank($to) || $to < $from) {
            $set('ends_on', $from);
            $to = $from;
        }

        $from = CarbonImmutable::parse($from);
        $to = CarbonImmutable::parse($to);
        if ($from->diffInDays($to) > CreateLeaveRequest::MAX_SPAN_DAYS) {
            return; // the create page refuses it anyway — don't walk the span
        }

        $set('days', WorkingDays::for((int) $tenant->getKey())->count($from, $to));
    }

    private static function balanceText(Get $get): string
    {
        $tenant = Filament::getTenant();
        $employee = self::approver()
            ? Employee::query()->where('company_id', $tenant?->getKey())->find($get('employee_id'))
            : Employee::forUser(auth()->user(), (int) $tenant?->getKey());

        if (! $employee instanceof Employee) {
            return '—';
        }

        $year = blank($get('starts_on')) ? (int) now()->format('Y') : (int) CarbonImmutable::parse($get('starts_on'))->format('Y');

        return sprintf('%d από %d ημέρες (%d)', $employee->annualLeaveRemaining($year), $employee->annual_leave_days, $year);
    }
}
