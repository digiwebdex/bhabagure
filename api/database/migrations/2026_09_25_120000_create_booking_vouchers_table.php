<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers' confirmation vouchers and contracts for upcoming bookings (docs/booking-vouchers.md): a title, the file
 * (PDF or JPG, encrypted on the private disk), and optionally the booking and the service date. Archived, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_vouchers', function (Blueprint $table) {
            $table->id();
            // What the voucher is, or whose: "Hotel Himalaya — Kathmandu, 3 rooms, 12–15 Oct".
            $table->string('title', 160);
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            // Check-in or travel date, when there is one: upcoming vouchers sort by it.
            $table->date('service_date')->nullable();
            $table->string('disk', 20);
            $table->string('path', 191);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            // The name it was uploaded with, for downloads.
            $table->string('original_name', 191);
            $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('archive_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['archived_at', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_vouchers');
    }
};
