<?php

namespace App\Models;

use App\Enums\MyDataMode;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'kad_primary',
        'address',
        'city',
        'postcode',
        'phone',
        'email',
        'mydata_aade_id',
        'mydata_subscription_key',
        'mydata_mode',
        'gsis_username',
        'gsis_password',
    ];

    protected function casts(): array
    {
        return [
            'mydata_subscription_key' => 'encrypted',
            'gsis_password' => 'encrypted',
        ];
    }

    /**
     * Typed accessor for mydata_mode. The column stores the raw string
     * for backward compatibility with ETL / artisan / DB-direct paths
     * (and so a new value added to MyDataMode doesn't break older rows);
     * code paths that want to switch on the mode should read this
     * accessor instead of the raw column.
     */
    protected function mydataModeEnum(): Attribute
    {
        return Attribute::make(
            get: fn () => MyDataMode::tryFrom((string) $this->attributes['mydata_mode'] ?? '') ?? MyDataMode::Off,
        );
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
