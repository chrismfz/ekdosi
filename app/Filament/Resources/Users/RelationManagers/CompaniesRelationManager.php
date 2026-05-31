<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Support\ManageTenantRoleAction;
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
                // The user's role WITHIN this tenant (team-scoped). Computed, not
                // a column on companies — resolved per row via the provisioner.
                TextColumn::make('tenant_role')
                    ->label('Ρόλος')
                    ->badge()
                    ->state(function (Company $record): string {
                        $user = $this->getOwnerRecord();
                        $role = $user instanceof User
                            ? app(TenantRoleProvisioner::class)->roleInCompany($user, $record)
                            : null;

                        return ManageTenantRoleAction::roleLabel($role);
                    })
                    ->color(fn (string $state): string => match ($state) {
                        ManageTenantRoleAction::roleLabel('super_admin') => 'danger',
                        ManageTenantRoleAction::roleLabel(TenantRoleProvisioner::ROLE_COMPANY_ADMIN) => 'warning',
                        ManageTenantRoleAction::roleLabel(TenantRoleProvisioner::ROLE_OPERATOR) => 'success',
                        default => 'gray',
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
                // Set this user's role within the row's company (team-scoped).
                ManageTenantRoleAction::make(
                    resolveUser: fn (Company $record): ?User => $this->getOwnerRecord() instanceof User
                        ? $this->getOwnerRecord()
                        : null,
                    resolveCompany: fn (Company $record): Company => $record,
                ),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
