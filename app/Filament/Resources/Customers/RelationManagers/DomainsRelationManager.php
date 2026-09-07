<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\Company;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Domains» tab on the customer card (Πυλώνας A / A1b) — the per-customer view
 * of assigned domains. Read-mostly: «Άνοιγμα» jumps to the full Domain view;
 * assignment/creation happens in the Domains resource. Visible only when the
 * tenant has the pillar on (RMs are not cluster members — own flag check,
 * docs/domains/README.md §8.2).
 */
class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    protected static ?string $title = 'Domains';

    protected static ?string $recordTitleAttribute = 'fqdn';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // Flag AND the default policy gate (viewAny:Domain) — overriding must
        // not WIDEN access vs the Domains resource itself.
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasDomainManagement()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        // No inline form — domains are managed in the Domains resource.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fqdn')
                    ->label('Domain')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge(),
                TextColumn::make('expires_at')
                    ->label('Λήξη')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('auto_renew')
                    ->label('Auto-renew')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Domain $record): string => DomainResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('fqdn');
    }
}
