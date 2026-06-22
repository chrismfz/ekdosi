<?php

namespace App\Filament\Resources\Cmr;

use App\Filament\Resources\Cmr\Pages\CreateCmr;
use App\Filament\Resources\Cmr\Pages\EditCmr;
use App\Filament\Resources\Cmr\Pages\ListCmr;
use App\Filament\Resources\Cmr\Schemas\CmrForm;
use App\Filament\Resources\Cmr\Tables\CmrTable;
use App\Models\CmrNote;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * CMR — international road consignment notes. A TRANSPORT document (not myDATA),
 * first-class with an optional source (Invoice | Δελτίο Αποστολής | standalone).
 * Created blank here (standalone) or pre-filled via the «Δημιουργία CMR» action
 * on an invoice / delivery note. Grouped next to Παραστατικά Διακίνησης for
 * discoverability (it is NOT itself a myDATA e-transport document).
 *
 * Gated on View:Cmr — Gate::can is 404-storm-safe (missing permission → false,
 * not a throw), so it's silent until shield:generate creates the permission.
 */
class CmrResource extends Resource
{
    protected static ?string $model = CmrNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Ψηφιακή Διακίνηση';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference_no';

    public static function getNavigationLabel(): string
    {
        return 'CMR (φορτωτικές)';
    }

    public static function getModelLabel(): string
    {
        return 'CMR';
    }

    public static function getPluralModelLabel(): string
    {
        return 'CMR';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:CmrNote');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference_no', 'consignee_text'];
    }

    public static function form(Schema $schema): Schema
    {
        return CmrForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CmrTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmr::route('/'),
            'create' => CreateCmr::route('/create'),
            'edit' => EditCmr::route('/{record}/edit'),
        ];
    }
}
