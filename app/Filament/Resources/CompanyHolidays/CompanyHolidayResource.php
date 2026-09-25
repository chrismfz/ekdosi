<?php

namespace App\Filament\Resources\CompanyHolidays;

use App\Enums\HolidayRule;
use App\Filament\Resources\CompanyHolidays\Pages\ManageCompanyHolidays;
use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Support\Hr\GreekHolidays;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Τοπικές αργίες» — the tenant's extra non-working days (πολιούχος,
 * απελευθέρωση, office closures) on top of the national ones, which are
 * built in (GreekHolidays). No official list exists — keep the ones the office
 * actually observes. They're excluded from leave day counts and shown on the
 * leave calendar.
 */
class CompanyHolidayResource extends Resource
{
    protected static ?string $model = CompanyHoliday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Τοπικές αργίες';

    protected static ?string $modelLabel = 'αργία';

    protected static ?string $pluralModelLabel = 'Τοπικές αργίες';

    protected static ?int $navigationSort = 40;

    protected static bool $isGloballySearchable = false;

    /** Only for tenants with the Προσωπικό / ΕΡΓΑΝΗ pillar enabled (Company → «ΕΡΓΑΝΗ»). */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        $year = (int) now()->format('Y');

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Ονομασία')->placeholder('π.χ. Πολιούχος Ξάνθης — Αγ. Ιωάννης ο Πρόδρομος')
                ->required()->maxLength(120)->columnSpanFull(),
            Select::make('rule')->label('Επανάληψη')->options(HolidayRule::class)
                ->default(HolidayRule::Fixed->value)->required()->live()->columnSpanFull(),
            TextInput::make('day')->label('Ημέρα')->numeric()->integer()->minValue(1)->maxValue(31)
                ->visible(fn (Get $get): bool => self::rule($get) === HolidayRule::Fixed)
                ->required(fn (Get $get): bool => self::rule($get) === HolidayRule::Fixed),
            TextInput::make('month')->label('Μήνας')->numeric()->integer()->minValue(1)->maxValue(12)
                ->visible(fn (Get $get): bool => self::rule($get) === HolidayRule::Fixed)
                ->required(fn (Get $get): bool => self::rule($get) === HolidayRule::Fixed),
            TextInput::make('easter_offset')->label('Ημέρες από την Κυριακή του Πάσχα')->numeric()->integer()
                ->minValue(-70)->maxValue(70)
                ->helperText('Αρνητικό = πριν. Π.χ. +50 = Αγίου Πνεύματος. Πάσχα '.$year.': '.GreekHolidays::orthodoxEaster($year)->format('d/m/Y'))
                ->visible(fn (Get $get): bool => self::rule($get) === HolidayRule::Easter)
                ->required(fn (Get $get): bool => self::rule($get) === HolidayRule::Easter)
                ->columnSpanFull(),
            DatePicker::make('date')->label('Ημερομηνία')->native(false)->displayFormat('d/m/Y')
                ->visible(fn (Get $get): bool => self::rule($get) === HolidayRule::Once)
                ->required(fn (Get $get): bool => self::rule($get) === HolidayRule::Once)
                ->columnSpanFull(),
            Toggle::make('is_active')->label('Ενεργή')->default(true)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $year = (int) now()->format('Y');

        return $table
            ->columns([
                TextColumn::make('name')->label('Ονομασία')->searchable()->weight('medium'),
                TextColumn::make('rule')->label('Επανάληψη')
                    ->formatStateUsing(fn (CompanyHoliday $record): string => $record->ruleLabel()),
                TextColumn::make('this_year')->label('Φέτος')
                    ->state(fn (CompanyHoliday $record): ?string => ($d = $record->dateInYear($year))
                        ? CarbonImmutable::parse($d)->locale('el')->isoFormat('dd D/M')
                        : null)
                    ->placeholder('—'),
                IconColumn::make('is_active')->label('Ενεργή')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyHolidays::route('/'),
        ];
    }

    private static function rule(Get $get): ?HolidayRule
    {
        $value = $get('rule');

        return $value instanceof HolidayRule ? $value : HolidayRule::tryFrom((string) $value);
    }
}
