<?php

namespace App\Filament\Resources\PaymentGatewayConnections;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Resources\PaymentGatewayConnections\Pages\CreatePaymentGatewayConnection;
use App\Filament\Resources\PaymentGatewayConnections\Pages\EditPaymentGatewayConnection;
use App\Filament\Resources\PaymentGatewayConnections\Pages\ListPaymentGatewayConnections;
use App\Filament\Resources\PaymentGatewayConnections\Schemas\PaymentGatewayConnectionForm;
use App\Filament\Resources\PaymentGatewayConnections\Tables\PaymentGatewayConnectionsTable;
use App\Models\PaymentGatewayConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * «Τρόποι online πληρωμής» — per-tenant payment gateways (Πυλώνας B / B0). The
 * WHMCS-style list: add a method, enable/disable it, name it, set its settings.
 * Runtime behaviour is resolved by the `gateway` key through
 * PaymentGatewayRegistry; adding a gateway is a config line + a class, not a
 * resource edit. Super-admin only — these carry credentials (kept off the
 * company_admin CompanySettings page, like the other credential knobs).
 */
class PaymentGatewayConnectionResource extends Resource
{
    protected static ?string $model = PaymentGatewayConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'Τρόπος online πληρωμής';

    protected static ?string $pluralModelLabel = 'Τρόποι online πληρωμής';

    // Verbatim nav label — else Filament title-cases the plural.
    protected static ?string $navigationLabel = 'Τρόποι online πληρωμής';

    protected static ?string $recordTitleAttribute = 'label';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSystemSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentGatewayConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentGatewayConnectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGatewayConnections::route('/'),
            'create' => CreatePaymentGatewayConnection::route('/create'),
            'edit' => EditPaymentGatewayConnection::route('/{record}/edit'),
        ];
    }
}
