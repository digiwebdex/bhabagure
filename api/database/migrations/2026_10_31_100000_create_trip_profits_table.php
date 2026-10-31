<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance → Trip profit (docs/trip-profit.md): each trip's costs, what was collected and the profit, as staff enter
 * them — on its own, linked to no booking, invoice or account. The total cost and profit are worked out by the API
 * on every save and kept, so lists and totals read them as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_profits', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('trip_type', 20)->nullable();
            $table->date('trip_date');
            $table->unsignedSmallInteger('pax');
            $table->decimal('flight_cost', 12, 2)->default(0);
            $table->decimal('land_package_cost', 12, 2)->default(0);
            $table->decimal('other_cost', 12, 2)->default(0);
            $table->decimal('total_cost', 13, 2)->default(0);
            $table->decimal('total_collected_amount', 12, 2)->default(0);
            $table->decimal('profit', 13, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['trip_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_profits');
    }
};
