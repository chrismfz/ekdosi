<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Extends Spatie's Role to give it a `company()` relation back to the
 * tenant. Filament's BelongsToTenant trait needs this method to scope
 * Shield's RoleResource to the current tenant — without it, listing
 * /admin/{slug}/shield/roles throws "Role does not have a relationship
 * named [company]".
 */
class Role extends SpatieRole
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
