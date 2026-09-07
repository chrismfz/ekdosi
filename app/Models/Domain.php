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

    /** Keep fqdn derived from the authoritative sld + tld pair. */
    public static function fqdnFor(string $sld, string $tld): string
    {
        return mb_strtolower(trim($sld).'.'.ltrim(trim($tld), '.'));
    }
}
