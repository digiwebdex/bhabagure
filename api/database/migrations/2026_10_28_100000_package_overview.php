<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A package's overview (docs/package-page.md): paragraphs about the tour on its page and in its brochure. The summary
 * stays the one line under the title on the package card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->text('overview_en')->nullable()->after('summary_bn');
            $table->text('overview_bn')->nullable()->after('overview_en');
        });
    }

    public function down(): void
    {
        Schema::table('tour_packages', function (Blueprint $table) {
            $table->dropColumn(['overview_en', 'overview_bn']);
        });
    }
};
