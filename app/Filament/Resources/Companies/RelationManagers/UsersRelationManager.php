<?php

namespace App\Filament\Resources\Companies\RelationManagers;

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

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $recordTitleAttribute = 'email';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                // This user's role within THIS company (team-scoped, computed).
                ManageTenantRoleAction::badgeColumn(
                    resolveUser: fn (User $record): User => $record,
                    resolveCompany: fn (User $record): ?Company => $this->getOwnerRecord() instanceof Company
                        ? $this->getOwnerRecord()
                        : null,
                ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    // If the attached user is already super_admin in another
                    // tenant, propagate it here too so they don't lose the
                    // bypass when switching into this company. Non-super
                    // operators are unaffected.
                    ->after(function (array $data): void {
                        $company = $this->getOwnerRecord();
                        if (! $company instanceof Company) {
                            return;
                        }
                        // recordId is a single id or an array (multi-attach).
                        $ids = (array) ($data['recordId'] ?? []);
                        $provisioner = app(TenantRoleProvisioner::class);
                        foreach (User::whereKey($ids)->get() as $user) {
                            if ($provisioner->isSuperAdminAnywhere($user)) {
                                $provisioner->assignSuperAdmin($user, $company);
                            }
                        }
                    }),
            ])
            ->recordActions([
                // Set the row user's role within this company (team-scoped).
                ManageTenantRoleAction::make(
                    resolveUser: fn (User $record): User => $record,
                    resolveCompany: fn (User $record): ?Company => $this->getOwnerRecord() instanceof Company
                        ? $this->getOwnerRecord()
                        : null,
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
