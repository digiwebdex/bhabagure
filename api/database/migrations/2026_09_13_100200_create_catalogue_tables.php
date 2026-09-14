<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-1-schema.md §3.3 plus the Phase 2 additions (docs/phase-2-website.md §4):
 * destinations, packages and their content, media library, departures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name_bn', 80);
            $table->string('name_en', 80);
            $table->char('country_code', 2)->nullable();
            $table->string('region', 20)->default('international');
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Every uploaded image. The server re-encodes uploads (which strips EXIF, including phone GPS)
        // and keeps only WebP variants; the raw upload is never stored.
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 20)->default('public');
            $table->string('directory', 120)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime', 60);
            $table->unsignedInteger('bytes')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            // { "thumb": { "path", "width", "height", "bytes" }, "card": {…}, "detail": {…}, "full": {…} }
            $table->json('variants')->nullable();
            // Seeded stock placeholders point at a remote URL instead of stored variants.
            $table->string('source_url', 500)->nullable();
            $table->boolean('is_placeholder')->default(false);
            $table->string('alt_bn', 255)->nullable();
            $table->string('alt_en', 255)->nullable();
            $table->string('credit', 160)->nullable();
            $table->string('credit_url', 500)->nullable();
            $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tour_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->unsignedInteger('wp_trip_id')->nullable()->unique();
            $table->string('slug', 190)->unique();
            $table->foreignId('destination_id')->constrained('destinations')->restrictOnDelete();
            $table->string('title_en', 255);
            $table->string('title_bn', 255)->nullable();
            $table->string('summary_en', 500)->nullable();
            $table->string('summary_bn', 500)->nullable();
            $table->unsignedTinyInteger('duration_days');
            $table->unsignedTinyInteger('duration_nights')->nullable();
            $table->decimal('regular_price', 12, 2);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->boolean('includes_airfare')->nullable();
            $table->string('group_mode', 20)->default('group');
            $table->unsignedSmallInteger('min_pax')->nullable();
            $table->string('departure_mode', 20)->default('regular');
            $table->string('difficulty', 20)->nullable();
            $table->string('source_image_url', 500)->nullable();
            $table->string('seo_title_bn', 255)->nullable();
            $table->string('seo_title_en', 255)->nullable();
            $table->string('seo_description_bn', 500)->nullable();
            $table->string('seo_description_en', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('package_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_package_id')->constrained('tour_packages')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();

            $table->unique(['tour_package_id', 'media_id']);
        });

        Schema::create('package_itinerary_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_package_id')->constrained('tour_packages')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->string('title_en', 255)->nullable();
            $table->string('title_bn', 255)->nullable();
            $table->text('body_en');
            $table->text('body_bn')->nullable();
            $table->timestamps();

            $table->unique(['tour_package_id', 'day_number']);
        });

        Schema::create('package_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_package_id')->constrained('tour_packages')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('text_en', 500);
            $table->string('text_bn', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tour_package_id', 'kind', 'sort_order']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('slug', 80);
            $table->string('name_en', 80);
            $table->string('name_bn', 80)->nullable();
            $table->timestamps();

            $table->unique(['type', 'slug']);
        });

        Schema::create('package_tag', function (Blueprint $table) {
            $table->foreignId('tour_package_id')->constrained('tour_packages')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            // Tags show in the order the editor listed them.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['tour_package_id', 'tag_id']);
        });

        Schema::create('package_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_package_id')->constrained('tour_packages')->restrictOnDelete();
            $table->date('departs_on');
            $table->date('returns_on')->nullable();
            $table->unsignedSmallInteger('seats_total');
            // "Guaranteed departure" is shown only when staff mark it (docs/phase-2-website.md §7.16).
            $table->boolean('is_guaranteed')->default(false);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('group_leader_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'departs_on']);
        });
    }

    public function down(): void
    {
        foreach (['package_departures', 'package_tag', 'tags', 'package_inclusions', 'package_itinerary_days', 'package_images', 'tour_packages', 'media', 'destinations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
