<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * English (Latin-script) identity for a company — the CMR «Sender» (box 1) and
 * any other international transport document needs the official Latin name +
 * address (a CMR for GR→BG must be in English). Optional: when blank we fall
 * back to a transliteration of the Greek fields (App\Support\TransliterateGreek).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('name_en')->nullable()->after('name');
            $t->string('address_en')->nullable()->after('address');
            $t->string('city_en')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['name_en', 'address_en', 'city_en']);
        });
    }
};
