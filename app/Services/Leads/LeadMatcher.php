<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Lead;
use App\Support\Afm;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Να μην ξαναζαλίζουμε κόσμο» — finds existing customers and other leads
 * that share a lead's ΑΦΜ, email or phone, so the form can warn BEFORE the
 * operator picks up the phone. Pure lookup, no side effects.
 *
 * Matching is by normalised value: ΑΦΜ digits only (both sides stripped of
 * spaces/dashes, and LeadForm stores it normalised), email lower-cased, phones
 * compared on their trailing digits (the stored column is stripped of spaces /
 * dashes / plus / parentheses with SQL REPLACE, which exists on MariaDB and
 * sqlite alike) so «2310 123-456» matches «+30 2310123456» in either direction.
 */
class LeadMatcher
{
    private const PHONE_MIN_DIGITS = 6;

    /** Rows returned for the banner — a preview, not the full match set. */
    public const PREVIEW_LIMIT = 10;

    /**
     * Phones are compared on their trailing digits so a country prefix on
     * either side («+30 2310…» vs «2310…») never hides a match. Greek numbers
     * are 10 digits; longer national formats still share their last 10.
     */
    private const PHONE_SUFFIX_DIGITS = 10;

    /**
     * Per-instance memo. The matcher is a request-scoped singleton (see
     * AppServiceProvider), so the banner, the DNC acknowledgement (visible +
     * rule), the create hook and the convert modal share ONE lookup per set of
     * inputs instead of re-running the REPLACE()-scan queries each time.
     *
     * @var array<string, LeadMatch>
     */
    private array $memo = [];

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Invalidate the memo whenever the matched data changes in-process (a batch
     * job creating several leads in a loop, tests). Registered once from
     * AppServiceProvider::boot(); no-op when the matcher was never resolved.
     */
    public static function listenForWrites(): void
    {
        $flush = static function (): void {
            if (app()->resolved(self::class)) {
                app(self::class)->flush();
            }
        };

        foreach ([Lead::class, Customer::class, CustomerContact::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
            $model::restored($flush);
        }
    }

    /**
     * The ΑΦΜ equality predicate (digits, EL/GR prefix tolerated on the stored
     * side) — public so a caller that must LOCK the rows can reuse the rule.
     */
    public static function whereAfm(Builder $q, string $afm): Builder
    {
        return $q->whereRaw('UPPER('.self::strippedSql('afm').') IN (?, ?, ?)', [$afm, 'EL'.$afm, 'GR'.$afm]);
    }

    /**
     * @param  list<string|null>  $phones  any of phone / mobile
     */
    public function find(
        int $companyId,
        ?string $afm,
        ?string $email,
        array $phones = [],
        ?int $ignoreLeadId = null,
    ): LeadMatch {
        $key = md5(json_encode([$companyId, self::normalizeAfm($afm), self::normalizeEmail($email), array_map([self::class, 'normalizePhone'], $phones), $ignoreLeadId]));

        return $this->memo[$key] ??= $this->lookup($companyId, $afm, $email, $phones, $ignoreLeadId);
    }

    /**
     * @param  list<string|null>  $phones
     */
    private function lookup(int $companyId, ?string $afm, ?string $email, array $phones, ?int $ignoreLeadId): LeadMatch
    {
        $afm = self::normalizeAfm($afm);
        $email = self::normalizeEmail($email);
        $phones = array_values(array_unique(array_map(
            fn (string $p): string => substr($p, -self::PHONE_SUFFIX_DIGITS),
            array_filter(
                array_map(fn (?string $p): ?string => self::normalizePhone($p), $phones),
                fn (?string $p): bool => $p !== null && strlen($p) >= self::PHONE_MIN_DIGITS,
            ),
        )));

        if ($afm === null && $email === null && $phones === []) {
            return LeadMatch::none();
        }

        // Direct hits: live customers matched on their OWN columns — the set the
        // convert modal may pre-select and the create hook may act on.
        $direct = Customer::query()
            ->where('company_id', $companyId)
            ->where(function (Builder $q) use ($afm, $email, $phones): void {
                $this->applyIdentity($q, $afm, $email, $phones, ['phone1', 'phone2'], ['email', 'secondary_email']);
            })
            ->orderBy('name')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        // Banner set: also soft-deleted ones (a deleted customer is still someone
        // we dealt with) and their named contacts' email/phone — the person who
        // answers the phone is often a contact.
        $customers = Customer::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where(function (Builder $q) use ($afm, $email, $phones): void {
                $this->applyIdentity($q, $afm, $email, $phones, ['phone1', 'phone2'], ['email', 'secondary_email']);

                if ($email !== null || $phones !== []) {
                    // Nested group: a leading OR inside whereHas would attach to
                    // the relation's own join predicate.
                    $q->orWhereHas('contacts', function (Builder $c) use ($email, $phones): void {
                        $c->where(function (Builder $cc) use ($email, $phones): void {
                            $this->applyIdentity($cc, null, $email, $phones, ['phone'], ['email']);
                        });
                    });
                }
            })
            ->orderBy('name')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        $leadIdentity = function (Builder $q) use ($afm, $email, $phones): void {
            $this->applyIdentity($q, $afm, $email, $phones, ['phone', 'mobile'], ['email']);
        };

        $leads = Lead::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->when($ignoreLeadId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreLeadId))
            ->where($leadIdentity)
            ->orderByDesc('updated_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        // «Μην ξαναενοχλήσετε» is decided by an UNBOUNDED exists — the preview
        // above is capped, and a DNC lead must never hide behind newer duplicates.
        $doNotContact = Lead::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->when($ignoreLeadId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreLeadId))
            ->where('status', LeadStatus::DoNotContact->value)
            ->where($leadIdentity)
            ->exists();

        return new LeadMatch($customers, $leads, $doNotContact, $direct);
    }

    /**
     * @param  list<string>  $phones
     * @param  list<string>  $phoneColumns
     * @param  list<string>  $emailColumns
     */
    private function applyIdentity(Builder $q, ?string $afm, ?string $email, array $phones, array $phoneColumns, array $emailColumns): void
    {
        if ($afm !== null) {
            // The stored side may carry an EL/GR prefix (customers.afm is saved
            // as typed) — accept the digits with or without it.
            $q->orWhere(fn (Builder $qq) => self::whereAfm($qq, $afm));
        }

        if ($email !== null) {
            foreach ($emailColumns as $column) {
                $q->orWhereRaw('LOWER('.$column.') = ?', [$email]);
            }
        }

        foreach ($phones as $suffix) {
            foreach ($phoneColumns as $column) {
                $q->orWhereRaw(self::strippedSql($column).' LIKE ?', ['%'.$suffix]);
            }
        }
    }

    /** SQL expression stripping the usual phone/ΑΦΜ formatting from a column. */
    private static function strippedSql(string $column): string
    {
        $expr = $column;
        foreach ([' ', '-', '+', '(', ')', '.'] as $ch) {
            $expr = "REPLACE({$expr}, '{$ch}', '')";
        }

        return $expr;
    }

    /** The ONE ΑΦΜ rule (App\Support\Afm) — never a second implementation. */
    public static function normalizeAfm(?string $afm): ?string
    {
        return Afm::normalise($afm);
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? null : $digits;
    }
}
