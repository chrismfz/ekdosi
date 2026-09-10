<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Note;
use App\Support\Afm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * «Συγχώνευση πελατών» — two rows, one legal party (the same ΑΦΜ entered
 * twice, a legacy row + a panel/WHMCS one, …). Everything that hangs off the
 * losing row is repointed to the surviving one inside ONE transaction, the
 * fields that differ are written as an internal note on the survivor («τι
 * ήταν ο άλλος»), and the loser is FORCE-deleted (owner decision: no ghost
 * row behind the UNIQUE(company_id, afm_key) index).
 *
 * Never guesses which row is the party: the caller decides (the command
 * defaults to the one carrying the most records — see suggestKeeper()).
 * Documents are legally significant, so the whole thing is one transaction
 * and refuses rather than half-merges.
 */
class MergeCustomers
{
    /**
     * Plain FK sites: table => column. Every one is ALSO scoped to the
     * tenant's company_id, so a merge can never reach another company's row.
     *
     * @var array<string, string>
     */
    public const FOREIGN_KEYS = [
        'invoices' => 'customer_id',
        'payments' => 'customer_id',
        'quotes' => 'customer_id',
        'customer_contacts' => 'customer_id',
        'delivery_notes' => 'customer_id',
        'service_contracts' => 'customer_id',
        'pending_whmcs_invoices' => 'customer_id',
        'ai_pending_actions' => 'customer_id',
        'cmr_notes' => 'customer_id',
        'invoice_types' => 'default_customer_id',
        'customers' => 'referred_by_customer_id',
        'leads' => 'referred_by_customer_id',
    ];

    /**
     * Polymorphic sites: table => [type column, id column]. `taggables` and
     * `activity_log` carry no company_id, so they are scoped by the id alone
     * (the id already belongs to the tenant's customer).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MORPHS = [
        'notes' => ['notable_type', 'notable_id'],
        'attachments' => ['attachable_type', 'attachable_id'],
        'taggables' => ['taggable_type', 'taggable_id'],
        'activity_log' => ['subject_type', 'subject_id'],
    ];

    /**
     * Columns NEVER compared for the note: the row's own identity, the audit
     * timestamps, the derived key, and the two FKs the merge itself rewrites.
     * EVERYTHING else on `customers` is compared — a hand-kept allow-list rots
     * silently, and a column that quietly dies with the loser (the WHMCS
     * «άμεση τιμολόγηση» flag, an έκπτωση, a ΚΑΔ) is exactly what the note is for.
     */
    public const NOT_COMPARED = [
        'id', 'company_id', 'created_at', 'updated_at', 'deleted_at',
        'afm_key', 'afm_key_parked', 'sort_order', 'referred_by_customer_id', 'whmcs_reseller_routes',
    ];

    /** Greek labels for the columns an operator would recognise (fallback: the column name). */
    public const FIELD_LABELS = [
        'name' => 'Επωνυμία',
        'afm' => 'ΑΦΜ',
        'type' => 'Τύπος',
        'occupation' => 'Δραστηριότητα',
        'tax_office' => 'ΔΟΥ',
        'kad_primary' => 'ΚΑΔ',
        'address1' => 'Διεύθυνση',
        'address2' => 'Διεύθυνση 2',
        'city' => 'Πόλη',
        'postcode' => 'Τ.Κ.',
        'country' => 'Χώρα',
        'phone1' => 'Τηλέφωνο',
        'phone2' => 'Τηλέφωνο 2',
        'fax' => 'Fax',
        'email' => 'Email',
        'secondary_email' => 'Email 2',
        'discount' => 'Έκπτωση %',
        'withhold_tax' => 'Παρακράτηση',
        'vat_vies' => 'VIES',
        'payment_method_id' => 'Τρόπος πληρωμής (id)',
        'peppol_endpoint' => 'PEPPOL endpoint',
        'whmcs_client_id' => 'WHMCS client id',
        'needs_immediate_invoice' => 'Άμεση τιμολόγηση',
        'auto_email_invoices' => 'Αυτόματο email παραστατικών',
        'is_active' => 'Ενεργός',
        'is_favorite' => 'Αγαπημένος',
        'show_balance_on_pdf' => 'Υπόλοιπο στο PDF',
        'alt_customer_legacy_id' => 'alt_customer_legacy_id',
        'legacy_id' => 'legacy_id',
    ];

