<?php

namespace App\Models;

use Database\Factories\CustomerUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer-portal login (the `portal` auth guard) — DISTINCT from operator
 * `App\Models\User` (the Filament panel). A CustomerUser never reaches the admin
 * panel; an operator never authenticates here. The identity is global (unique
 * email); which customer(s)/company(ies) it may see is a separate grant table
 * (a later slice) — this model carries NO business/legal data.
 */
#[Fillable(['name', 'email', 'password', 'status', 'username', 'locale', 'phone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class CustomerUser extends Authenticatable
{
    /** @use HasFactory<CustomerUserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * May this login actually authenticate right now? Only an ACTIVE row with a
     * password set (a null-password invited/backfilled row can never log in). The
     * login flow checks this AFTER a valid password so a suspended account can't
     * slip through, and reports a generic error (no status disclosure).
     */
    public function canLogin(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->password !== null;
    }
}
