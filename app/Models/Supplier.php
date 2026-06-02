<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasTags;

use App\Enums\SupplierSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Προμηθευτής — counterpart on the expenses (εισροές) side. Net-new for
 * the Έξοδα phase; the supplier mirror of Customer.
 *
 * Tenant scoping: carries the `BelongsToCompany` global scope, so reads are
 * auto-filtered to the ambient tenant (Filament panel, or a CLI `actAs`
 * block). With no ambient context the scope is a no-op, so CLI/queue paths
 * still scope by `company_id` explicitly.
 */
class Supplier extends Model
{
    use BelongsToCompany;

    use HasFactory;
    use HasTags;
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
