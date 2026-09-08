<?php

namespace App\Models;

use App\Enums\SupplierSource;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasTags;
use App\Support\IsoCountry;
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
        // MYD-011: normalised ISO-3166-1 alpha-2 cache of `country` (the picker binds here).
        'country_code',
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

    protected static function booted(): void
    {
        // MYD-011: keep the normalised ISO code in sync with the free-text country.
        // Prefer an explicit code the picker set, else derive from the stored value.
        // Mirror a known code into a blank `country` so downstream readers of the
        // free-text column keep resolving (and a foreign supplier is never GR).
        static::saving(function (self $supplier): void {
            IsoCountry::syncCountryCode($supplier);
        });
    }

    /**
     * The supplier's country as a normalised ISO-3166-1 alpha-2, or null when it
     * cannot be resolved. Prefers the `country_code` cache, falling back to
     * normalising the free-text `country` live (zero regression for un-backfilled rows).
     */
    public function isoCountryCode(): ?string
    {
        return $this->country_code ?? IsoCountry::tryNormalise($this->country);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
