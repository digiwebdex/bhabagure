<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step sign-in with an authenticator app (docs/two-step-sign-in.md): each staff member's own secret, kept encrypted
 * with APP_KEY; set while they scan the QR code and in force once they confirm a code. Recovery codes are kept only as
 * keyed hashes. The last time step accepted makes each code work once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('must_change_password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_step']);
        });
    }
};
