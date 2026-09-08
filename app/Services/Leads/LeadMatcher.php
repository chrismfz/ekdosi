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

        // Banner set: also soft-deleted ones (a deleted customer is still someone
        // we dealt with) and their named contacts' email/phone — the person who
        // answers the phone is often a contact.
        $customers = Customer::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where(function (Builder $q) use ($afm, $email, $phones): void {
                $this->applyIdentity($q, $afm, $email, $phones, ['phone1', 'phone2'], ['email', 'secondary_email'], customers: true);

                if ($email !== null || $phones !== []) {
                    // Nested group: a leading OR inside whereHas would attach to
                    // the relation's own join predicate.
                    $q->orWhereHas('contacts', function (Builder $c) use ($email, $phones): void {
                        $c->where(function (Builder $cc) use ($email, $phones): void {
                            $this->applyIdentity($cc, null, $email, $phones, ['phone'], ['email'], customers: false);
                        });
                    });
                }
            })
            ->orderBy('name')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        // Direct hits: live customers matched on their OWN columns — the set the
        // convert modal may pre-select and the create hook may act on. When the
        // banner query wasn't capped it already holds every match, so filter in
        // PHP; only a capped preview needs its own query.
        $direct = $customers->count() < self::PREVIEW_LIMIT
            ? $customers->filter(fn (Customer $c): bool => ! $c->trashed() && self::ownColumnsMatch($c, $afm, $email, $phones))->values()
            : Customer::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $q) use ($afm, $email, $phones): void {
                    $this->applyIdentity($q, $afm, $email, $phones, ['phone1', 'phone2'], ['email', 'secondary_email'], customers: true);
                })
                ->orderBy('name')
                ->limit(self::PREVIEW_LIMIT)
                ->get();

        $leadIdentity = function (Builder $q) use ($afm, $email, $phones): void {
            $this->applyIdentity($q, $afm, $email, $phones, ['phone', 'mobile'], ['email'], customers: false);
        };

        $leads = Lead::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->when($ignoreLeadId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreLeadId))
            ->where($leadIdentity)
            ->orderByDesc('updated_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        // «Μην ξαναενοχλήσετε» must never hide behind newer duplicates: when the
        // preview is capped, decide it with an UNBOUNDED exists; an uncapped
        // preview already holds every matching lead.
        $doNotContact = $leads->count() < self::PREVIEW_LIMIT
            ? $leads->contains(fn (Lead $l): bool => $l->status === LeadStatus::DoNotContact)
            : Lead::query()
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
    private function applyIdentity(Builder $q, ?string $afm, ?string $email, array $phones, array $phoneColumns, array $emailColumns, bool $customers): void
    {
        if ($afm !== null) {
            // Both sides store the ΑΦΜ identity (customers.afm_key, leads.afm
            // via LeadForm) — exact, indexed equality. A placeholder never gets
            // here (lookup() drops it), so this branch always adds a clause.
            $q->orWhere($customers ? 'afm_key' : 'afm', $afm);
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

    /**
     * PHP twin of applyIdentity() for a customer's OWN columns — used to derive
     * the direct set from an uncapped banner query without a second scan. Must
     * agree with the SQL rule (ΑΦΜ digits with EL/GR tolerated, lower-cased
     * email, trailing-digits phone).
     *
     * @param  list<string>  $phoneSuffixes
     */
    private static function ownColumnsMatch(Customer $c, ?string $afm, ?string $email, array $phoneSuffixes): bool
    {
        if ($afm !== null && $c->afm_key !== null && $c->afm_key === $afm) {
            return true;
        }

        if ($email !== null && in_array($email, [self::normalizeEmail($c->email), self::normalizeEmail($c->secondary_email)], true)) {
            return true;
        }

        foreach ($phoneSuffixes as $suffix) {
            foreach ([$c->phone1, $c->phone2] as $stored) {
                $digits = self::normalizePhone($stored);
                if ($digits !== null && str_ends_with($digits, $suffix)) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * The ONE ΑΦΜ identity rule (App\Support\Afm::uniqueKey) — never a second
     * implementation. Null for a placeholder (000000000 …): NOT an identity,
     * so it adds no predicate and can never match «every customer».
     */
    public static function normalizeAfm(?string $afm): ?string
    {
        return Afm::uniqueKey($afm);
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
