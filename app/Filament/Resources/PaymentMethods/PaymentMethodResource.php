<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Resources\PaymentMethods\Schemas\PaymentMethodForm;
use App\Filament\Resources\PaymentMethods\Tables\PaymentMethodsTable;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
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

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'description';

    /**
     * SET-2: dependent counts blocking deletion (single + bulk + force). One source.
     *
     * @return array<string, int>
     */
    public static function dependents(Model $record): array
    {
        return [
            'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'payment_method_id', $record->id),
            'πληρωμές' => GuardedDeleteAction::count(Payment::class, 'payment_method_id', $record->id),
            'πελάτες' => GuardedDeleteAction::count(Customer::class, 'payment_method_id', $record->id),
            'συμβόλαια' => GuardedDeleteAction::count(ServiceContract::class, 'payment_method_id', $record->id),
            'τύποι παραστατικών (προεπιλογή)' => GuardedDeleteAction::count(InvoiceType::class, 'payment_method_id', $record->id),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentMethodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
            'create' => CreatePaymentMethod::route('/create'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
