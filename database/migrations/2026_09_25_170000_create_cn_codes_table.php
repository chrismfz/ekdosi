<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Συνδυασμένη Ονοματολογία (ΣΟ / CN) — the reference list behind the products' TARIC
 * («Ενιαία Κωδικοποίηση Ειδών», mandatory 1/1/2027). GLOBAL (not tenant-owned, like the
 * myDATA code tables): one row per (year, 8-digit CN code) with the Greek description and
 * its 4 › 6 › 8-digit path for search.
 *
 * Source: Combined Nomenclature 2026 (Commission Implementing Regulation (EU) 2025/1926),
 * Publications Office of the EU, data.europa.eu «combined-nomenclature-2026» (SKOS/RDF),
 * © European Union, reused under the Commission reuse notice (CC BY 4.0). Bundled as
 * database/data/cn2026_el.csv.gz and loaded here so a fresh install is never empty;
 * later years come from «Κωδικοί ΣΟ / TARIC» → «Ενημέρωση από ΕΕ» (or `cn:import`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cn_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->char('code', 8);
            $table->text('description_el');
            $table->text('path_el');
            $table->timestamps();

            $table->unique(['year', 'code']);
            $table->index('code');
        });

        $file = database_path('data/cn2026_el.csv.gz');
        if (! is_file($file) || ! function_exists('gzopen')) {
            return;   // no zlib → the list comes from «Ενημέρωση από ΕΕ» / cn:import instead
        }

        $h = gzopen($file, 'r');
        fgetcsv($h, escape: '');   // header
        $now = now();
        $batch = [];
        while (($r = fgetcsv($h, escape: '')) !== false) {
            if (count($r) < 3 || ! preg_match('/^\d{8}$/', (string) $r[0])) {
                continue;
            }
            $batch[] = ['year' => 2026, 'code' => $r[0], 'description_el' => $r[1], 'path_el' => $r[2],
                'created_at' => $now, 'updated_at' => $now];
            if (count($batch) === 500) {
                DB::table('cn_codes')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('cn_codes')->insert($batch);
        }
        gzclose($h);
    }

    public function down(): void
    {
        Schema::dropIfExists('cn_codes');
    }
};
