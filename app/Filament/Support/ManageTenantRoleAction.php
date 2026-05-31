<?php

namespace App\Filament\Support;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/**
 * The per-tenant role picker (PR2). A single record action, reused by both
 * sides of the user↔company pivot:
 *   - UserResource → Tenants relation manager (record = Company, owner = User)
 *   - CompanyResource → Users relation manager (record = User, owner = Company)
 *
 * It edits the ONE managed role a user holds within a company's team
 * (super_admin | company_admin | operator | none), via
 * TenantRoleProvisioner::setRoleInCompany — picker semantics, not a multi-role
 * list. The two callbacks resolve the (User, Company) pair from whatever record
 * the relation manager hands us.
 *
 * Escalation guard: granting super_admin is offered/allowed ONLY when the
 * ACTING user is themselves super_admin in the target company. A company_admin
 * (who otherwise has every permission in their tenant, including Update:User)
 * therefore cannot lift anyone — including themselves — to the cross-tenant
 * super_admin bypass.
 */
final class ManageTenantRoleAction
{
    /**
     * @param  Closure(mixed): ?User  $resolveUser
     * @param  Closure(mixed): ?Company  $resolveCompany
     */
    public static function make(Closure $resolveUser, Closure $resolveCompany): Action
    {
        return Action::make('manageTenantRole')
            ->label('Ρόλος')
            ->icon('heroicon-o-identification')
            ->modalHeading('Ρόλος χρήστη στην εταιρία')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Αποθήκευση')
            ->schema(function ($record) use ($resolveUser, $resolveCompany): array {
                $user = $resolveUser($record);
                $company = $resolveCompany($record);

                return [
                    Select::make('role')
                        ->label('Ρόλος')
                        ->options(self::roleOptions($company))
                        ->default($user && $company
                            ? app(TenantRoleProvisioner::class)->roleInCompany($user, $company)
                            : null)
                        ->placeholder('— Κανένας ρόλος (μόνο πρόσβαση) —')
                        ->helperText('Ένας ρόλος ανά εταιρία. Ο «Super admin» ισχύει μόνο για αυτή την εταιρία και παρακάμπτει κάθε δικαίωμα.')
                        ->native(false),
                ];
            })
            ->action(function (array $data, $record) use ($resolveUser, $resolveCompany): void {
                $user = $resolveUser($record);
                $company = $resolveCompany($record);

                if (! $user instanceof User || ! $company instanceof Company) {
                    return;
                }

                $role = $data['role'] ?? null;
                if ($role === '') {
                    $role = null;
                }

                // Escalation guard (defence in depth — the option is also hidden):
                // only an existing super_admin in THIS company may grant it.
                if ($role === ShieldUtils::getSuperAdminName() && ! self::actorMayGrantSuperAdmin($company)) {
                    Notification::make()
                        ->danger()
                        ->title('Δεν επιτρέπεται')
                        ->body('Μόνο ένας super admin αυτής της εταιρίας μπορεί να αναθέσει ρόλο super admin.')
                        ->send();

                    return;
                }

                app(TenantRoleProvisioner::class)->setRoleInCompany($user, $company, $role);

                Notification::make()
                    ->success()
                    ->title('Ο ρόλος ενημερώθηκε')
                    ->body(self::roleLabel($role).' — '.$company->name)
                    ->send();
            });
    }

    /**
     * The picker options for a company. super_admin is included only when the
     * acting user is allowed to grant it in this company.
     *
     * @return array<string, string>
     */
    private static function roleOptions(?Company $company): array
    {
        $options = [];

        if ($company instanceof Company && self::actorMayGrantSuperAdmin($company)) {
            $options[ShieldUtils::getSuperAdminName()] = self::roleLabel(ShieldUtils::getSuperAdminName());
        }

        $options[TenantRoleProvisioner::ROLE_COMPANY_ADMIN] = self::roleLabel(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $options[TenantRoleProvisioner::ROLE_OPERATOR] = self::roleLabel(TenantRoleProvisioner::ROLE_OPERATOR);

        return $options;
    }

    /**
     * Greek label for a managed role name (or "no role" for null).
     */
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            ShieldUtils::getSuperAdminName() => 'Super admin (όλα τα δικαιώματα)',
            TenantRoleProvisioner::ROLE_COMPANY_ADMIN => 'Διαχειριστής εταιρίας',
            TenantRoleProvisioner::ROLE_OPERATOR => 'Χειριστής',
            default => 'Κανένας ρόλος',
        };
    }

    /**
     * May the currently authenticated user grant super_admin in this company?
     * True only if they themselves are super_admin there.
     */
    private static function actorMayGrantSuperAdmin(Company $company): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(TenantRoleProvisioner::class)->hasSuperAdminIn($actor, $company);
    }
}
