<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-5-admin-core.md §0, §10.
 *
 * - Ownership: a record belongs to `assigned_staff_id`. Bookings staff created before this rule get their creator as
 *   owner; website records stay unassigned (the shared pool).
 * - One source vocabulary (App\Enums\LeadSource): the old `website_booking` and `website` become `website_form`.
 * - Air-ticket enquiries can be marked quoted (who and when).
 * - Booking references take one running counter (BH-2608-036 → BH-2609-037) instead of restarting each month.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')->whereNull('assigned_staff_id')->whereNotNull('created_by_staff_id')
            ->update(['assigned_staff_id' => DB::raw('created_by_staff_id')]);

        DB::table('customers')->whereIn('source', ['website_booking', 'website'])->update(['source' => 'website_form']);
        DB::table('bookings')->whereIn('source', ['website_booking', 'website'])->update(['source' => 'website_form']);

        Schema::table('inquiries', function (Blueprint $table) {
            $table->timestamp('quoted_at')->nullable()->after('status');
            $table->foreignId('quoted_by_staff_id')->nullable()->after('quoted_at')->constrained('staff')->nullOnDelete();
            $table->index(['type', 'status', 'created_at']);
            $table->index(['assigned_staff_id', 'type']);
        });

        $this->mergeBookingCounters();
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['assigned_staff_id', 'type']);
            $table->dropIndex(['type', 'status', 'created_at']);
            $table->dropConstrainedForeignId('quoted_by_staff_id');
            $table->dropColumn('quoted_at');
        });
    }

    /** The running counter continues after the highest number any monthly counter handed out. */
    private function mergeBookingCounters(): void
    {
        // Deleted bookings included: the query builder applies no soft-delete scope, and their numbers stay taken.
        $highest = DB::table('bookings')->pluck('reference')
            ->map(fn (string $reference) => preg_match('/^BH-\d{4}-(\d+)$/', $reference, $m) === 1 ? (int) $m[1] : 0)
            ->max() ?? 0;
        $monthly = DB::table('document_sequences')->where('key', 'like', 'booking:%')->max('next_value');
        $next = max($highest + 1, (int) ($monthly ?? 1));

        DB::table('document_sequences')->insertOrIgnore(['key' => 'booking', 'prefix' => 'BH', 'next_value' => $next, 'updated_at' => now()]);
        DB::table('document_sequences')->where('key', 'booking')->where('next_value', '<', $next)->update(['next_value' => $next]);
        DB::table('document_sequences')->where('key', 'like', 'booking:%')->delete();
    }
};
