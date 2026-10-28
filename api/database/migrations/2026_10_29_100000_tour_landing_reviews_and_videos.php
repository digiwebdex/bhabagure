<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A landing page's reviews and videos (docs/tour-landing-pages.md §9): the reviews it puts first (the tour's other
 * published reviews follow, read from the reviews themselves), and its guests' video feedback and travel vlogs, as links
 * to YouTube or Facebook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_landing_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_landing_page_id')->constrained('tour_landing_pages')->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tour_landing_page_id', 'review_id']);
        });

        Schema::create('tour_landing_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_landing_page_id')->constrained('tour_landing_pages')->cascadeOnDelete();
            // guest: "what our guests say"; vlog: "related travel vlogs".
            $table->string('section', 10);
            // youtube | facebook, from the link.
            $table->string('provider', 10);
            $table->string('url', 500);
            $table->string('youtube_id', 20)->nullable();
            $table->string('title_en', 160)->nullable();
            $table->string('title_bn', 160)->nullable();
            // A Facebook video's picture (Facebook gives none without its API); a YouTube one's own when chosen.
            $table->foreignId('thumbnail_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tour_landing_page_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_landing_videos');
        Schema::dropIfExists('tour_landing_reviews');
    }
};
