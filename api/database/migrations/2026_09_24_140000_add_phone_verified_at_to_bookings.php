<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a website booking's lead mobile proved itself with a code before the booking was saved
 * (docs/booking-phone-verification.md). Null for office bookings and for bookings made while the check was off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