    /**
     * What a merge WOULD do — counts per table + the differing fields. Never
     * writes. The same numbers the command's --dry-run and the panel modal show.
     */
    public function preview(Customer $keep, Customer $drop): MergeCustomersResult
    {
        $this->assertMergeable($keep, $drop);

        $moves = [];
        foreach (self::FOREIGN_KEYS as $table => $column) {
            if (! $this->usable($table, $column)) {
                continue;
            }
            $count = $this->fkQuery($table, $column, $drop)
                // A customer never refers itself: skip the survivor's own row.
                // (`whereKeyNot` is Eloquent-only — on a query builder it silently
                // becomes a dynamic where on a «key_not» column.)
                ->when($table === 'customers', fn ($q) => $q->where('id', '!=', $keep->getKey()))
                ->count();
            if ($count > 0) {
                $moves[$table] = $count;
            }
        }
        if ($this->usable('leads', 'converted_customer_id')) {
            $origin = DB::table('leads')
                ->where('company_id', $drop->company_id)
                ->where('converted_customer_id', $drop->getKey())
                ->count();
            if ($origin > 0) {
                $moves['leads'] = ($moves['leads'] ?? 0) + $origin;
            }
        }
        foreach (self::MORPHS as $table => [$typeColumn, $idColumn]) {
            if (! $this->usable($table, $idColumn)) {
                continue;
            }
            $query = $this->morphQuery($table, $typeColumn, $idColumn, $drop);
            if ($table === 'taggables') {
                // A tag the survivor already carries is dropped, not moved.
                $query->whereNotIn('tag_id', DB::table('taggables')
                    ->where('taggable_type', Customer::class)
                    ->where('taggable_id', $keep->getKey())
                    ->pluck('tag_id'));
            }
            $count = $query->count();
            if ($count > 0) {
                $moves[$table] = ($moves[$table] ?? 0) + $count;
            }
        }

        return new MergeCustomersResult(
            keepId: (int) $keep->getKey(),
            keepName: (string) $keep->name,
            dropId: (int) $drop->getKey(),
            dropName: (string) $drop->name,
            moves: $moves,
            differences: $this->differences($keep, $drop),
        );
    }

    /**
     * Do it. Returns what was moved (the same shape as preview()).
     */
    public function __invoke(Customer $keep, Customer $drop): MergeCustomersResult
    {
        $this->assertMergeable($keep, $drop);

        return DB::transaction(function () use ($keep, $drop): MergeCustomersResult {
            // Re-read under a lock: the counts in the operator's modal may be
            // minutes old, and another operator may have moved documents since.
            $keep = Customer::query()->withTrashed()->whereKey($keep->getKey())->lockForUpdate()->firstOrFail();
            $drop = Customer::query()->withTrashed()->whereKey($drop->getKey())->lockForUpdate()->firstOrFail();
            $this->assertMergeable($keep, $drop);

            $result = $this->preview($keep, $drop);

            // Which identity keys the survivor will take over (decided here so
            // the note can say «υιοθετήθηκε» instead of «χάθηκε»).
            $adopt = [];
            foreach (['legacy_id', 'whmcs_client_id'] as $column) {
                if (blank($keep->{$column}) && filled($drop->{$column})) {
                    $adopt[$column] = $drop->{$column};
                }
            }

            foreach (self::FOREIGN_KEYS as $table => $column) {
                if (! $this->usable($table, $column)) {
                    continue;
                }
                $this->fkQuery($table, $column, $drop)
                    // …never leaving the survivor referred by itself.
                    ->when($table === 'customers', fn ($q) => $q->where('id', '!=', $keep->getKey()))
                    ->update([$column => $keep->getKey()]);
            }

            // leads.converted_customer_id is UNIQUE: only ONE lead may point at
            // the survivor. assertMergeable() refuses when both sides have one,
            // so at most one row moves here.
            if ($this->usable('leads', 'converted_customer_id')) {
                DB::table('leads')
                    ->where('company_id', $keep->company_id)
                    ->where('converted_customer_id', $drop->getKey())
                    ->update(['converted_customer_id' => $keep->getKey()]);
            }

            // Tags first: `taggables` has a UNIQUE(tag_id, taggable_*) pivot, so a
            // tag BOTH rows carry must be dropped from the loser BEFORE the move
            // (updating into it would violate the index mid-transaction).
            $this->releaseSharedTags($keep, $drop);

            foreach (self::MORPHS as $table => [$typeColumn, $idColumn]) {
                if (! $this->usable($table, $idColumn)) {
                    continue;
                }
                $this->morphQuery($table, $typeColumn, $idColumn, $drop)->update([$idColumn => $keep->getKey()]);
            }

            // At most one primary contact survives (the model's own rule).
            $this->dedupePrimaryContact($keep);

            $this->writeMergeNote($keep, $drop, $result, array_keys($adopt));

            // Force-delete: a soft-deleted twin would still hold the ΑΦΜ under
            // UNIQUE(company_id, afm_key) — the whole point of the merge.
            $drop->forceDelete();

            // …then ADOPT the identity keys the rest of the system matches on,
            // if the survivor has none. Dropping them would let the very
            // systems that created the duplicate recreate it: the Firebird ETL
            // re-inserts an unmatched legacy_id, and the WHMCS matcher/creator
            // an unmatched client id. Done after the delete — both columns are
            // unique per company.
            if ($adopt !== []) {
                // Query builder, NOT $keep->save(): the model's saving hook always
                // writes `afm_key`, which does not exist on the pre-migration
                // schema — exactly where update.sh sends the operator.
                DB::table('customers')->where('id', $keep->getKey())->update($adopt);
                $keep->forceFill($adopt)->syncOriginal();
            }

            // …and the ΑΦΜ identity itself. The survivor can be a PARKED row (the
            // legacy υποκατάστημα twin the ETL imported keyless because the row we
            // just force-deleted held the ΑΦΜ) — and every write here is query
            // builder, so the model hook that would re-derive `afm_key` never runs.
            // Without this the party ends up with NO customer holding its ΑΦΜ and
            // the matchers (WHMCS by ΑΦΜ, LeadMatcher, myDATA sync) start minting
            // duplicates of the very customer we just merged.
            $this->reclaimFreedAfmKey($keep);

            return $result;
        });
    }

