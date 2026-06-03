<?php

namespace App\Services\Etl;

use App\Models\Customer;
use App\Models\Note;
use App\Models\Scopes\CompanyScope;

/**
 * Keeps a single «imported from backup» internal note in sync with the remark
 * an import carries for a customer (Epsilon `Remarks` / legacy `DETAILS`).
 *
 * Idempotent by design — the ETL is re-runnable, so this upserts ONE note per
 * (customer × source='backup') instead of appending a new one each run:
 *   - non-empty remark → create or update the backup note's body
 *   - empty/blank remark → remove any stale backup note
 *
 * Runs from CLI/ETL where there's no ambient tenant, so it bypasses the
 * CompanyScope and scopes explicitly by the passed company_id.
 */
class BackupNoteSync
{
    public const SOURCE = 'backup';

    public static function sync(int $companyId, int $customerId, ?string $remark): void
    {
        $remark = trim((string) $remark);

        $match = [
            'company_id'   => $companyId,
            'notable_type' => Customer::class,
            'notable_id'   => $customerId,
            'source'       => self::SOURCE,
        ];

        $existing = Note::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where($match)
            ->first();

        if ($remark === '') {
            // Source no longer carries a remark — drop the stale note entirely
            // (force, so it doesn't linger soft-deleted and shadow a re-create).
            $existing?->forceDelete();

            return;
        }

        if ($existing !== null) {
            if ($existing->body !== $remark) {
                $existing->update(['body' => $remark]);
            }

            return;
        }

        Note::create($match + ['body' => $remark, 'author_user_id' => null]);
    }
}
