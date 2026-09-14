<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-6-customer-portal.md §3.1, §3.6, §0.4: a code sent to confirm a new phone number is kept apart from sign-in
 * codes; a new email address is confirmed with a code sent to it; one NPS answer per completed trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_login_codes', function (Blueprint $table) {
            // sign_in · change_phone
            $table->string('purpose', 20)->default('sign_in')->after('phone');
        });

        Schema::create('customer_email_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('email', 190);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });

        // Append-only: an answer stays as given.
        Schema::create('nps_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_responses');
        Schema::dropIfExists('customer_email_changes');
        Schema::table('customer_login_codes', fn (Blueprint $table) => $table->dropColumn('purpose'));
    }
};
