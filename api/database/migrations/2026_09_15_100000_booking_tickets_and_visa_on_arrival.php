<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two Phase 6 gaps closed (docs/phase-6-customer-portal.md §8):
 *
 *  - E-tickets recorded on bookings, per traveller, so the readiness checklist can show them. A mistake is voided with
 *    a reason and a new ticket recorded; the row stays.
 *  - Destinations where travellers get the visa on arrival: a traveller's visa and insurance default to "not required"
 *    there, so the checklist doesn't show blanks. Staff can still set either for one traveller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->boolean('visa_on_arrival')->default(false)->after('region');
        });
        // Decided 2026-09-15. Staff change it per destination in the CMS.
        DB::table('destinations')->whereIn('slug', ['nepal', 'thailand'])->update(['visa_on_arrival' => true]);

        Schema::create('booking_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('booking_traveller_id')->constrained('booking_travellers')->cascadeOnDelete();
            $table->string('airline', 80);
            $table->string('pnr', 12);
            $table->string('ticket_number', 20);
            // DAC–KTM–DAC
            $table->string('route', 80)->nullable();
            $table->date('departs_on')->nullable();
            // The e-ticket itself, encrypted on the private disk like traveller documents. Optional: the number is the ticket.
            $table->string('disk', 20)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->foreignId('issued_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'voided_at']);
            $table->index('ticket_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_tickets');
        Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn('visa_on_arrival'));
    }
};
