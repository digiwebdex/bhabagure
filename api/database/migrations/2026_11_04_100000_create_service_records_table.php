<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an invoice line sold, when it is an air ticket, a hotel booking or a tour package (docs/invoice-services.md):
 * PNR or reservation number, route, dates and status. Kept beside the lines, not on them: an issued invoice's lines are
 * frozen and rewritten by a correction, but a PNR, a status or a new flight time changes after issue. `line_key` ties a
 * record to its line through a correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->char('line_key', 26)->unique();
            $table->string('type', 20);
            $table->string('passenger_name', 160)->nullable();
            // The PNR, or the hotel's reservation / confirmation number.
            $table->string('reference', 60)->nullable();
            $table->string('trip_type', 12)->nullable();
            $table->string('route_from', 80)->nullable();
            $table->string('route_to', 80)->nullable();
            // Departure and return; check-in and check-out.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('hotel_name', 160)->nullable();
            $table->string('room_type', 80)->nullable();
            $table->string('package_name', 160)->nullable();
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->nullOnDelete();
            $table->string('status', 20)->nullable();
            $table->text('schedule_note')->nullable();
            $table->timestamp('schedule_changed_at')->nullable();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'starts_on']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->char('line_key', 26)->nullable()->after('kind')->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['line_key']);
            $table->dropColumn('line_key');
        });
        Schema::dropIfExists('service_records');
    }
};
