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

        // Backfill (all rows, soft-deleted included) — in PHP so the ONE rule
        // (App\Support\Afm::uniqueKey) is the one applied, on MariaDB and sqlite alike.
        DB::table('customers')->select('id', 'afm')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('customers')->where('id', $row->id)->update(['afm_key' => Afm::uniqueKey($row->afm)]);
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
