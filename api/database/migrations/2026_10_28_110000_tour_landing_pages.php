<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group tour's landing page (docs/tour-landing-pages.md): its own address and presentation — the banner photo, the
 * photos beside each day, the countdown and the home page promotion. Everything about the tour itself (title, prices,
 * dates, the days' text, what is included) stays on the package and is read from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 190)->unique();
            // One landing page per tour.
            $table->foreignId('tour_package_id')->unique()->constrained('tour_packages')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            // Blank: the package's own title and overview.
            $table->string('title_en', 255)->nullable();
            $table->string('title_bn', 255)->nullable();
            $table->text('intro_en')->nullable();
            $table->text('intro_bn')->nullable();
            // Blank: the package's cover photo.
            $table->foreignId('hero_media_id')->nullable()->constrained('media')->nullOnDelete();
            // Blank: the next scheduled departure, whichever it is.
            $table->foreignId('countdown_departure_id')->nullable()->constrained('package_departures')->nullOnDelete();
            $table->boolean('promote_on_home')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        // Photos beside a day of the itinerary, by day number: the package's days are written again on every save.
        Schema::create('tour_landing_day_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_landing_page_id')->constrained('tour_landing_pages')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tour_landing_page_id', 'day_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_landing_day_photos');
        Schema::dropIfExists('tour_landing_pages');
    }
};
