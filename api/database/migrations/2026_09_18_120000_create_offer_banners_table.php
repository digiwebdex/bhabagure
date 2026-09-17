<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The offer banners that slide under the hero video on the home page (docs/offer-banners.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_banners', function (Blueprint $table) {
            $table->id();
            // What the banner says, for anyone who cannot see it and for the admin list.
            $table->string('title_bn', 160);
            $table->string('title_en', 160);
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            // Where the banner leads: a package on this site, an offer page, WhatsApp — or nowhere.
            $table->string('link_url', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_banners');
    }
};
