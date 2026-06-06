<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_marks', function (Blueprint $t) {
            $t->string('provider_key', 40)->nullable()->after('mydata_action');
            $t->string('authentication_code', 255)->nullable()->after('provider_key');
            $t->string('provider_delivery_state', 80)->nullable()->after('authentication_code');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_marks', function (Blueprint $t) {
            $t->dropColumn(['provider_key', 'authentication_code', 'provider_delivery_state']);
        });
    }
};
