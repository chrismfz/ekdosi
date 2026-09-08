<?php

namespace App\Filament\Resources\DeliveryMethods;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\DeliveryMethods\Pages\CreateDeliveryMethod;
use App\Filament\Resources\DeliveryMethods\Pages\EditDeliveryMethod;
use App\Filament\Resources\DeliveryMethods\Pages\ListDeliveryMethods;
use App\Filament\Resources\DeliveryMethods\Schemas\DeliveryMethodForm;
use App\Filament\Resources\DeliveryMethods\Tables\DeliveryMethodsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryMethod;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeliveryMethodResource extends Resource
{
    protected static ?string $model = DeliveryMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Είδη & αποστολή';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Τρόπος αποστολής';

    protected static ?string $pluralModelLabel = 'Τρόποι αποστολής';

    // Verbatim nav label — else Filament title-cases the plural.
    protected static ?string $navigationLabel = 'Τρόποι αποστολής';

    protected static ?string $recordTitleAttribute = 'description';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'delivery_method_id', $record->id),
            'δελτία αποστολής' => GuardedDeleteAction::count(DeliveryNote::class, 'delivery_method_id', $record->id),
            'τύποι παραστατικών (προεπιλογή)' => GuardedDeleteAction::count(InvoiceType::class, 'delivery_method_id', $record->id),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryMethodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryMethods::route('/'),
            'create' => CreateDeliveryMethod::route('/create'),
            'edit' => EditDeliveryMethod::route('/{record}/edit'),
        ];
    }
}
