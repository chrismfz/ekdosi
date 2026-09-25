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
use Filament\Tables\Columns\TextColumn;

/**
 * The per-tenant role picker. A single record action + a matching badge column,
 * reused by both sides of the user↔company pivot:
 *   - UserResource → Tenants relation manager (record = Company, owner = User)
 *   - CompanyResource → Users relation manager (record = User, owner = Company)
 *
 * It edits the ONE managed role a user holds within a company's team
 * (super_admin | company_admin | operator | none) via
 * TenantRoleProvisioner::setRoleInCompany — picker semantics. The two callbacks
 * resolve the (User, Company) pair from whatever record the manager hands us.
 *
 * Authorization: role management is super_admin-only. The action is VISIBLE only
 * to an actor who is super_admin in the target company, and the in-action guard
 * additionally refuses any change that GRANTS OR REMOVES super_admin unless the
 * actor is super_admin there — so a company_admin can neither escalate anyone
 * nor knock out an existing super_admin (defence in depth behind the visibility
 * gate, and it also covers the null-submit case where a hidden super_admin
 * default would otherwise strip the role).
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
            // Only a super_admin of the target company may manage roles there.
            ->visible(function ($record) use ($resolveCompany): bool {
                $company = $resolveCompany($record);

                return $company instanceof Company && self::actorMayManageRoles($company);
            })
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
                        ->helperText('Ένας ρόλος ανά εταιρία. Ο «Super admin» είναι ρόλος συστήματος — παρακάμπτει κάθε δικαίωμα σε ΟΛΕΣ τις εταιρίες (αρκεί να τον έχει σε μία).')
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

                // HARD authorization — NOT defence-in-depth behind ->visible():
                // Filament does not re-check isVisible() at action mount (only
                // isDisabled()), so callTableAction reaches here even when the
                // button is hidden. Role management is super_admin-only, so reject
                // EVERY change (not just super_admin-touching ones) unless the
                // actor is super_admin in this company. This is the real boundary
                // alongside the UserResource/CompanyResource view policies.
                if (! self::actorMayManageRoles($company)) {
                    Notification::make()
                        ->danger()
                        ->title('Δεν επιτρέπεται')
                        ->body('Μόνο ένας super admin αυτής της εταιρίας μπορεί να διαχειριστεί ρόλους.')
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
     * The team-scoped "current role" badge column, shared by both relation
     * managers. $state carries the role NAME (stable), formatted to a Greek
     * label and coloured by name — so neither presentation depends on the
     * other's text.
     *
     * @param  Closure(mixed): ?User  $resolveUser
     * @param  Closure(mixed): ?Company  $resolveCompany
     */
    public static function badgeColumn(Closure $resolveUser, Closure $resolveCompany): TextColumn
    {
        return TextColumn::make('tenant_role')
            ->label('Ρόλος')
            ->badge()
            ->state(function ($record) use ($resolveUser, $resolveCompany): ?string {
                $user = $resolveUser($record);
                $company = $resolveCompany($record);

                return $user instanceof User && $company instanceof Company
                    ? app(TenantRoleProvisioner::class)->roleInCompany($user, $company)
                    : null;
            })
            ->formatStateUsing(fn (?string $state): string => self::roleLabel($state))
            ->color(fn (?string $state): string => self::roleColor($state));
    }

    /**
     * The picker options for a company. super_admin is offered only when the
     * acting user may manage super_admin there.
     *
     * @return array<string, string>
     */
    private static function roleOptions(?Company $company): array
    {
        $options = [];

        if ($company instanceof Company && self::actorMayManageRoles($company)) {
            $options[ShieldUtils::getSuperAdminName()] = self::roleLabel(ShieldUtils::getSuperAdminName());
        }

        $options[TenantRoleProvisioner::ROLE_COMPANY_ADMIN] = self::roleLabel(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $options[TenantRoleProvisioner::ROLE_OPERATOR] = self::roleLabel(TenantRoleProvisioner::ROLE_OPERATOR);
        $options[TenantRoleProvisioner::ROLE_ERGANI] = self::roleLabel(TenantRoleProvisioner::ROLE_ERGANI);

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
            TenantRoleProvisioner::ROLE_ERGANI => 'Προσωπικό (μόνο άδειες)',
            default => 'Κανένας ρόλος',
        };
    }

    /**
     * Filament badge colour for a managed role name. Keyed on the role NAME
     * (super_admin resolved dynamically), not the label text.
     */
    public static function roleColor(?string $role): string
    {
        return match ($role) {
            ShieldUtils::getSuperAdminName() => 'danger',
            TenantRoleProvisioner::ROLE_COMPANY_ADMIN => 'warning',
            TenantRoleProvisioner::ROLE_OPERATOR => 'success',
            TenantRoleProvisioner::ROLE_ERGANI => 'info',
            default => 'gray',
        };
    }

    /**
     * May the currently authenticated user manage roles in this company? Role
     * management is super_admin-only — but a SYSTEM super_admin (super_admin in
     * ANY tenant) may manage roles in EVERY company, not just ones they already
     * hold super_admin in. That lets the owner bootstrap roles in a freshly
     * created / restored company they have no role in yet (the chicken-and-egg:
     * otherwise you could never give yourself a role in a new tenant), and it
     * matches the auto-grant of super_admin on attach. Safe because super_admin
     * is the system-owner role — per-tenant admins get company_admin, which this
     * never returns true for.
     */
    private static function actorMayManageRoles(Company $company): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($actor);
    }
}
