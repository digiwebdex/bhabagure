<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-6-customer-portal.md §0.1, §3.1: customers sign in with a one-time code sent to their phone — no passwords.
 * The first code on a number that already has a customer record claims it. Codes are stored hashed and never written
 * to the message log (a code is a credential; staff who can read the log must not be able to sign in as a customer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            // sms · whatsapp · none (nothing could deliver it)
            $table->string('channel', 10);
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // First signed in to the portal (a record created by staff or a booking becomes the customer's own).
            $table->timestamp('portal_claimed_at')->nullable()->after('last_login_at');
            // Staff can stop a customer signing in; bookings and messages are unaffected.
            $table->timestamp('portal_disabled_at')->nullable()->after('portal_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_claimed_at', 'portal_disabled_at']);
        });
        Schema::dropIfExists('customer_login_codes');
    }
};
