<?php

namespace App\Filament\Resources\PaymentIntents;

use App\Filament\Resources\PaymentIntents\Pages\ListPaymentIntents;
use App\Filament\Resources\PaymentIntents\Tables\PaymentIntentsTable;
use App\Models\PaymentIntent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Εκκρεμείς πληρωμές πύλης» (Πυλώνας B / B0b) — payment intents customers
 * started from the portal, awaiting confirmation. Read-only + a «Καταχώριση
 * πληρωμής» action (manual): confirming writes the real Payment via
 * PaymentAllocator and settles the intent (idempotent). Intents are created by
 * the portal, never here — so no create/edit page.
 */
class PaymentIntentResource extends Resource
{
    protected static ?string $model = PaymentIntent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Πύλη πελατών';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Εκκρεμής πληρωμή';

    protected static ?string $pluralModelLabel = 'Εκκρεμείς πληρωμές πύλης';

    protected static ?string $navigationLabel = 'Εκκρεμείς πληρωμές πύλης';

    protected static ?string $recordTitleAttribute = 'reference';

    /** Nav badge: how many are still pending (a to-do count for the operator). */
    public static function getNavigationBadge(): ?string
    {
        $count = PaymentIntent::query()->where('status', PaymentIntent::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return PaymentIntentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentIntents::route('/'),
        ];
    }
}
