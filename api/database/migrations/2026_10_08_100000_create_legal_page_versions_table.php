<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website's booking terms, refund/cancellation policy and privacy policy, edited in the admin (docs/legal-pages.md).
 * Every publish adds a version and nothing is overwritten: a booking records the terms version the customer accepted.
 * No rows at first: the website keeps showing its built-in draft until staff publish a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_page_versions', function (Blueprint $table) {
            $table->id();
            // terms · refund-policy · privacy
            $table->string('slug', 30);
            $table->unsignedInteger('version');
            $table->string('title_bn', 160);
            $table->string('title_en', 160)->nullable();
            // Sanitised HTML from the editor; English empty shows the Bangla on the English site.
            $table->longText('body_bn');
            $table->longText('body_en')->nullable();
            // What changed, for the version list.
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['slug', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_page_versions');
    }
};
