<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The group tour gallery on the home page: photos of the agency's travellers on their trips (docs/group-tour-gallery.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_photos', function (Blueprint $table) {
            $table->id();
            // The trip it shows ("Mustang, Nepal"): the caption under the photo, and its alt text.
            $table->string('caption_bn', 160);
            $table->string('caption_en', 160);
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            // The month of the trip, when known; stored as its first day.
            $table->date('trip_month')->nullable();
            // The tour it was, for "See this tour" — shown only while that package is published.
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_photos');
    }
};
