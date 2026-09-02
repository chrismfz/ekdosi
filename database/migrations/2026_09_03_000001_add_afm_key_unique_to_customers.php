<?php

use App\Services\Customers\CustomerAfmDuplicates;
use App\Support\Afm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Ποτέ διπλός πελάτης για ένα ΑΦΜ» — enforced by the database.
 *
 * `customers.afm_key` = Afm::uniqueKey(afm) (digits for a Greek ΑΦΜ with any
 * EL/GR prefix stripped, letters kept for foreign VATs, NULL for blanks and
 * placeholders) + UNIQUE (company_id, afm_key). NULLs don't collide, so retail
 * customers without an ΑΦΜ are untouched. Soft-deleted rows ARE covered — a
 * deleted customer must be restored + reused, never re-created.
 *
 * Idempotent and fail-closed: every step re-checks, and the unique index is
 * added only when NO tenant has duplicates. If one does, the migration stops
 * with the list — resolve them (`php artisan customers:afm-duplicates`) and
 * re-run `migrate`.
 */
return new class extends Migration
{
    private const UNIQUE = 'customers_company_afm_key_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'afm_key')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->string('afm_key', 32)->nullable()->after('afm');
            });
        }

        // Backfill (all rows, soft-deleted included). The dominant case — a
        // plain 9-digit ΑΦΜ that is not a placeholder — is ONE statement
        // (key = afm); everything else (prefixes, spaces, foreign VAT, blanks)
        // goes through the ONE PHP rule (App\Support\Afm::uniqueKey), grouped
        // by resulting key so a chunk costs a handful of UPDATEs, not one per row.
        // A row whose ΑΦΜ was blanked since (e.g. while resolving a duplicate
        // with raw SQL) must lose its stale key — the two backfill steps below
        // only touch rows WITH an ΑΦΜ.
        DB::table('customers')->where(fn ($q) => $q->whereNull('afm')->orWhere('afm', ''))->update(['afm_key' => null]);

        $nineDigits = DB::getDriverName() === 'sqlite'
            ? "afm GLOB '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'"
            : "afm REGEXP '^[0-9]{9}$'";
        $placeholders = implode(',', array_map(fn (int $d): string => "'".str_repeat((string) $d, 9)."'", range(0, 9)));
        DB::statement("UPDATE customers SET afm_key = afm WHERE afm IS NOT NULL AND {$nineDigits} AND afm NOT IN ({$placeholders})");

        DB::table('customers')
            ->select('id', 'afm')
            ->whereNotNull('afm')
            ->where('afm', '<>', '')
            ->whereRaw("NOT ({$nineDigits} AND afm NOT IN ({$placeholders}))")
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $byKey = [];
                foreach ($rows as $row) {
                    $byKey[Afm::uniqueKey($row->afm) ?? ''][] = $row->id;
                }
                foreach ($byKey as $key => $ids) {
                    DB::table('customers')->whereIn('id', $ids)->update(['afm_key' => $key === '' ? null : $key]);
                }
            });

        $duplicates = app(CustomerAfmDuplicates::class)->find();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                "Δεν μπορεί να μπει το UNIQUE(company_id, afm_key): υπάρχουν πελάτες με το ίδιο ΑΦΜ.\n"
                .app(CustomerAfmDuplicates::class)->describe($duplicates)
                ."\nΔιόρθωσε/συγχώνευσε τους διπλούς (php artisan customers:afm-duplicates) και ξανατρέξε migrate."
            );
        }

        // leads.afm carries the same identity form from now on (LeadForm stores
        // Afm::leadAfm) — bring existing lead rows in line so lead↔customer and
        // lead↔lead dedupe compare like with like. NORMALISE, never blank: text
        // with no identity («000000000», «N/A») is the operator's answer and is
        // kept as typed. Runs after the duplicate check so a refused migration
        // rewrites nothing.
        if (Schema::hasTable('leads')) {
            DB::table('leads')->select('id', 'afm')->whereNotNull('afm')->orderBy('id')->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $key = Afm::leadAfm($row->afm);
                    if ($key !== $row->afm) {
                        DB::table('leads')->where('id', $row->id)->update(['afm' => $key]);
                    }
                }
            });
        }

        if (! Schema::hasIndex('customers', self::UNIQUE)) {
            Schema::table('customers', function (Blueprint $t) {
                $t->unique(['company_id', 'afm_key'], self::UNIQUE);
            });
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (Schema::hasIndex('customers', self::UNIQUE)) {
                $t->dropUnique(self::UNIQUE);
            }
            $t->dropColumn('afm_key');
        });
    }
};
