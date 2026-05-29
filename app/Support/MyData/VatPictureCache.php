<?php

namespace App\Support\MyData;

use App\Models\Company;
use App\Services\MyData\MyDataVatPicture;
use Illuminate\Support\Facades\Cache;

/**
 * Cache layer for the "Εικόνα από myDATA" VAT picture. The aggregation
 * paginates ALL of a tenant's AADE docs, so it's computed by a scheduler
 * (`mydata:refresh-vat-picture`) into here; the dashboard widget only READS
 * — never triggers a live AADE call on page load.
 *
 * Two fixed period keys per tenant: `month` (current month) and `quarter`
 * (current quarter). Stored with a TTL well beyond the refresh cadence so a
 * paused scheduler degrades to "stale" (with a visible fetchedAt), not empty.
 */
class VatPictureCache
{
    /** Period keys the widget + refresher agree on. */
    public const PERIODS = ['month', 'quarter'];

    /** Survive a paused scheduler for a week (cron overwrites every few hours). */
    private const TTL_SECONDS = 7 * 24 * 60 * 60;

    public static function key(int|string $companyId, string $period): string
    {
        return "mydata-vat-picture:{$companyId}:{$period}";
    }

    public static function put(Company $company, string $period, MyDataVatPicture $picture): void
    {
        Cache::put(self::key($company->getKey(), $period), $picture->toArray(), self::TTL_SECONDS);
    }

    public static function get(Company $company, string $period): ?MyDataVatPicture
    {
        $data = Cache::get(self::key($company->getKey(), $period));

        return is_array($data) ? MyDataVatPicture::fromArray($data) : null;
    }
}
