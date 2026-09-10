<?php

namespace App\Services\Etl;

use App\Support\Afm;
use Illuminate\Support\Facades\DB;

/**
 * THE ΑΦΜ-identity check for a legacy Firebird `CUSTOMER` table — one rule
 * (`App\Support\Afm::uniqueKey`, the same one behind UNIQUE(company_id, afm_key))
 * shared by every caller so the answer can never drift:
 *
 *  - `migrate:firebird`            — the fail-closed guard, before any write;
 *  - `migrate:firebird --dry-run`  — the same guard, read-only, no company created;
 *  - `FirebirdConnectionTester`    — the «Έλεγχος σύνδεσης» probe, so the operator
 *                                    learns about it BEFORE a gbak restore + import.
 *
 * READ-ONLY on both sides: the legacy database is only ever SELECTed (it stays a
 * pristine archive), and nothing here writes to MariaDB either.
 */
final class LegacyAfmConflicts
{
    /**
     * @param  list<array{id:int, afm:?string, name:?string}>  $rows  legacy customers, already charset-cleaned
     * @param  int|null  $companyId  target tenant (null = skip the ekdosi-side check)
     * @param  list<int>  $keepers  CUST_IDs the operator named with `--afm-keep`
     */
    public function find(array $rows, ?int $companyId = null, array $keepers = []): LegacyAfmConflictReport
    {
        // One pass: key → source rows (in-source duplicates) and CUST_ID → key
        // (the ekdosi-side check). Keys stay STRINGS — a numeric array key would
        // bind as int against the varchar index.
        $byKey = [];
        $keyById = [];
        $sourceIds = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $sourceIds[$id] = true;
            $key = Afm::uniqueKey($r['afm'] ?? null);
            if ($key === null) {
                continue;   // placeholder / blank / free text — never an identity
            }
            $byKey[(string) $key][] = ['id' => $id, 'name' => $r['name'] ?? null];
            $keyById[$id] = (string) $key;
        }

        $keepers = array_values(array_unique(array_map('intval', $keepers)));
        $usedKeepers = [];

        $groups = [];
        foreach ($byKey as $key => $entries) {
            if (count($entries) < 2) {
                continue;
            }
            $ids = array_column($entries, 'id');
            $named = array_values(array_intersect($keepers, $ids));
            $usedKeepers = array_merge($usedKeepers, $named);

            $groups[] = [
                'key' => (string) $key,
                'entries' => $entries,
                // Two keepers for one ΑΦΜ is a contradiction, not a choice — the
                // report refuses it rather than picking the first.
                'keeper' => count($named) === 1 ? $named[0] : null,
                'contradiction' => count($named) > 1 ? $named : [],
            ];
        }

        return new LegacyAfmConflictReport(
            groups: $groups,
            localOwners: $companyId === null ? [] : $this->localOwners($byKey, $keyById, $sourceIds, $companyId),
            unusedKeepers: array_values(array_diff($keepers, $usedKeepers)),
            tenantChecked: $companyId !== null,
        );
    }

    /**
     * Convenience for callers holding raw legacy rows: map Firebird column names
     * onto the shape find() wants, through a charset-cleaning callback.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>, string): ?string  $field
     * @return list<array{id:int, afm:?string, name:?string}>
     */
    public function mapLegacyRows(array $rows, callable $field): array
    {
        return array_map(fn (array $r): array => [
            'id' => (int) $r['CUST_ID'],
            'afm' => $field($r, 'AFM'),
            'name' => $field($r, 'NAME'),
        ], $rows);
    }

    /**
     * The ekdosi side (the parallel-run week): a local row that owns one of the
     * source ΑΦΜ and would NOT be released by this run — i.e. it has no
     * `legacy_id` (made in the panel) or its `legacy_id` no longer exists in the
     * source. Rows that ARE in the source get their `afm_key` released before the
     * upserts (see MigrateFromFirebird::copyCustomers), so moves and swaps are fine.
     *
     * @param  array<string, list<array{id:int, name:?string}>>  $byKey
     * @param  array<int, string>  $keyById
     * @param  array<int, true>  $sourceIds
     * @return list<array{key:string, id:int, name:string, legacy_id:?int, trashed:bool, claimant:?int}>
     */
    private function localOwners(array $byKey, array $keyById, array $sourceIds, int $companyId): array
    {
        if ($keyById === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_map('strval', array_keys($byKey)), 500) as $keys) {
            $owners = DB::table('customers')
                ->where('company_id', $companyId)
                ->whereIn('afm_key', $keys)
                ->get(['id', 'name', 'afm_key', 'legacy_id', 'deleted_at']);

            foreach ($owners as $o) {
                $ownerLegacy = $o->legacy_id !== null ? (int) $o->legacy_id : null;
                // Any row this run rewrites (its legacy_id is in the source — keyed
                // or not, e.g. corrected to a placeholder) gets its key released first.
                if ($ownerLegacy !== null && isset($sourceIds[$ownerLegacy])) {
                    continue;
                }
                $claimant = array_search((string) $o->afm_key, $keyById, true);
                $out[] = [
                    'key' => (string) $o->afm_key,
                    'id' => (int) $o->id,
                    'name' => (string) $o->name,
                    'legacy_id' => $ownerLegacy,
                    'trashed' => $o->deleted_at !== null,
                    'claimant' => $claimant === false ? null : (int) $claimant,
                ];
            }
        }

        return $out;
    }
}