    /**
     * Give the survivor the ΑΦΜ identity when it holds none and nobody else in
     * the tenant does — the merge just freed it. No-op for the ordinary merge
     * (the survivor already has its key) and for a row whose ΑΦΜ is no identity
     * at all (placeholder/blank). Never steals a key another row still holds.
     */
    private function reclaimFreedAfmKey(Customer $keep): void
    {
        // Pre-migration schema (where update.sh sends the operator) has neither column.
        if (! Schema::hasColumn('customers', 'afm_key') || $keep->afm_key !== null) {
            return;
        }

        $key = Afm::uniqueKey($keep->afm);
        if ($key === null) {
            return;
        }

        $stillHeld = DB::table('customers')
            ->where('company_id', $keep->company_id)
            ->where('afm_key', (string) $key)
            ->where('id', '!=', $keep->getKey())
            ->exists();
        if ($stillHeld) {
            return;
        }

        $values = ['afm_key' => (string) $key];
        if (Schema::hasColumn('customers', 'afm_key_parked')) {
            $values['afm_key_parked'] = false;
        }

        DB::table('customers')->where('id', $keep->getKey())->update($values);
        $keep->forceFill($values)->syncOriginal();
    }

    /**
     * Which row should survive: the one carrying the most records; a tie goes
     * to the older id (the row the documents were first filed against).
     */
    public function suggestKeeper(Customer $a, Customer $b): Customer
    {
        $countFor = function (Customer $c): int {
            $n = 0;
            foreach (self::FOREIGN_KEYS as $table => $column) {
                if ($this->usable($table, $column)) {
                    $n += $this->fkQuery($table, $column, $c)->count();
                }
            }

            return $n;
        };

        // A trashed row can never be the survivor (assertMergeable refuses it),
        // so it never wins the recommendation either.
        if ($a->trashed() !== $b->trashed()) {
            return $a->trashed() ? $b : $a;
        }

        $na = $countFor($a);
        $nb = $countFor($b);
        if ($na === $nb) {
            return $a->getKey() <= $b->getKey() ? $a : $b;
        }

        return $na > $nb ? $a : $b;
    }

    private function assertMergeable(Customer $keep, Customer $drop): void
    {
        if ($keep->getKey() === $drop->getKey()) {
            throw new RuntimeException('Ο ίδιος πελάτης — δεν συγχωνεύεται με τον εαυτό του.');
        }
        if ((int) $keep->company_id !== (int) $drop->company_id) {
            throw new RuntimeException('Οι δύο πελάτες ανήκουν σε διαφορετικές εταιρείες.');
        }
        if ($keep->trashed()) {
            throw new RuntimeException('Ο πελάτης που κρατάμε (#'.$keep->getKey().') είναι διαγραμμένος — επανέφερέ τον πρώτα.');
        }

        // leads.converted_customer_id is unique: two origin leads cannot both
        // point at the survivor, and silently dropping one erases the «από πού
        // ήρθε» link. The operator resolves it on the lead first.
        if (Schema::hasTable('leads')) {
            $leads = DB::table('leads')
                ->where('company_id', $keep->company_id)
                ->whereIn('converted_customer_id', [$keep->getKey(), $drop->getKey()])
                ->count();
            if ($leads > 1) {
                throw new RuntimeException(
                    'Και οι δύο πελάτες προέρχονται από lead (leads.converted_customer_id είναι μοναδικό). '
                    .'Αποσύνδεσε πρώτα το ένα lead και ξαναπροσπάθησε.'
                );
            }
        }
    }

