<?php

namespace App\Services\Leads;

use App\Models\Customer;
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

    /**
     * Phones are compared on their trailing digits so a country prefix on
     * either side («+30 2310…» vs «2310…») never hides a match. Greek numbers
     * are 10 digits; longer national formats still share their last 10.
     */
    private const PHONE_SUFFIX_DIGITS = 10;

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

        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->where(function (Builder $q) use ($afm, $email, $phones): void {
                $this->applyIdentity($q, $afm, $email, $phones, ['phone1', 'phone2']);
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        $leads = Lead::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->when($ignoreLeadId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreLeadId))
            ->where(function (Builder $q) use ($afm, $email, $phones): void {
                $this->applyIdentity($q, $afm, $email, $phones, ['phone', 'mobile']);
            })
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return new LeadMatch($customers, $leads);
    }

    /**
     * @param  list<string>  $phones
     * @param  list<string>  $phoneColumns
     */
    private function applyIdentity(Builder $q, ?string $afm, ?string $email, array $phones, array $phoneColumns): void
    {
        if ($afm !== null) {
            // The stored side may carry an EL/GR prefix (customers.afm is saved
            // as typed) — accept the digits with or without it.
            $q->orWhereRaw('UPPER('.self::strippedSql('afm').') IN (?, ?, ?)', [$afm, 'EL'.$afm, 'GR'.$afm]);
        }

        if ($email !== null) {
            $q->orWhereRaw('LOWER(email) = ?', [$email]);
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
