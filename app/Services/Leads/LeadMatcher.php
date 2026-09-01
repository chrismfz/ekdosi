<?php

namespace App\Services\Leads;

use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Να μην ξαναζαλίζουμε κόσμο» — finds existing customers and other leads
 * that share a lead's ΑΦΜ, email or phone, so the form can warn BEFORE the
 * operator picks up the phone. Pure lookup, no side effects.
 *
 * Matching is by normalised value: ΑΦΜ digits only, email lower-cased, phones
 * compared digits-only (the stored column is stripped of spaces / dashes /
 * plus / parentheses with SQL REPLACE, which exists on MariaDB and sqlite
 * alike) so «2310 123-456» matches «2310123456».
 */
class LeadMatcher
{
    private const PHONE_MIN_DIGITS = 6;

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
        $phones = array_values(array_unique(array_filter(
            array_map(fn (?string $p): ?string => self::normalizePhone($p), $phones),
            fn (?string $p): bool => $p !== null && strlen($p) >= self::PHONE_MIN_DIGITS,
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
            $q->orWhere('afm', $afm);
        }

        if ($email !== null) {
            $q->orWhereRaw('LOWER(email) = ?', [$email]);
        }

        foreach ($phones as $digits) {
            foreach ($phoneColumns as $column) {
                $q->orWhereRaw(self::strippedPhoneSql($column).' LIKE ?', ['%'.$digits.'%']);
            }
        }
    }

    /** SQL expression stripping the usual phone formatting from a column. */
    private static function strippedPhoneSql(string $column): string
    {
        $expr = $column;
        foreach ([' ', '-', '+', '(', ')', '.'] as $ch) {
            $expr = "REPLACE({$expr}, '{$ch}', '')";
        }

        return $expr;
    }

    public static function normalizeAfm(?string $afm): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $afm) ?? '';

        return $digits === '' ? null : $digits;
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
