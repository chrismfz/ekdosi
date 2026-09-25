<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An activated office tablet («Σημείο κάρτας») — named, listable, revocable one
 * by one. The device's httpOnly cookie carries a random token; only its SHA-256
 * is stored, so the DB never holds a usable credential.
 */
class WorkCardKioskDevice extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'token_hash', 'activated_by_user_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Register a new device; returns [device, plain token] — the token goes into
     * the device cookie and is never shown or stored anywhere else.
     *
     * @return array{0: self, 1: string}
     */
    public static function activate(Company $company, string $name, ?int $userId): array
    {
        $token = Str::random(48);
        $device = static::create([
            'company_id' => $company->getKey(),
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'Tablet', 0, 80),
            'token_hash' => hash('sha256', $token),
            'activated_by_user_id' => $userId,
        ]);

        return [$device, $token];
    }

    /** The live device behind a cookie token (company must still have ΕΡΓΑΝΗ on). */
    public static function forToken(?string $token): ?self
    {
        if (! is_string($token) || strlen($token) < 32) {
            return null;
        }
        $hash = hash('sha256', $token);
        $device = static::query()->withoutGlobalScopes()->active()->where('token_hash', $hash)->with('company')->first();

        return $device && hash_equals($device->token_hash, $hash) && $device->company?->hasErgani() ? $device : null;
    }
}
