<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The airlines the agency books, shown as a band of logos above the footer (docs/partners-and-payments.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airline_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name_bn', 120);
            $table->string('name_en', 120);
            // The logo, uploaded to the media library; without one there is nothing to show, so it cannot be published.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('website_url', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airline_partners');
    }
};
