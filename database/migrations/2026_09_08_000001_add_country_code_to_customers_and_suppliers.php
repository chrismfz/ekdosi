<?php

use App\Support\IsoCountry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-011 (Option B): a normalised ISO-3166-1 alpha-2 `country_code` alongside the
 * existing free-text `country` on both party tables.
 *
 * `country` stays the raw/legacy label (customers hold «ΙΤΑΛΙΑ» / 'Italy' from the
 * Firebird import; the column is never validated on save). `country_code` is the
 * clean canonical value — always null or a real ISO code — so a Filament Select can
 * bind to it WITHOUT the implicit `in:` rule rejecting a legacy raw value (the exact
 * regression that reverted the first MYD-011 attempt). It is derived on save
 * (`Customer`/`Supplier::booted`), populated by the ETL, and backfilled once by
 * `ekdosi:backfill-country-codes`.
 *
 * `suppliers.country` also loses its `default('GR')` NOT-NULL: that default is what
 * froze a foreign supplier as Greek on a ΔΑ when the operator never set a country.
 * With it gone, a blank supplier country resolves to null → the delivery submitter
 * REFUSES (never a silent GR), which is the bug this issue exists to close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable()->after('country');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable()->after('country');
            // Drop the GR default + NOT NULL so a blank foreign supplier is not frozen.
            $table->string('country', 2)->nullable()->default(null)->change();
        });

        // Backfill the cache immediately so there is no un-normalised window after
        // deploy (the standalone `ekdosi:backfill-country-codes` remains for re-runs).
        $this->backfill('customers');
        $this->backfill('suppliers');
    }

    /**
     * Set `country_code` from the free-text `country` on every row that still lacks
     * one. Groups the UPDATEs by resolved ISO so a table is a handful of statements,
     * not one-per-row. Idempotent (guarded by the NULL filter).
     */
    private function backfill(string $table): void
    {
        $byCode = [];
        DB::table($table)->whereNull('country_code')->whereNotNull('country')
            ->orderBy('id')
            ->select(['id', 'country'])
            ->chunk(1000, function ($rows) use (&$byCode): void {
                foreach ($rows as $row) {
                    $code = IsoCountry::tryNormalise($row->country);
                    if ($code !== null) {
                        $byCode[$code][] = $row->id;
                    }
                }
            });

        foreach ($byCode as $code => $ids) {
            foreach (array_chunk($ids, 1000) as $batch) {
                DB::table($table)->whereIn('id', $batch)->update(['country_code' => $code]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('country_code');
        });

        // Restore the NULLs to 'GR' FIRST — up() allowed a blank supplier country, and
        // re-adding NOT NULL over an existing NULL row would abort the rollback.
        DB::table('suppliers')->whereNull('country')->update(['country' => 'GR']);

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('country_code');
            $table->string('country', 2)->default('GR')->change();
        });
    }
};
