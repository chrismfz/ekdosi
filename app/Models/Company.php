<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'country_code',
        'einvoice_provider',
        'afm',
        'tax_office',
        'address',
        'city',
        'postcode',
        'phone',
        'email',
        'mydata_aade_id',
        'mydata_subscription_key',
        'mydata_production',
    ];

    protected function casts(): array
    {
        return [
            'mydata_production' => 'boolean',
            'mydata_subscription_key' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Operators with access to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
