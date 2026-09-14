<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-6-customer-portal.md §3.2, §4: a quotation records when its customer first opened it in the portal, how it
 * was accepted, and that the 24-hour expiry reminder went out; a payment started from the portal returns there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('sent_at');
            // staff · portal
            $table->string('accepted_via', 10)->nullable()->after('accepted_at');
            $table->timestamp('expiry_reminded_at')->nullable()->after('viewed_at');
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            // site · portal: where the customer's browser goes back to after SSLCommerz.
            $table->string('return_to', 10)->default('site')->after('method_hint');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('return_to'));
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn(['viewed_at', 'accepted_via', 'expiry_reminded_at']));
    }
};
