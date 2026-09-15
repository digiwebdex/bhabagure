<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every package brochure and visa requirements PDF a signed-in customer downloads (docs/phase-8-visa-quotes-pricing-downloads.md
 * §4.E), for the Downloads screen and the customer's profile: who, what, in which hotel category and group size, and when.
 * The title is kept as downloaded, so the log still reads after a package or visa is renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('tour_package_id')->nullable()->constrained('tour_packages')->nullOnDelete();
            $table->foreignId('visa_service_id')->nullable()->constrained('visa_services')->nullOnDelete();
            $table->string('title', 255);
            $table->char('hotel_category', 1)->nullable();
            $table->unsignedTinyInteger('pax')->nullable();
            $table->char('locale', 2);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
