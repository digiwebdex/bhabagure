<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers write reviews on the website, with up to five trip photos (docs/customer-reviews.md). A customer's review
 * waits for staff (`reviewed_at` empty) and is approved (published) or rejected; staff-written reviews are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // staff: written in Admin → Reviews; customer: sent from the website form.
            $table->string('source', 20)->default('staff')->after('sort_order');
            // The customer's mobile (8801…), for the office and the booking match; never shown on the website.
            $table->string('phone', 20)->nullable()->after('source');
            // A booking with that number: the review is from someone who travelled with the agency.
            $table->foreignId('booking_id')->nullable()->after('phone')->constrained('bookings')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('booking_id');
            $table->foreignId('reviewed_by_staff_id')->nullable()->after('reviewed_at')->constrained('staff')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('reviewed_by_staff_id');
            $table->string('reject_reason', 300)->nullable()->after('rejected_at');

            $table->index(['source', 'reviewed_at']);
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            // Staff can hide a photo that shouldn't be on the website without rejecting the review.
            $table->boolean('is_shown')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropConstrainedForeignId('reviewed_by_staff_id');
            $table->dropIndex(['source', 'reviewed_at']);
            $table->dropColumn(['source', 'phone', 'reviewed_at', 'rejected_at', 'reject_reason']);
        });
    }
};
