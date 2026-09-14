<?php

use App\Support\Database\TableGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** docs/phase-1-schema.md §3.4 — bookings and the travellers on them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->restrictOnDelete();
            $table->foreignId('departure_id')->nullable()->constrained('package_departures')->restrictOnDelete();
            // Snapshot at booking time: a later package edit never changes an existing booking.
            $table->string('package_title_en', 255);
            $table->string('package_title_bn', 255)->nullable();
            $table->date('travel_start')->nullable();
            $table->date('travel_end')->nullable();
            $table->unsignedSmallInteger('pax_count');

            $table->string('room_type', 10)->default('twin');

            // Money: DECIMAL(12,2), exact. Never FLOAT. The full quote is a snapshot from the shared pricing
            // service (App\Support\Pricing); the priced lines are in booking_lines.
            $table->decimal('list_price', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal_amount', 12, 2);
            $table->decimal('single_supplement_amount', 12, 2)->default(0);
            $table->decimal('addons_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            // VAT / service charge on (lines − discount).
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            // Written only by LedgerService, in the same DB transaction as the ledger row.
            $table->decimal('paid_amount', 12, 2)->default(0);
            // Stored generated column: it cannot drift from total and paid, because nothing can write it.
            $table->decimal('due_amount', 12, 2)->storedAs('`total_amount` - `paid_amount`');
            $table->string('payment_status', 20)->default('unpaid');

            $table->string('status', 20)->default('inquiry');
            $table->string('source', 20);
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->text('internal_notes')->nullable();
            $table->char('locale', 2)->default('bn');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            // Guest bookings: SHA-256 of the private link token (the token itself is shown once).
            $table->char('access_token_hash', 64)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'travel_start']);
            $table->index(['assigned_staff_id', 'status']);
            $table->index('payment_status');
        });

        TableGuards::check('bookings', 'bookings_amounts_non_negative',
            '`unit_price` >= 0 AND `subtotal_amount` >= 0 AND `discount_amount` >= 0 AND `vat_amount` >= 0 AND `total_amount` >= 0 AND `paid_amount` >= 0');
        TableGuards::check('bookings', 'bookings_pax_positive', '`pax_count` > 0');
        // The total is always exactly its parts; no screen or bug can store a total that doesn't add up.
        TableGuards::check('bookings', 'bookings_total_adds_up',
            '`total_amount` = `subtotal_amount` + `single_supplement_amount` + `addons_amount` - `discount_amount` + `vat_amount`');

        Schema::create('booking_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
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

        Schema::create('booking_travellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->string('full_name', 160);
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 2)->default('BD');
            $table->text('passport_number')->nullable();
            $table->char('passport_number_hash', 64)->nullable()->index();
            $table->date('passport_expiry')->nullable();
            $table->string('passport_scan_path', 255)->nullable();
            $table->timestamp('ocr_filled_at')->nullable();
            $table->string('phone', 15)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 15)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_travellers');
        Schema::dropIfExists('booking_lines');
        Schema::dropIfExists('bookings');
    }
};
