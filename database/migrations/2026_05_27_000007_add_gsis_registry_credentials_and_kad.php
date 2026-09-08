<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GSIS / RgWsPublic2 credentials (separate from myDATA — different service,
 * different credential pair, different auth scheme):
 *
 *  - mydata creds (already on companies): aade-user-id + Ocp-Apim-Subscription-Key,
 *    REST headers, against mydatapi.aade.gr/myDATA — for invoice submission.
 *  - gsis creds (NEW here):              username + password,
 *    SOAP WS-Security, against www1.gsis.gr/wsaade/RgWsPublic2 — for VAT
 *    registry lookup (AFM → company name / DOY / address / activity).
 *
 * gsis_password is text + encrypted cast at the model layer.
 *
 * kad_primary captures the principal activity classification (Δραστηριότητα
 * Επιχείρησης / "KYRIA") that AADE assigns to a VAT number. On companies it's
 * the issuer's primary KAD that typically prints on the invoice header. On
 * customers it's auto-filled when an operator clicks "Fetch from AADE" on
 * the customer form and the AADE registry returns activity codes.
 *
 * VARCHAR(20) leaves room for the 8-digit numeric code (e.g. "62200000")
 * plus an optional letter suffix and slack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('gsis_username', 120)->nullable()->after('mydata_production');
            $t->text('gsis_password')->nullable()->after('gsis_username');
            $t->string('kad_primary', 20)->nullable()->after('tax_office');
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->string('kad_primary', 20)->nullable()->after('tax_office');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['gsis_username', 'gsis_password', 'kad_primary']);
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn('kad_primary');
        });
    }
};