    /** The table/column pair exists in THIS schema (the merge also runs pre-migration). */
    private function usable(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function fkQuery(string $table, string $column, Customer $customer)
    {
        $query = DB::table($table)->where($column, $customer->getKey());

        // Every one of these tables is tenant-owned; the extra predicate makes
        // a cross-tenant write impossible even if an id were guessed.
        if (Schema::hasColumn($table, 'company_id')) {
            $query->where('company_id', $customer->company_id);
        }

        return $query;
    }

    private function morphQuery(string $table, string $typeColumn, string $idColumn, Customer $customer)
    {
        return DB::table($table)
            ->where($typeColumn, Customer::class)
            ->where($idColumn, $customer->getKey());
    }

    /**
     * Tags both rows carry: drop them from the LOSER so the move can't violate
     * the pivot's UNIQUE(tag_id, taggable_type, taggable_id). The survivor keeps
     * its own row, so no tag is lost.
     */
    private function releaseSharedTags(Customer $keep, Customer $drop): void
    {
        if (! $this->usable('taggables', 'taggable_id')) {
            return;
        }

        $keepTagIds = DB::table('taggables')
            ->where('taggable_type', Customer::class)
            ->where('taggable_id', $keep->getKey())
            ->pluck('tag_id');

        if ($keepTagIds->isEmpty()) {
            return;
        }

        DB::table('taggables')
            ->where('taggable_type', Customer::class)
            ->where('taggable_id', $drop->getKey())
            ->whereIn('tag_id', $keepTagIds)
            ->delete();
    }

    /** Two primary contacts cannot coexist — keep the survivor's own. */
    private function dedupePrimaryContact(Customer $keep): void
    {
        if (! $this->usable('customer_contacts', 'is_primary')) {
            return;
        }

        $query = DB::table('customer_contacts')
            ->where('company_id', $keep->company_id)
            ->where('customer_id', $keep->getKey())
            ->where('is_primary', true);

        // A live contact always outranks a soft-deleted one for the slot.
        if (Schema::hasColumn('customer_contacts', 'deleted_at')) {
            $query->orderByRaw('deleted_at IS NOT NULL');
        }

        $primaries = $query->orderBy('id')->pluck('id');

        if ($primaries->count() > 1) {
            DB::table('customer_contacts')
                ->whereIn('id', $primaries->slice(1)->all())
                ->update(['is_primary' => false]);
        }
    }

    /**
     * The merged row's identity, kept as an internal note on the survivor —
     * «τι έλεγε ο άλλος» (επωνυμία, email, …) plus what moved. Pinned: it is
     * the explanation of a destructive act.
     */
    /**
     * @param  list<string>  $adopted  columns the survivor took over from the loser
     */
    private function writeMergeNote(Customer $keep, Customer $drop, MergeCustomersResult $result, array $adopted = []): void
    {
        $lines = ['Συγχώνευση πελάτη #'.$drop->getKey().' «'.$drop->name.'» σε αυτόν τον πελάτη ('.now()->format('d/m/Y H:i').').'];

        if ($result->differences !== []) {
            $lines[] = '';
            $lines[] = 'Στοιχεία που διέφεραν (κρατήθηκαν του #'.$keep->getKey().'):';
            foreach ($result->differences as $label => $pair) {
                $lines[] = in_array($pair['column'] ?? '', $adopted, true)
                    ? '• '.$label.': ΥΙΟΘΕΤΗΘΗΚΕ «'.($pair['drop'] ?? '—').'» από τον συγχωνευμένο (ο #'.$keep->getKey().' δεν είχε)'
                    : '• '.$label.': «'.($pair['keep'] ?? '—').'» ← ο συγχωνευμένος είχε «'.($pair['drop'] ?? '—').'»';
            }
        }

        if ($result->moves !== []) {
            $lines[] = '';
            $lines[] = 'Μεταφέρθηκαν: '.$result->movesLabel().'.';
        }

        Note::create([
            'company_id' => $keep->company_id,
            'notable_type' => Customer::class,
            'notable_id' => $keep->getKey(),
            'body' => implode("\n", $lines),
            'is_pinned' => true,
            'author_user_id' => auth()->id(),
        ]);
    }

    /**
     * Fields where the two rows disagree (both non-empty and different, or the
     * loser had one the survivor lacks). Label => ['keep' => …, 'drop' => …].
     *
     * @return array<string, array{keep: ?string, drop: ?string, column: string}>
     */
    private function differences(Customer $keep, Customer $drop): array
    {
        $out = [];
        foreach (Schema::getColumnListing('customers') as $column) {
            if (in_array($column, self::NOT_COMPARED, true)) {
                continue;
            }
            $a = $this->normalise($keep->getRawOriginal($column));
            $b = $this->normalise($drop->getRawOriginal($column));
            if ($b === null || $a === $b) {
                continue;   // the loser adds nothing here
            }
            $out[self::FIELD_LABELS[$column] ?? $column] = ['keep' => $a, 'drop' => $b, 'column' => $column];
        }

        return $out;
    }

    private function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
