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
        // PR #27: branding + outbound mail config
        'logo_path',
        'pdf_footer_text',
        'mail_from_address',
        'mail_from_name',
        'invoice_audit_bcc',
        'auto_email_on_mydata_accept',
        'mail_smtp_host',
        'mail_smtp_port',
        'mail_smtp_username',
        'mail_smtp_password',
        'mail_smtp_encryption',
        'mail_subject_template',
        'mail_body_template',
    ];

    protected function casts(): array
    {
        return [
            'mydata_subscription_key' => 'encrypted',
            'gsis_password' => 'encrypted',
            'mail_smtp_password' => 'encrypted',
            'auto_email_on_mydata_accept' => 'boolean',
            'mail_smtp_port' => 'integer',
        ];
    }

    /**
     * True iff this tenant has its own SMTP server configured. The
     * Mailer factory checks this to decide whether to swap the runtime
     * config or fall through to the global MAIL_MAILER. Host alone is
     * the signal — username/password are optional (some local relays
     * accept unauthenticated sends from trusted IPs).
     */
    public function hasOwnSmtp(): bool
    {
        return ! empty($this->mail_smtp_host);
    }

    /**
     * Parsed list of audit-BCC recipients. The column is a single
     * comma/semicolon-separated string (legacy operators typed it as
     * `"audit@example.com, ops@example.com"`); we split and trim.
     * Returns an empty array if the column is empty — the mailer
     * skips the BCC header entirely in that case.
     *
     * @return list<string>
     */
    public function auditBccList(): array
    {
        $raw = (string) ($this->invoice_audit_bcc ?? '');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            // Defensive: drop anything that doesn't even look like an
            // email — silently. The CompanyForm has a validator that
            // surfaces format errors at save time, so an invalid value
            // arriving here is either pre-PR-27 data or a hand-edit
            // and the right behaviour is "best-effort send, skip junk"
            // not "explode the queue worker on every invoice".
            if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
                $out[] = $trimmed;
            }
        }
        return $out;
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
            // Parens around the ?? so the coalesce binds BEFORE the cast.
            // Without them, `(string) $this->attributes['mydata_mode'] ?? ''`
            // parses as `((string) $this->attributes['mydata_mode']) ?? ''`
            // which fires "Undefined array key" if mydata_mode isn't in
            // the loaded attributes (e.g. a partial Company::select(['id'])
            // query) before ?? gets a chance to short-circuit.
            get: fn () => MyDataMode::tryFrom((string) ($this->attributes['mydata_mode'] ?? '')) ?? MyDataMode::Off,
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
