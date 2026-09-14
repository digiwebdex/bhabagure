<?php

use App\Support\Database\TableGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-5-admin-core.md §4.5, §5. A quotation freezes its price: the lines and amounts are copied from the pricing
 * service when it is saved and honoured until valid_until (a Dhaka date). Expiry is derived from that date, never stored.
 * A revision after sending is a new quotation pointing back to the original; conversion copies the lines one to one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('revision_of_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->nullOnDelete();
            $table->foreignId('departure_id')->nullable()->constrained('package_departures')->nullOnDelete();

            // Snapshot: a later package edit never changes a quotation already given.
            $table->string('package_title_en', 255);
            $table->string('package_title_bn', 255)->nullable();
            $table->string('package_code', 40)->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->unsignedSmallInteger('duration_nights')->nullable();
            $table->boolean('includes_airfare')->nullable();
            $table->date('travel_date')->nullable();
            $table->unsignedSmallInteger('pax_count');
            $table->string('room_type', 10)->default('twin');

            $table->decimal('list_price', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal_amount', 12, 2);
            $table->decimal('single_supplement_amount', 12, 2)->default(0);
            $table->decimal('addons_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);

            // 3, 7 or 14 days; valid_until is set from it when the quotation is sent (provisionally when drafted).
            $table->unsignedTinyInteger('validity_days')->default(7);
            $table->date('valid_until');
            // draft · sent · accepted · declined · withdrawn · converted (expired is derived from valid_until)
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_booking_id')->nullable()->constrained('bookings')->nullOnDelete();

            $table->char('locale', 2)->default('bn');
            $table->text('notes')->nullable();
            // The PDF link sent to the customer (WhatsApp fetches the document from it).
            $table->string('share_token', 40)->unique();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'valid_until']);
            $table->index(['assigned_staff_id', 'status']);
        });

        TableGuards::check('quotations', 'quotations_amounts_non_negative',
            '`unit_price` >= 0 AND `subtotal_amount` >= 0 AND `discount_amount` >= 0 AND `vat_amount` >= 0 AND `total_amount` >= 0');
        TableGuards::check('quotations', 'quotations_pax_positive', '`pax_count` > 0');
        TableGuards::check('quotations', 'quotations_total_adds_up',
            '`total_amount` = `subtotal_amount` + `single_supplement_amount` + `addons_amount` - `discount_amount` + `vat_amount`');

        // Same shape as booking_lines, so converting copies rows one to one.
        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('code', 40)->nullable();
            $table->string('title_bn', 255)->nullable();
            $table->string('title_en', 255);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // The reverse link; unique, so a quotation converts into one booking at most.
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->unique()->after('departure_id')->constrained('quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quotation_id');
        });
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
