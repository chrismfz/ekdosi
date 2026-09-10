<?php

namespace App\Services\Etl;

/**
 * The outcome of an ΑΦΜ-identity check between a legacy Firebird `CUSTOMER`
 * table and the target tenant — see LegacyAfmConflicts for the rules.
 *
 * Two independent kinds of conflict live here, because they are fixed in two
 * different places:
 *
 *  - `groups`      — two legacy CUST_IDs share ONE ΑΦΜ identity. Resolved by the
 *                    operator naming the keeper (`--afm-keep=CUST_ID`); the legacy
 *                    database is never written to.
 *  - `localOwners` — a customer already in ekdosi holds an ΑΦΜ the source hands to
 *                    someone else, and this run would NOT release it (no legacy_id,
 *                    or a legacy_id that no longer exists in the source). Resolved
 *                    inside ekdosi (`customers:afm-duplicates` → `customers:merge`).
 *
 * A group WITH a keeper is not a blocker: the keeper takes the identity, every
 * other row of the group imports with `afm_key = NULL` (it keeps its ΑΦΜ text,
 * its documents and its history — it simply does not hold the identity) and is
 * listed by `parked()` so the ETL can park it and warn.
 */
final class LegacyAfmConflictReport
{
    /**
     * @param  list<array{key:string, entries:list<array{id:int, name:?string}>, keeper:?int, contradiction:list<int>}>  $groups
     * @param  list<array{key:string, id:int, name:string, legacy_id:?int, trashed:bool, claimant:?int}>  $localOwners
     * @param  list<int>  $unusedKeepers  --afm-keep values that matched no conflict group
     * @param  bool  $tenantChecked  false when no tenant was given (pre-import probe of a not-yet-created company)
     */
    public function __construct(
        public readonly array $groups = [],
        public readonly array $localOwners = [],
        public readonly array $unusedKeepers = [],
        public readonly bool $tenantChecked = true,
    ) {}

    /** Groups that still need an operator decision (no keeper, or two keepers named). */
    public function unresolvedGroups(): array
    {
        return array_values(array_filter(
            $this->groups,
            fn (array $g): bool => $g['keeper'] === null || $g['contradiction'] !== [],
        ));
    }

    /** Groups the operator already resolved with `--afm-keep`. */
    public function resolvedGroups(): array
    {
        return array_values(array_filter(
            $this->groups,
            fn (array $g): bool => $g['keeper'] !== null && $g['contradiction'] === [],
        ));
    }

    /**
     * CUST_IDs that import WITHOUT the ΑΦΜ identity (the non-keepers of a
     * resolved group), as `[CUST_ID => ['key' => …, 'keeper' => …, 'name' => …]]`.
     *
     * @return array<int, array{key:string, keeper:int, name:?string}>
     */
    public function parked(): array
    {
        $out = [];
        foreach ($this->resolvedGroups() as $g) {
            foreach ($g['entries'] as $entry) {
                if ($entry['id'] !== $g['keeper']) {
                    $out[$entry['id']] = ['key' => $g['key'], 'keeper' => $g['keeper'], 'name' => $entry['name']];
                }
            }
        }

        return $out;
    }

    /** True when the import cannot proceed: an undecided group or a local owner. */
    public function hasBlockers(): bool
    {
        return $this->unresolvedGroups() !== [] || $this->localOwners !== [];
    }

    /** True when there is anything at all worth telling the operator about. */
    public function isEmpty(): bool
    {
        return $this->groups === [] && $this->localOwners === [];
    }

    /** One line for a UI notification / probe summary. */
    public function summary(): string
    {
        if ($this->isEmpty()) {
            return 'Κανένα διπλό ΑΦΜ πελάτη.';
        }

        $parts = [];
        if ($this->groups !== []) {
            $parts[] = count($this->groups).' διπλά ΑΦΜ μέσα στη legacy βάση';
        }
        if ($this->localOwners !== []) {
            $parts[] = count($this->localOwners).' ΑΦΜ που κρατά ήδη άλλος πελάτης στο ekdosi';
        }

        return implode(' · ', $parts);
    }

    /**
     * The full operator-facing report: what collides, and — precisely — WHERE
     * each kind is fixed. The ETL never picks a winner on its own; the choice is
     * legally significant (documents and balances hang off both rows).
     */
    public function describe(): string
    {
        $lines = [];

        foreach ($this->groups as $g) {
            $who = implode(' | ', array_map(
                fn (array $e): string => $e['id'].' '.($e['name'] ?? ''),
                $g['entries'],
            ));
            $lines[] = "  ΑΦΜ {$g['key']} (μέσα στη legacy βάση): {$who}";

            if ($g['contradiction'] !== []) {
                $lines[] = '      ✗ δόθηκαν δύο keepers για το ίδιο ΑΦΜ ('
                    .implode(', ', $g['contradiction']).') — μόνο ΕΝΑΣ κρατά την ταυτότητα';

                continue;
            }
            if ($g['keeper'] !== null) {
                $others = implode(', ', array_map(
                    fn (array $e): string => (string) $e['id'],
                    array_filter($g['entries'], fn (array $e): bool => $e['id'] !== $g['keeper']),
                ));
                $lines[] = "      ✓ κρατά το {$g['keeper']} — το {$others} μπαίνει χωρίς ταυτότητα ΑΦΜ";

                continue;
            }
            $options = implode(' ή ', array_map(
                fn (array $e): string => '--afm-keep='.$e['id'],
                $g['entries'],
            ));
            $lines[] = "      → διάλεξε ποιος κρατά την ταυτότητα: {$options}";
        }

        foreach ($this->localOwners as $o) {
            $lines[] = "  ΑΦΜ {$o['key']}: υπάρχει ήδη στο ekdosi ως #{$o['id']} «{$o['name']}»"
                .($o['legacy_id'] !== null ? " (legacy_id {$o['legacy_id']} — δεν υπάρχει πια στην πηγή)" : ' (χωρίς legacy_id — φτιάχτηκε στο panel)')
                .($o['trashed'] ? ' [ΔΙΑΓΡΑΜΜΕΝΟΣ]' : '')
                .($o['claimant'] !== null ? " — η πηγή το δίνει σε CUST_ID {$o['claimant']}" : '');
        }

        foreach ($this->unusedKeepers as $id) {
            $lines[] = "  (το --afm-keep={$id} δεν αντιστοιχεί σε κανένα διπλό ΑΦΜ — αγνοήθηκε)";
        }

        return implode("\n", $lines);
    }

    /** What to do about it — appended to describe() when the run is refused. */
    public function howTo(): string
    {
        $howTo = [];
        if ($this->unresolvedGroups() !== []) {
            $howTo[] = 'τα διπλά ΜΕΣΑ στη legacy λύνονται ΧΩΡΙΣ να πειραχτεί η legacy βάση: '
                .'--afm-keep=CUST_ID ανά ΑΦΜ (ο άλλος μπαίνει με τα πάντα του — παραστατικά, '
                .'ιστορικό, ΑΦΜ ως κείμενο — απλώς χωρίς την ταυτότητα ΑΦΜ, και μετά συγχωνεύεται '
                .'ή μένει ως υποκατάστημα μέσα στο ekdosi)';
        }
        if ($this->localOwners !== []) {
            $howTo[] = 'οι τοπικοί κάτοχοι διορθώνονται στο ekdosi (php artisan customers:afm-duplicates)';
        }

        return ucfirst(implode('· ', $howTo))
            .' και ξανατρέξε — ο στόχος επιβάλλει UNIQUE(company_id, afm_key).';
    }
}
