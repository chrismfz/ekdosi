<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A user's personal ICS subscription for one company («Το ημερολόγιό μου»).
 * The public URL holds the raw token; only its SHA-256 is used for lookup.
 */
class CalendarFeed extends Model
{
    use BelongsToCompany;

    protected $fillable = ['include_leads', 'include_leaves', 'include_team', 'include_holidays', 'include_overtime'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'include_leads' => 'boolean',
            'include_leaves' => 'boolean',
            'include_team' => 'boolean',
            'include_holidays' => 'boolean',
            'include_overtime' => 'boolean',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The user's feed in this company, created (with a fresh token) on first use. */
    public static function for(User $user, Company $company): self
    {
        $feed = static::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())->where('user_id', $user->getKey())->first();

        return $feed ?? tap(new static, function (self $f) use ($user, $company): void {
            $f->forceFill(['company_id' => $company->getKey(), 'user_id' => $user->getKey()]);
            $f->rotate();
        });
    }

    /** New token — the old link stops working immediately. */
    public function rotate(): void
    {
        $token = Str::random(48);
        $this->forceFill(['token' => $token, 'token_hash' => hash('sha256', $token)])->save();
    }

    public static function forToken(string $token): ?self
    {
        if (strlen($token) < 32) {
            return null;
        }

        return static::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    public function url(): string
    {
        return route('calendar.feed', ['token' => $this->token]);
    }
}
