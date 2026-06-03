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
 *   - non-empty remark → create, or restore+update the existing (incl. a
 *     soft-deleted) backup note — never a duplicate
 *   - empty/blank remark → remove any backup note (live or trashed)
 *
 * The lookup is `withTrashed()` on purpose: a backup note an operator deleted
 * via the UI is soft-deleted; without this the next import would create a
 * second live note and leave the trashed ghost behind.
 *
 * Runs from CLI/ETL where there's no ambient tenant, so it bypasses the
 * CompanyScope and scopes explicitly by the passed company_id.
 */
class BackupNoteSync
{
    public const SOURCE = Note::SOURCE_BACKUP;

    public static function sync(int $companyId, int $customerId, ?string $remark): void
    {
        $remark = trim((string) $remark);

        $match = [
            'company_id'   => $companyId,
            'notable_type' => Customer::class,
            'notable_id'   => $customerId,
            'source'       => self::SOURCE,
        ];

        if ($remark === '') {
            // Source no longer carries a remark — drop any backup note (live or
            // trashed) in one statement, no SELECT-first.
            Note::withTrashed()
                ->withoutGlobalScope(CompanyScope::class)
                ->where($match)
                ->forceDelete();

            return;
        }

        $existing = Note::withTrashed()
            ->withoutGlobalScope(CompanyScope::class)
            ->where($match)
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if ($existing->body !== $remark) {
                $existing->update(['body' => $remark]);
            }

            return;
        }

        Note::create($match + ['body' => $remark, 'author_user_id' => null]);
    }
}
