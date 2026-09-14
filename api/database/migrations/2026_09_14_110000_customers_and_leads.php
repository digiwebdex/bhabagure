<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-5-admin-core.md §4.4, §5. A lead's stage on the board (New · Contacted · Quoted · Converted) is derived from
 * the contact log, quotations and bookings; only what can't be derived is stored: what they're interested in, and that
 * they were lost and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('interest', 190)->nullable()->after('source');
            $table->timestamp('lost_at')->nullable()->after('interest');
            $table->string('lost_reason', 300)->nullable()->after('lost_at');
            $table->index(['stage', 'lost_at']);
        });

        // Append-only: a call that happened stays in the log; a correction is a new entry.
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('outcome', 20);
            $table->text('note')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'occurred_at']);
            $table->index('next_follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['stage', 'lost_at']);
            $table->dropColumn(['interest', 'lost_at', 'lost_reason']);
        });
    }
};
