<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blocked sender (Πυλώνας E, Phase 4). `pattern` is a full email
 * (`spammer@x.gr`) or a bare domain (`x.gr`), stored lowercased. The inbound
 * router drops an email whose sender address OR domain matches — before any
 * customer match or ticket creation.
 */
class TicketBlockedSender extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'pattern',
        'reason',
        'created_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Normalise a raw pattern: lowercase + trim, and strip a leading «@» so a
     * domain entered as «@x.gr» is stored as the bare «x.gr» the router matches.
     * Returns '' for a blank (the caller decides what to do with it).
     */
    public static function normalizePattern(?string $value): string
    {
        // lowercase → strip ALL whitespace incl. unicode separators (NBSP/full-width
        // from copy-paste), since a valid email/domain has none, so «@ bad.gr» and
        // «spammer @bad.gr» collapse correctly → strip a leading «@» (a domain entered
        // as «@x.gr» stored bare).
        $value = preg_replace('/[\s\p{Z}]+/u', '', mb_strtolower((string) $value)) ?? '';

        return ltrim($value, '@');
    }

    /** Store the pattern normalised, so the unique index + matching are case-insensitive. */
    protected function pattern(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => self::normalizePattern($value),
        );
    }

    /**
     * Is this sender blocked for the company? Matches the full address OR its
     * domain against the tenant's blocklist. Explicitly company-scoped (the
     * router runs off-panel), so it bypasses the ambient CompanyScope.
     */
    public static function isBlocked(int $companyId, string $fromEmail): bool
    {
        // Canonicalise the incoming address through the SAME normaliser used on
        // store, so matching and storage can never disagree (a real address has no
        // leading «@», so that part is a no-op here).
        $from = self::normalizePattern($fromEmail);
        if ($from === '' || ! str_contains($from, '@')) {
            return false;
        }

        $domain = (string) substr(strrchr($from, '@'), 1);
        $candidates = array_values(array_unique(array_filter([$from, $domain])));

        return self::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->whereIn('pattern', $candidates)
            ->exists();
    }
}
