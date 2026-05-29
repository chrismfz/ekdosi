<?php

namespace App\Models;

use App\Enums\SupplierSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Προμηθευτής — counterpart on the expenses (εισροές) side. Net-new for
 * the Έξοδα phase; the supplier mirror of Customer.
 *
 * Tenant scoping: like Customer, this relies on Filament's BelongsToTenant
 * (the `company()` relation + the panel's tenant). There is no global scope
 * here (see CLAUDE.md latent items) — code outside a Filament request must
 * scope by company_id itself.
 */
class Supplier extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'afm',
        'name',
        'tax_office',
        'occupation',
        'address1',
        'city',
        'postcode',
        'country',
        'email',
        'phone1',
        'source',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'source' => SupplierSource::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
