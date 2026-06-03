<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP two-factor (Filament v5 App MFA). The secret + recovery codes are stored
 * encrypted-at-rest (see the casts on App\Models\User).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('app_authentication_secret')->nullable()->after('password');
            $t->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
