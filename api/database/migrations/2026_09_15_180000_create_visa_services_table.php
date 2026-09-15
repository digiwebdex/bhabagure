<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visa services the agency processes (docs/phase-8-visa-quotes-pricing-downloads.md §4.C): one row per country and visa
 * type, edited on Admin → Visa services and shown in the home page's Visa section, on /visa/<slug> and in the search
 * panel's Visa tab. Requirements are one per line, in both languages; the requirements PDF (step E) is made from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visa_services', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->char('country_code', 2)->nullable();
            $table->string('country_bn', 80);
            $table->string('country_en', 80);
            $table->string('visa_type_bn', 80);
            $table->string('visa_type_en', 80);
            // Per person, in taka, including the agency's service charge. Null: priced on request.
            $table->decimal('price', 12, 2)->nullable();
            $table->string('processing_bn', 120)->nullable();
            $table->string('processing_en', 120)->nullable();
            $table->string('stay_bn', 160)->nullable();
            $table->string('stay_en', 160)->nullable();
            $table->text('requirements_bn')->nullable();
            $table->text('requirements_en')->nullable();
            $table->text('notes_bn')->nullable();
            $table->text('notes_en')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visa_services');
    }
};
