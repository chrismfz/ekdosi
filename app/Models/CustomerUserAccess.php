<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single portal ACCESS GRANT: `customer_user` × `company` × `customer` × role.
 * The leak-proof boundary the portal will use to decide which customer's data a
 * login may see. Audited (granted_by/granted_at), softly revoked (revoked_at).
 *
 * Not tenant-scoped: the customer_user side is global, and grants deliberately
 * span companies (that's the point). Scope by company_id/customer_id explicitly.
 */
#[Fillable(['customer_user_id', 'company_id', 'customer_id', 'role', 'granted_by', 'granted_at', 'revoked_at'])]
class CustomerUserAccess extends Model
{
    protected $table = 'customer_user_access';

    public const ROLE_OWNER = 'owner';

    public const ROLE_RESELLER = 'reseller';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Only live (non-revoked) grants — the set the portal will actually honour. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * A grant is inherently CROSS-COMPANY, so the customer must resolve
     * regardless of the panel's active tenant — drop the ambient CompanyScope
     * (otherwise a grant to a customer of another company renders blank).
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
