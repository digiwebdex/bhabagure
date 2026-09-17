<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The YouTube videos staff choose to show under the travel host's cards on the home page (docs/travel-host.md). The
 * host's own profile — name, links, counts, photos — is the `creator` site setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_videos', function (Blueprint $table) {
            $table->id();
            // The 11-character id every YouTube link carries: the watch link and the thumbnail are built from it.
            $table->string('youtube_id', 11)->unique();
            $table->string('title_bn', 200)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_videos');
    }
};
