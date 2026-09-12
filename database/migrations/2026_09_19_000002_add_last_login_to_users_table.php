<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised last-login snapshot on operator accounts — mirrors what
 * customer_users already carries (see create_customer_users_table). Written on
 * the Auth `Login` event (App\Listeners\RecordAuthEvent), surfaced as columns on
 * the «Χρήστες» list so an admin can see, at a glance, when/where each operator
 * last signed in — «έστω το IP», even after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');  // IPv6-safe
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'last_login_ip']);
        });
    }
};
