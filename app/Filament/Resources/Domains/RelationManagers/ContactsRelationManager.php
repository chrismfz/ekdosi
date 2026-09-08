<?php

namespace App\Filament\Resources\Domains\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The domain's registrant/admin/tech/billing contacts, parsed + editable inline
 * (the owner's core objection to WHMCS's bare button — §8.2). One per type (DB
 * unique + form rule). At A3 «Modify Contacts» pushes these to the registrar;
 * until then they are our own record — and the manual-assign aid.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Επαφές (registrant/admin/tech/billing)';

    private const TYPE_LABELS = [
        'registrant' => 'Δικαιούχος (registrant)',
        'admin' => 'Διαχειριστικός (admin)',
        'tech' => 'Τεχνικός (tech)',
        'billing' => 'Οικονομικός (billing)',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Τύπος')
                ->options(self::TYPE_LABELS)
                ->required()
                ->native(false)
                ->unique(
                    table: 'domain_contacts',
                    column: 'type',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('domain_id', $this->getOwnerRecord()->getKey()),
                )
                ->validationMessages(['unique' => 'Υπάρχει ήδη επαφή αυτού του τύπου στο domain.']),
            TextInput::make('name')->label('Όνομα')->required()->maxLength(190),
            TextInput::make('org')->label('Εταιρεία')->maxLength(190),
            TextInput::make('email')->label('Email')->email()->maxLength(190),
            TextInput::make('phone')->label('Τηλέφωνο')->maxLength(40)->placeholder('+30.2101234567'),
            TextInput::make('address1')->label('Διεύθυνση')->maxLength(190),
            TextInput::make('address2')->label('Διεύθυνση (2η γραμμή)')->maxLength(190),
            TextInput::make('city')->label('Πόλη')->maxLength(120),
            TextInput::make('postcode')->label('Τ.Κ.')->maxLength(20),
            TextInput::make('country')->label('Χώρα (ISO)')->maxLength(2)->placeholder('GR'),
            TextInput::make('registrar_contact_handle')
                ->label('Registrar handle')
                ->maxLength(40)
                ->helperText('Π.χ. Openprovider AB123456-XX — θα γεμίζει από το sync (A2).'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Τύπος')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::TYPE_LABELS[$state] ?? $state),
                TextColumn::make('name')->label('Όνομα')->searchable(),
                TextColumn::make('org')->label('Εταιρεία')->placeholder('—'),
                TextColumn::make('email')->label('Email')->placeholder('—')->copyable(),
                TextColumn::make('phone')->label('Τηλέφωνο')->placeholder('—'),
                TextColumn::make('registrar_contact_handle')->label('Handle')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Προσθήκη επαφής')
                    ->mutateDataUsing(function (array $data): array {
                        $data['company_id'] = Filament::getTenant()?->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
