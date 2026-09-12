<?php

namespace App\Filament\Resources\PaymentGatewayEvents;

use App\Filament\Resources\PaymentGatewayEvents\Pages\ListPaymentGatewayEvents;
use App\Filament\Resources\PaymentGatewayEvents\Tables\PaymentGatewayEventsTable;
use App\Models\PaymentGatewayEvent;
use App\Models\Scopes\CompanyScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «Log πύλης» — every inbound gateway notification (a vPOS return), read-only.
 * The debugging surface for «πλήρωσα, δεν φαίνεται»: see that the return arrived,
 * its signed status, whether the digest verified, and — if not settled — why.
 * Super-admin only (contains IPs + security-reject reasons); written by the
 * webhook, never here — so no create/edit page.
 */
class PaymentGatewayEventResource extends Resource
{
    protected static ?string $model = PaymentGatewayEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Πύλη πελατών';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Εγγραφή Log πύλης';

    protected static ?string $pluralModelLabel = 'Log πύλης';

    protected static ?string $navigationLabel = 'Log πύλης';

    // NOT tenant-scoped: a forged/unknown-orderid return has no resolvable company
    // (company_id = null), so a tenant filter would hide the very «άγνωστο orderid»
    // case this log exists to surface. Super-admin only (see canAccess), so the
    // cross-tenant view is both safe and the right debugging lens.
    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSystemSuperAdmin();
    }

    /** Drop the ambient CompanyScope too (Filament still sets it from the selected tenant). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(CompanyScope::class);
    }

    public static function table(Table $table): Table
    {
        return PaymentGatewayEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGatewayEvents::route('/'),
        ];
    }
}
