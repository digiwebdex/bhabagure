<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A voucher's earlier files (docs/booking-vouchers.md §6, 2026-10-03): when staff replace a voucher's file, the file it
 * had moves here, still encrypted where it was, and can still be opened. Nothing a supplier confirmed is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_voucher_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_voucher_id')->constrained('booking_vouchers')->cascadeOnDelete();
            $table->string('disk', 20);
            $table->string('path', 191);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->string('original_name', 191);
            // Who uploaded this file and when, carried over from the voucher; who replaced it, and when (created_at).
            $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('replaced_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        // When the voucher's current file arrived: the upload, or the latest replacement.
        Schema::table('booking_vouchers', function (Blueprint $table) {
            $table->timestamp('file_uploaded_at')->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('booking_vouchers', fn (Blueprint $table) => $table->dropColumn('file_uploaded_at'));
        Schema::dropIfExists('booking_voucher_files');
    }
};
