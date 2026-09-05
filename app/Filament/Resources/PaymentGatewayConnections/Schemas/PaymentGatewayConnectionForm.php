<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Schemas;

use App\Contracts\PaymentGateway;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentGatewayRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PaymentGatewayConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        $registry = app(PaymentGatewayRegistry::class);

        return $schema->components([
            Select::make('gateway')
                ->label('Τύπος')
                ->options(collect($registry->all())
                    ->mapWithKeys(fn ($g) => [$g->key() => $g->displayName()])
                    ->all())
                ->required()
                ->native(false)
                ->live()
                // The type binds the config schema — don't change it after
                // creation; add a new method instead.
                ->disabledOn('edit')
                ->helperText('Ο τύπος τρόπου πληρωμής. Δεν αλλάζει μετά τη δημιουργία.'),

            TextInput::make('label')
                ->label('Όνομα (όπως το βλέπει ο πελάτης)')
                ->maxLength(120)
                ->placeholder(fn (Get $get): string => filled($get('gateway'))
                    ? $registry->for((string) $get('gateway'))->displayName()
                    : '')
                ->helperText('Κενό → χρησιμοποιείται η προεπιλογή του τύπου.'),

            Toggle::make('is_active')
                ->label('Ενεργό (ορατό στον πελάτη)')
                ->default(false),

            TextInput::make('sort')
                ->label('Σειρά εμφάνισης')
                ->numeric()
                ->default(0)
                ->minValue(0),

            // The myDATA «Τρόπος πληρωμής» stamped on payments settled through this
            // channel (e.g. Eurobank/κάρτα → «Ηλεκτρονικά μέσα Πληρωμών»), so a gateway
            // payment isn't left method-less. Kept SEPARATE from the customer-facing
            // label: this is the accounting/myDATA type, not what the customer reads.
            Select::make('payment_method_id')
                ->label('Τρόπος πληρωμής (myDATA)')
                ->options(fn (): array => PaymentMethod::query()
                    ->where('company_id', Filament::getTenant()?->getKey())
                    ->orderBy('description')
                    ->pluck('description', 'id')
                    ->all())
                ->searchable()
                ->native(false)
                ->placeholder('— κανένας (κενό «Τρόπος» στην πληρωμή) —')
                ->helperText('Αυτόματα στην είσπραξη μέσω αυτού του καναλιού. Π.χ. «Ηλεκτρονικά μέσα Πληρωμών».'),

            // Per-gateway settings, declared BY the gateway (configFields) and
            // nested under `config` (encrypted at rest). A new gateway brings its
            // own fields with no edit here.
            //
            // STATIC schema (not a `->schema(fn (Get))` closure): every gateway's
            // fields are built ONCE at mount, one Group each, and only the selected
            // gateway's Group is shown. A reactively-BUILT schema (closure) adds its
            // fields AFTER mount, so they never go through `fill()` — their state
            // isn't hydrated/committed and `->default()` is skipped, which silently
            // dropped merchant_id/shared_secret and fired a false «required» on save
            // (reproduced in-browser; the earlier `->live(onBlur)` couldn't fix it
            // because the schema shape, not blur timing, was the cause). Hidden
            // Groups are excluded from validation AND dehydrated out, so a non-selected
            // gateway's required fields never block and its keys never persist (verified:
            // a manual create saves only manual keys, no eurobank bleed).
            //
            // CONTRACT: because all gateways share the one `config` statePath, their
            // configFields() keys must be UNIQUE across gateways (today: manual =
            // bank_account_ids/instructions, eurobank = merchant_id/shared_secret/lang/
            // testmode — disjoint). A second gateway reusing a key (e.g. a future PayPal
            // `testmode`) would collide on defaults → namespace under the gateway key
            // then. See docs/BACKLOG.md.
            Section::make('Ρυθμίσεις τρόπου')
                ->statePath('config')
                ->visible(fn (Get $get): bool => filled($get('gateway')) && $get('gateway') !== 'none')
                ->schema(array_map(
                    // `../gateway`: the Group sits under the `config`-statePath Section,
                    // so a bare `$get('gateway')` would read `config.gateway` (null) and
                    // `isAbsolute` drops the form's own `data` prefix — `../` climbs one
                    // level to the sibling top-level select.
                    fn (PaymentGateway $g): Group => Group::make($g->configFields())
                        ->visible(fn (Get $get): bool => (string) $get('../gateway') === $g->key()),
                    $registry->all(),
                )),
        ]);
    }
}
