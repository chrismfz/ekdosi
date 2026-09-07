<?php

namespace App\Filament\Resources\Domains\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Delegation nameservers (A1b: our record, manual· A3 pushes setNameservers to
 * the registrar). Glue/child hosts are a separate table + phase (domain_hosts).
 */
class NameserversRelationManager extends RelationManager
{
    protected static string $relationship = 'nameservers';

    protected static ?string $title = 'Nameservers';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('host')
                ->label('Nameserver')
                ->required()
                ->maxLength(190)
                ->placeholder('ns1.myip.gr'),
            TextInput::make('sort_order')
                ->label('Σειρά')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(10),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('host')->label('Nameserver')->searchable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Προσθήκη NS')
                    ->mutateDataUsing(function (array $data): array {
                        $data['company_id'] = Filament::getTenant()?->getKey();
                        $data['host'] = mb_strtolower(trim((string) $data['host']));

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
