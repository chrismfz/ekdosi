<?php

namespace App\Filament\Resources\DistributionAims;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\DistributionAims\Pages\CreateDistributionAim;
use App\Filament\Resources\DistributionAims\Pages\EditDistributionAim;
use App\Filament\Resources\DistributionAims\Pages\ListDistributionAims;
use App\Filament\Resources\DistributionAims\Schemas\DistributionAimForm;
use App\Filament\Resources\DistributionAims\Tables\DistributionAimsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryNote;
use App\Models\DistributionAim;
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

class DistributionAimResource extends Resource
{
    protected static ?string $model = DistributionAim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Σκοπός διακίνησης';

    protected static ?string $pluralModelLabel = 'Σκοποί διακίνησης';

    // Verbatim nav label — else Filament title-cases the plural.
    protected static ?string $navigationLabel = 'Σκοποί διακίνησης';

    protected static ?string $recordTitleAttribute = 'description';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'distribution_aim_id', $record->id),
            'δελτία αποστολής' => GuardedDeleteAction::count(DeliveryNote::class, 'distribution_aim_id', $record->id),
            'τύποι παραστατικών (προεπιλογή)' => GuardedDeleteAction::count(InvoiceType::class, 'distribution_aim_id', $record->id),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return DistributionAimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DistributionAimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDistributionAims::route('/'),
            'create' => CreateDistributionAim::route('/create'),
            'edit' => EditDistributionAim::route('/{record}/edit'),
        ];
    }
}
