<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-2-website.md §4 — CMS-managed website content, pricing settings and public form submissions.
 *
 * Every CMS list has a `status` of `draft` or `published`. The public API returns published rows only,
 * so a half-written post or the unfinished Thai 02 package is invisible to visitors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_slabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('min_pax')->unique();
            $table->decimal('discount_percent', 5, 2);
            $table->timestamps();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_bn', 120);
            $table->string('name_en', 120);
            $table->decimal('price', 12, 2);
            $table->string('unit', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name_bn', 80);
            $table->string('name_en', 80);
            $table->string('tone', 20)->default('blue');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 190)->unique();
            $table->foreignId('blog_category_id')->constrained('blog_categories')->restrictOnDelete();
            $table->string('title_bn', 255);
            $table->string('title_en', 255);
            $table->string('excerpt_bn', 1000)->nullable();
            $table->string('excerpt_en', 1000)->nullable();
            $table->mediumText('body_bn')->nullable();
            $table->mediumText('body_en')->nullable();
            $table->string('author_bn', 120)->nullable();
            $table->string('author_en', 120)->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('reading_minutes')->default(1);
            $table->boolean('reading_minutes_override')->default(false);
            $table->string('seo_title_bn', 255)->nullable();
            $table->string('seo_title_en', 255)->nullable();
            $table->string('seo_description_bn', 500)->nullable();
            $table->string('seo_description_en', 500)->nullable();
            $table->string('status', 20)->default('draft');
            // A published post with a future date stays hidden until then.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name_bn', 120);
            $table->string('name_en', 120);
            $table->string('role_bn', 120);
            $table->string('role_en', 120);
            $table->string('employee_code', 20)->nullable()->unique();
            $table->foreignId('photo_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->text('quote_bn');
            $table->text('quote_en')->nullable();
            $table->string('reviewer_name', 120);
            $table->string('trip_label_bn', 160)->nullable();
            $table->string('trip_label_en', 160)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->date('travelled_on')->nullable();
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('url', 500);
            $table->string('caption_bn', 255)->nullable();
            $table->string('caption_en', 255)->nullable();
            $table->unsignedInteger('view_count')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 160);
            $table->string('phone', 15);
            $table->string('email', 190)->nullable();
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->nullOnDelete();
            $table->unsignedSmallInteger('pax')->nullable();
            // Air quote: from/to, dates, cabin class. Contact: free-text message.
            $table->json('details')->nullable();
            $table->char('locale', 2)->default('bn');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status', 20)->default('new');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->char('locale', 2)->default('bn');
            $table->string('status', 20)->default('subscribed');
            $table->char('unsubscribe_token', 40)->unique();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['newsletter_subscribers', 'inquiries', 'site_settings', 'gallery_items', 'reviews', 'team_members', 'blog_posts', 'blog_categories', 'addons', 'pricing_slabs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
