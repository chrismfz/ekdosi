<?php

namespace App\Filament\Resources\Employees;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * «Εργαζόμενοι» — the staff roster (company_admin). Link a panel user so the
 * person can file their own leave (they need the operator or the «Προσωπικό
 * (μόνο άδειες)» role — set in Διαχείριση → Χρήστες). Deactivate, don't delete,
 * someone who left: their leave history stays.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Εργαζόμενοι';

    protected static ?string $modelLabel = 'εργαζόμενος';

    protected static ?string $pluralModelLabel = 'Εργαζόμενοι';

    protected static ?string $recordTitleAttribute = 'last_name';

    protected static ?int $navigationSort = 30;

    protected static bool $isGloballySearchable = false;

    /** Only for tenants with the Προσωπικό / ΕΡΓΑΝΗ pillar enabled (Company → «ΕΡΓΑΝΗ»). */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && parent::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        $tenantScoped = fn (Unique $rule): Unique => $rule->where('company_id', Filament::getTenant()?->getKey());

        return $schema->components([
            Section::make('Στοιχεία')
                ->columns(2)
                ->schema([
                    TextInput::make('last_name')->label('Επώνυμο')->required()->maxLength(100),
                    TextInput::make('first_name')->label('Όνομα')->required()->maxLength(100),
                    TextInput::make('afm')
                        ->label('ΑΦΜ')
                        ->regex('/^\d{9}$/')
                        ->unique(ignoreRecord: true, modifyRuleUsing: $tenantScoped)
                        ->validationMessages(['regex' => 'Ο ΑΦΜ έχει 9 ψηφία.', 'unique' => 'Υπάρχει ήδη εργαζόμενος με αυτόν τον ΑΦΜ (ίσως στους διαγραμμένους — φίλτρο «Διαγραμμένα» → Επαναφορά).'])
                        ->helperText('Όπως στο ΕΡΓΑΝΗ — χρειάζεται για τη δήλωση αδειών/κάρτας.'),
                    TextInput::make('email')->label('Email')->email()->maxLength(191),
                    DatePicker::make('hired_at')->label('Ημ/νία πρόσληψης')->native(false)->displayFormat('d/m/Y'),
                    Toggle::make('is_active')->label('Ενεργός')->default(true)->inline(false),
                ]),
            Section::make('Άδειες & πρόσβαση')
                ->columns(2)
                ->schema([
                    TextInput::make('annual_leave_days')
                        ->label('Ημέρες κανονικής άδειας / έτος')
                        ->numeric()->integer()->minValue(0)->maxValue(60)
                        ->default(20)->required()
                        ->helperText('Πενθήμερο: 20 τον 1ο χρόνο, αυξάνεται με την προϋπηρεσία — επιβεβαίωσε με τον λογιστή.'),
                    Select::make('user_id')
                        ->label('Λογαριασμός στο ekdosi')
                        ->options(fn (): array => Filament::getTenant()?->users()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (User $u): array => [$u->id => $u->name.' <'.$u->email.'>'])
                            ->all() ?? [])
                        ->searchable()
                        ->unique(ignoreRecord: true, modifyRuleUsing: $tenantScoped)
                        ->helperText('Για να ζητά ο ίδιος άδειες. Χωρίς λογαριασμό, τις καταχωρεί ο διαχειριστής.'),
                    TextInput::make('ergani_branch')
                        ->label('Α/Α παραρτήματος ΕΡΓΑΝΗ')
                        ->numeric()->integer()->minValue(0)->maxValue(255)->default(0)->required(),
                    Textarea::make('notes')->label('Σημειώσεις')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $year = (int) now()->format('Y');

        return $table
            // Balance in the same query (no per-row SUM).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum(
                ['leaveRequests as annual_taken' => fn (Builder $q) => $q
                    ->where('status', LeaveStatus::Approved->value)
                    ->where('type', LeaveType::Annual->value)
                    ->whereYear('starts_on', $year)],
                'days',
            ))
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Ονοματεπώνυμο')
                    ->formatStateUsing(fn (Employee $record): string => $record->full_name)
                    ->searchable(['last_name', 'first_name', 'afm'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('afm')->label('ΑΦΜ')->placeholder('—')->toggleable(),
                TextColumn::make('user.name')->label('Λογαριασμός')->placeholder('—'),
                TextColumn::make('balance')
                    ->label('Υπόλοιπο '.$year)
                    ->state(fn (Employee $record): string => ($record->annual_leave_days - (int) $record->annual_taken).' / '.$record->annual_leave_days)
                    ->alignEnd(),
                IconColumn::make('is_active')->label('Ενεργός')->boolean(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([RestoreBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
