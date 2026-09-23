<?php

use App\Support\Database\TableGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (docs/coupons.md §2.1): the coupons, the packages a coupon is limited to, every use of one, and the coupon's
 * share of a booking's and an invoice's discount. `discount_amount` stays the whole discount everywhere, so the booking
 * total's CHECK and everything that reads it are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            // In capitals. Unique including archived coupons, so an old code never points at a new offer.
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            // public: a campaign code; passport: one passport holder's.
            $table->string('kind', 10);
            // Where the campaign runs (website, facebook, sms, email, seasonal, other), for the report.
            $table->string('channel', 20)->nullable();
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->decimal('min_booking_amount', 12, 2)->nullable();
            // DATETIME, not TIMESTAMP: an admin may set a window past 2038 (UTC, like every time the API stores).
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            // all: every booking; packages: only those in coupon_packages (none left = no booking).
            $table->string('applies_to', 10)->default('all');
            // A passport coupon's passport: encrypted like booking_travellers.passport_number, matched by the same HMAC.
            $table->text('passport_number')->nullable();
            $table->char('passport_number_hash', 64)->nullable()->index();
            $table->string('holder_name', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            // Archived: kept for the bookings and the report that name it.
            $table->softDeletes();

            $table->index(['is_active', 'ends_at']);
        });

        TableGuards::check('coupons', 'coupons_kind', "`kind` IN ('public', 'passport') AND `applies_to` IN ('all', 'packages')");
        TableGuards::check('coupons', 'coupons_discount',
            "(`discount_type` = 'percent' AND `discount_value` > 0 AND `discount_value` <= 100) OR (`discount_type` = 'fixed' AND `discount_value` > 0)");
        TableGuards::check('coupons', 'coupons_amounts',
            '(`max_discount_amount` IS NULL OR `max_discount_amount` > 0) AND (`min_booking_amount` IS NULL OR `min_booking_amount` >= 0)');
        TableGuards::check('coupons', 'coupons_window', '`starts_at` IS NULL OR `ends_at` IS NULL OR `ends_at` > `starts_at`');
        TableGuards::check('coupons', 'coupons_limits', '(`usage_limit` IS NULL OR `usage_limit` > 0) AND (`per_customer_limit` IS NULL OR `per_customer_limit` > 0)');
        // A passport coupon has its passport; a public one has none.
        TableGuards::check('coupons', 'coupons_passport', "(`kind` = 'passport') = (`passport_number_hash` IS NOT NULL)");

        Schema::create('coupon_packages', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('tour_package_id')->constrained('tour_packages')->cascadeOnDelete();
            $table->primary(['coupon_id', 'tour_package_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // The terms as they were when applied: a booking's discount never depends on what the coupon says later.
            $table->string('code', 30);
            $table->string('kind', 10);
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->decimal('min_booking_amount', 12, 2)->nullable();
            // The traveller's passport that satisfied a passport coupon.
            $table->text('passport_number')->nullable();
            $table->char('passport_number_hash', 64)->nullable()->index();
            // What the coupon worked on (every line before any discount and the service charge), what it took off, and the
            // booking's total without and with it. Kept in step with the booking while its quote can still change.
            $table->decimal('eligible_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('original_total', 12, 2);
            $table->decimal('final_total', 12, 2);
            // reserved (the booking holds it) → used (the booking is confirmed); released gives the use back.
            $table->string('status', 10)->default('reserved');
            // website (the customer) or office (staff applied it).
            $table->string('source', 10);
            $table->foreignId('applied_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('applied_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 40)->nullable();
            $table->foreignId('released_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            // The booking only while the use is live: unique, so a booking carries one coupon at a time.
            $table->unsignedBigInteger('live_booking_id')->nullable()->storedAs("IF(`status` IN ('reserved', 'used'), `booking_id`, NULL)");

            $table->unique('live_booking_id');
            $table->index(['coupon_id', 'status']);
            $table->index(['customer_id', 'coupon_id']);
            $table->index('applied_at');
        });

        TableGuards::check('coupon_redemptions', 'coupon_redemptions_status', "`status` IN ('reserved', 'used', 'released') AND `source` IN ('website', 'office')");
        TableGuards::check('coupon_redemptions', 'coupon_redemptions_amounts',
            '`eligible_amount` >= 0 AND `discount_amount` >= 0 AND `discount_amount` <= `eligible_amount` AND `final_total` >= 0 AND `original_total` >= `final_total`');

        Schema::table('bookings', function (Blueprint $table) {
            // The coupon's share of discount_amount; the rest is the staff's own discount.
            $table->decimal('coupon_discount_amount', 12, 2)->default(0)->after('discount_amount');
        });
        TableGuards::check('bookings', 'bookings_coupon_within_discount', '`coupon_discount_amount` >= 0 AND `coupon_discount_amount` <= `discount_amount`');

        Schema::table('invoices', function (Blueprint $table) {
            // Part of the frozen snapshot (Invoice::SNAPSHOT_COLUMNS): printed as its own line.
            $table->string('coupon_code', 30)->nullable()->after('discount_amount');
            $table->decimal('coupon_discount_amount', 12, 2)->default(0)->after('coupon_code');
        });
        TableGuards::check('invoices', 'invoices_coupon_within_discount', '`coupon_discount_amount` >= 0 AND `coupon_discount_amount` <= `discount_amount`');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `invoices` DROP CHECK `invoices_coupon_within_discount`');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount_amount']);
        });
        DB::statement('ALTER TABLE `bookings` DROP CHECK `bookings_coupon_within_discount`');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('coupon_discount_amount');
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_packages');
        Schema::dropIfExists('coupons');
    }
};
