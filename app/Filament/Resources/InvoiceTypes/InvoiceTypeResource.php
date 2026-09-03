<?php

namespace App\Filament\Resources\InvoiceTypes;

use App\Filament\Resources\InvoiceTypes\Pages\CreateInvoiceType;
use App\Filament\Resources\InvoiceTypes\Pages\EditInvoiceType;
use App\Filament\Resources\InvoiceTypes\Pages\ListInvoiceTypes;
use App\Filament\Resources\InvoiceTypes\Schemas\InvoiceTypeForm;
use App\Filament\Resources\InvoiceTypes\Tables\InvoiceTypesTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\ServiceContract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class InvoiceTypeResource extends Resource
{
    protected static ?string $model = InvoiceType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Ρυθμίσεις';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Τύπος παραστατικού';

    protected static ?string $pluralModelLabel = 'Τύποι παραστατικών';

    // Verbatim nav label — else Filament title-cases the plural to «Τύποι Παραστατικών».
    protected static ?string $navigationLabel = 'Τύποι παραστατικών';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     * Includes BOTH WHMCS-default FKs (invoice-type AND receipt-type — the latter
     * was missing from the old single-record map, a gap this closes everywhere).
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'invoice_type_id', $record->id),
            'δελτία αποστολής' => GuardedDeleteAction::count(DeliveryNote::class, 'delivery_type_id', $record->id),
            'συμβόλαια' => GuardedDeleteAction::count(ServiceContract::class, 'invoice_type_id', $record->id),
            'εταιρίες (προεπιλογή τιμολογίου WHMCS)' => GuardedDeleteAction::count(Company::class, 'whmcs_default_invoice_type_id', $record->id),
            'εταιρίες (προεπιλογή απόδειξης WHMCS)' => GuardedDeleteAction::count(Company::class, 'whmcs_default_receipt_type_id', $record->id),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoiceTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceTypes::route('/'),
            'create' => CreateInvoiceType::route('/create'),
            'edit' => EditInvoiceType::route('/{record}/edit'),
        ];
    }
}
