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
 *   - empty/blank remark → NO-OP. We never auto-delete: a tenant can be fed by
 *     more than one source (legacy Firebird AND Epsilon), and a remark absent
 *     from one export must not wipe one imported from another source/run.
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
            // No remark in this source → leave any existing backup note alone
            // (never auto-delete; see the class docblock).
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
