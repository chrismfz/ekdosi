<?php

namespace App\Filament\Resources\ExpenseClassificationRules;

use App\Filament\Resources\ExpenseClassificationRules\Pages\CreateExpenseClassificationRule;
use App\Filament\Resources\ExpenseClassificationRules\Pages\EditExpenseClassificationRule;
use App\Filament\Resources\ExpenseClassificationRules\Pages\ListExpenseClassificationRules;
use App\Filament\Resources\ExpenseClassificationRules\Schemas\ExpenseClassificationRuleForm;
use App\Filament\Resources\ExpenseClassificationRules\Tables\ExpenseClassificationRulesTable;
use App\Models\Company;
use App\Models\ExpenseClassificationRule;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * CRUD for the expense auto-classification rules (#5): «προμηθευτής (+ προαιρ.
 * τύπος) → χαρακτηρισμός». Setup-area, gr-mydata tenants only (a rule classifies
 * inbound myDATA expenses). ExpenseClassifier applies them on import + the bulk
 * «Εφαρμογή κανόνων» action.
 */
class ExpenseClassificationRuleResource extends Resource
{
    protected static ?string $model = ExpenseClassificationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'supplier_afm';

    public static function getModelLabel(): string
    {
        return 'Κανόνας χαρακτηρισμού';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Κανόνες χαρακτηρισμού';
    }

    public static function getNavigationLabel(): string
    {
        return 'Κανόνες χαρακτηρισμού';
    }

    /** myDATA-specific setup — hidden for tenants that don't file to myDATA. */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->einvoice_provider === 'gr-mydata'
            && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseClassificationRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseClassificationRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseClassificationRules::route('/'),
            'create' => CreateExpenseClassificationRule::route('/create'),
            'edit' => EditExpenseClassificationRule::route('/{record}/edit'),
        ];
    }
}
