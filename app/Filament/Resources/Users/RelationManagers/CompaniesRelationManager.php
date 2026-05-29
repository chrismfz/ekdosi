<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Tenants';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->disabled(),
                TextInput::make('slug')
                    ->disabled(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('country_code')
                    ->label('Country'),
                TextColumn::make('einvoice_provider')
                    ->label('e-invoice')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'gr-mydata' => 'myDATA',
                        'ee-peppol' => 'PEPPOL',
                        'none' => 'PDF only',
                        default => $state,
                    }),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    // Mirror of the Companies→Users hook: if THIS user is
                    // already super_admin somewhere, propagate it to each
                    // newly-attached company so they keep the bypass there.
                    ->after(function (array $data): void {
                        $user = $this->getOwnerRecord();
                        if (! $user instanceof User) {
                            return;
                        }
                        $provisioner = app(TenantRoleProvisioner::class);
                        if (! $provisioner->isSuperAdminAnywhere($user)) {
                            return;
                        }
                        $ids = (array) ($data['recordId'] ?? []);
                        foreach (Company::whereKey($ids)->get() as $company) {
                            $provisioner->assignSuperAdmin($user, $company);
                        }
                    }),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
