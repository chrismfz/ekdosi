<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A domain name (Πυλώνας A / A1) — docs/domains/README.md §3.4.
 *
 * Authoritative name = sld + tld; `fqdn` is derived (kept for lookups + the
 * per-tenant unique). Two orthogonal clocks: `expires_at` (REGISTRAR truth,
 * pulled by the A2 sync) vs the linked ServiceContract's next_due_date (the
 * billing clock, 1:1 per domain — owner decision). `customer_id` NULL =
 * «αδέσποτο»: imported/unmatched, outside renewal billing until «Ανάθεση σε
 * πελάτη».
 */
class Domain extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory;
    use HasInternalNotes;
    use SoftDeletes;
    use TracksActivity;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'customer_id',
        'service_contract_id',
        'domain_tld_id',
        'registrar_connection_id',
        'sld',
        'tld',
        'fqdn',
        'status',
        'registered_at',
        'transferred_at',
        'expires_at',
        'auto_renew',
        'transfer_lock',
        'whois_privacy',
        'dnssec_enabled',
        'consent_publish',
        'registrar_domain_id',
        'idn_script',
        'grace_days_override',
        'redemption_days_override',
        'fee_override',
        'price_override',
        'module_meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'registered_at' => 'date',
            'transferred_at' => 'date',
            'expires_at' => 'date',
            'auto_renew' => 'boolean',
            'transfer_lock' => 'boolean',
            'whois_privacy' => 'boolean',
            'dnssec_enabled' => 'boolean',
            'consent_publish' => 'boolean',
            'last_synced_at' => 'datetime',
            'fee_override' => 'decimal:2',
            'price_override' => 'decimal:2',
            'module_meta' => 'array',
        ];
    }

    /**
     * Audited business columns — NEVER the sync cache (last_synced_at /
     * sync_error, rewritten by every A2 sync run on otherwise-unchanged rows).
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'customer_id',
            'service_contract_id',
            'domain_tld_id',
            'registrar_connection_id',
            'sld',
            'tld',
            'fqdn',
            'status',
            'registered_at',
            'transferred_at',
            'expires_at',
            'auto_renew',
            'transfer_lock',
            'whois_privacy',
            'dnssec_enabled',
            'consent_publish',
            'price_override',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class);
    }

    public function tldRule(): BelongsTo
    {
        return $this->belongsTo(DomainTld::class, 'domain_tld_id');
    }

    public function registrarConnection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class, 'registrar_connection_id');
    }

    public function nameservers(): HasMany
    {
        return $this->hasMany(DomainNameserver::class)->orderBy('sort_order');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(DomainContact::class);
    }

    /** «Αδέσποτο» — imported/unmatched, outside renewal billing until assigned. */
    public function isUnassigned(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * Offerable for «Ανάθεση σε πελάτη»: unassigned AND not in a terminal
     * status (the assign action's guard refuses those — button/badges stay in
     * sync via this one predicate + scopeAssignable).
     */
    public function isAssignable(): bool
    {
        return $this->customer_id === null
            && ! ($this->status instanceof DomainStatus && $this->status->isTerminal());
    }

    /** Query twin of isAssignable() for worklist tabs/badges. */
    public function scopeAssignable($query)
    {
        return $query->whereNull('customer_id')
            ->whereNotIn('status', DomainStatus::terminalValues());
    }

    /** ONE «λήγει σύντομα» window for list column, tab, badge AND View header. */
    public const EXPIRING_SOON_DAYS = 45;

    /**
     * Expired = strictly BEFORE today: on the expiry date itself the registrar
     * still holds the name, so the UI must say «λήγει σήμερα», not «έληξε».
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(Carbon::today());
    }

    public function isExpiringSoon(): bool
    {
        return $this->expires_at !== null
            && ! $this->isExpired()
            && $this->expires_at->lte(Carbon::today()->addDays(self::EXPIRING_SOON_DAYS));
    }

    /** Query twin of isExpiringSoon() (active domains only) for the worklist tab/badge. */
    public function scopeExpiringSoon($query)
    {
        return $query
            ->where('status', DomainStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::today()->addDays(self::EXPIRING_SOON_DAYS));
    }

    /**
     * §2 routing, THE one source (UI display AND the sync both use it): the
     * domain's own connection wins, else the TLD's default. Null = manual.
     */
    public function effectiveRegistrarConnection(): ?DomainRegistrarConnection
    {
        return $this->registrarConnection ?? $this->tldRule?->registrarConnection;
    }

    /** Keep fqdn derived from the authoritative sld + tld pair. */
    public static function fqdnFor(string $sld, string $tld): string
    {
        return mb_strtolower(trim($sld).'.'.ltrim(trim($tld), '.'));
    }
}
