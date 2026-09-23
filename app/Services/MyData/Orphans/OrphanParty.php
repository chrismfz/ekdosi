<?php

namespace App\Services\MyData\Orphans;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Models\Scopes\CompanyScope;
use App\Support\Afm;

/**
 * Identity rules shared by the orphan matcher / linker / importer — one place,
 * on the app's own ΑΦΜ identity (Afm::uniqueKey = the indexed customers.afm_key),
 * never an ad-hoc string compare.
 */
final class OrphanParty
{
    /**
     * The identity key(s) of the document's counterpart. AADE sends a foreign VAT
     * WITHOUT its country prefix and the country separately — so «EE» + «102019025»
     * is the Estonian identity, never the Greek-looking «102019025».
     *
     * @return list<string>
     */
    public static function counterpartKeys(array $doc): array
    {
        $vat = trim((string) ($doc['counterpartVat'] ?? ''));
        if ($vat === '') {
            return [];
        }
        $country = strtoupper(trim((string) ($doc['counterpartCountry'] ?? '')));
        $raw = $country !== '' && ! in_array($country, ['GR', 'EL'], true) && ! str_starts_with(strtoupper($vat), $country)
            ? $country.$vat
            : $vat;
        $key = Afm::uniqueKey($raw);

        return $key !== null ? [$key] : [];
    }

    /** Is `$rawVat` (a customer's / invoice's ΑΦΜ) the document's counterpart? */
    public static function isCounterpart(array $doc, ?string $rawVat): bool
    {
        $key = Afm::uniqueKey($rawVat);

        return $key !== null && in_array($key, self::counterpartKeys($doc), true);
    }

    /** Did WE issue it? (our ΑΦΜ is the issuer — not «unknown», not a third party). */
    public static function issuedByUs(Company $company, array $doc): bool
    {
        $ours = Afm::uniqueKey($company->afm);

        return $ours !== null && Afm::uniqueKey((string) ($doc['issuerVat'] ?? '')) === $ours;
    }

    /**
     * Is this MARK already recorded for the tenant — on any invoice (deleted too)
     * or in the mydata_marks audit trail?
     */
    public static function markTaken(Company $company, string $mark): bool
    {
        return Invoice::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()
            ->where('company_id', $company->getKey())->where('mydata_mark', $mark)->exists()
            || MyDataMark::query()->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())->where('mark', $mark)->whereNotNull('invoice_id')->exists();
    }

    /**
     * Serialise every orphan resolution of the tenant: the company row is the
     * lock, so two operators (or a double submit) can't record one MARK — or one
     * number — twice. Call inside the transaction, then re-check.
     */
    public static function lockTenant(Company $company): void
    {
        Company::query()->whereKey($company->getKey())->lockForUpdate()->first();
    }
}
