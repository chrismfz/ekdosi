<?php

namespace App\Support\Hr;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Support\Collection;

/**
 * The «Προσωπικό (μόνο άδειες)» role (`ergani`) — staff who are panel users
 * only to request leave. Everything an operator sees WITHOUT a permission check
 * (dashboard widgets, the AI assistant, the invoice/payment bell broadcasts,
 * ungated pages) must be kept from them; this is the single predicate the
 * guards consult:
 *
 *   - App\Http\Middleware\RestrictErganiStaff — default-deny allowlist of panel routes
 *   - staffRecipients()                         — operator bell broadcasts
 *   - Dashboard / Assistant availability
 *
 * «Restricted» = the user's managed role in THIS company is `ergani` — or
 * `cashier` («Ταμίας — μόνο Ταμείο», POS PR 2c: the same confinement, to the till)
 * — (the picker holds one managed role per company) and they are not a system
 * super_admin. An operator/admin is never restricted. restrictedRole() says WHICH
 * (the middleware's allowlist / landing page differ per role).
 */
final class ErganiStaff
{
    /**
     * Per-request memo, held as a SCOPED container instance — Laravel drops it
     * between queue jobs (forgetScopedInstances) and Octane requests, so a
     * long-lived worker sees a role change on its next job. A static array
     * would pin the first answer for the worker's whole life.
     */
    private const MEMO = 'hr.ergani-staff.memo';

    /** @return \ArrayObject<string, string> */
    private static function memo(): \ArrayObject
    {
        if (! app()->bound(self::MEMO)) {
            app()->scoped(self::MEMO, fn (): \ArrayObject => new \ArrayObject);
        }

        return app(self::MEMO);
    }

    public static function isRestricted(?User $user, ?Company $company): bool
    {
        return self::restrictedRole($user, $company) !== null;
    }

    /** The confining role (`ergani` | `cashier`) the user holds in the company, or null. */
    public static function restrictedRole(?User $user, ?Company $company): ?string
    {
        if (! $user instanceof User || ! $company instanceof Company) {
            return null;
        }

        $key = $user->getKey().':'.$company->getKey();
        $memo = self::memo();

        if (! $memo->offsetExists($key)) {
            $roles = app(TenantRoleProvisioner::class);
            $role = $roles->roleInCompany($user, $company);
            $memo[$key] = in_array($role, [TenantRoleProvisioner::ROLE_ERGANI, TenantRoleProvisioner::ROLE_CASHIER], true)
                && ! $roles->isSystemSuperAdmin($user) ? $role : '';
        }

        return $memo[$key] === '' ? null : $memo[$key];
    }

    /**
     * The company's panel users minus the ergani-only staff — the recipient set
     * for any tenant-wide operator notification (new WHMCS invoice, payment,
     * overdue list, …). Use this instead of `$company->users` for broadcasts.
     *
     * @return Collection<int, User>
     */
    public static function staffRecipients(Company $company): Collection
    {
        return $company->users()->get()
            ->reject(fn (User $u): bool => self::isRestricted($u, $company))
            ->values();
    }

    /** Test/long-running-worker hygiene: role changes must not read a stale memo. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }
}
