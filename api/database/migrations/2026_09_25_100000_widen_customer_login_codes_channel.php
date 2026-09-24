<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A code now goes by every channel at once (docs/booking-phone-verification.md §6): `channel` lists the ones that took
 * it, e.g. "sms,whatsapp,email", or "none".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_login_codes', function (Blueprint $table) {
            $table->string('channel', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_login_codes', function (Blueprint $table) {
            $table->string('channel', 10)->change();
        });
    }
};
