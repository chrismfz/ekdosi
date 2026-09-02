<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Support\Afm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds customers that share an ΑΦΜ identity (`afm_key`) inside one tenant —
 * the audit behind the UNIQUE(company_id, afm_key) constraint: the migration
 * refuses to add the index while any exist, and `customers:afm-duplicates`
 * lists them for the operator to resolve. Soft-deleted rows count (the index
 * covers them too). Read-only.
 */
class CustomerAfmDuplicates
{
    /**
     * @return Collection<int, array{company_id:int, afm_key:string, customers:Collection<int, Customer>}>
     */
    public function find(?int $companyId = null): Collection
    {
        // Pre-migration (the documented pre-flight runs BEFORE `migrate`): no
        // afm_key column yet → derive the identity in PHP from `afm`.
        if (! Schema::hasColumn('customers', 'afm_key')) {
            return $this->findWithoutColumn($companyId);
        }

        $groups = DB::table('customers')
            ->select('company_id', 'afm_key', DB::raw('COUNT(*) AS n'))
            ->whereNotNull('afm_key')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->groupBy('company_id', 'afm_key')
            ->having('n', '>', 1)
            ->orderBy('company_id')
            ->orderBy('afm_key')
            ->get();

        return $groups->map(fn (object $g): array => [
            'company_id' => (int) $g->company_id,
            'afm_key' => (string) $g->afm_key,
            'customers' => Customer::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where('company_id', $g->company_id)
                ->where('afm_key', $g->afm_key)
                ->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * @return Collection<int, array{company_id:int, afm_key:string, customers:Collection<int, Customer>}>
     */
    private function findWithoutColumn(?int $companyId): Collection
    {
        $byKey = [];
        DB::table('customers')
            ->select('id', 'company_id', 'afm')
            ->whereNotNull('afm')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$byKey): void {
                foreach ($rows as $row) {
                    $key = Afm::uniqueKey($row->afm);
                    if ($key !== null) {
                        $byKey[(int) $row->company_id][$key][] = (int) $row->id;
                    }
                }
            });

        $groups = collect();
        ksort($byKey);
        foreach ($byKey as $cid => $keys) {
            ksort($keys);
            foreach ($keys as $key => $ids) {
                if (count($ids) > 1) {
                    $groups->push([
                        'company_id' => $cid,
                        'afm_key' => (string) $key,
                        'customers' => Customer::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->orderBy('id')->get(),
                    ]);
                }
            }
        }

        return $groups;
    }

    /**
     * Plain-text rendering (migration error message / CLI).
     *
     * @param  Collection<int, array{company_id:int, afm_key:string, customers:Collection<int, Customer>}>  $groups
     */
    public function describe(Collection $groups): string
    {
        return $groups->map(function (array $g): string {
            $rows = $g['customers']->map(fn (Customer $c): string => sprintf(
                '    #%d %s (ΑΦΜ «%s»)%s',
                $c->id,
                $c->name,
                $c->afm,
                $c->trashed() ? ' [ΔΙΑΓΡΑΜΜΕΝΟΣ]' : '',
            ))->implode("\n");

            return "  company_id={$g['company_id']} ΑΦΜ {$g['afm_key']}:\n{$rows}";
        })->implode("\n");
    }
}
