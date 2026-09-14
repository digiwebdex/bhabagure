<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh-token rotation with a short grace window (App\Services\Auth\RefreshTokens): a token rotated a moment ago and
 * presented again by the same browser gets the same successor instead of ending the session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_refresh_tokens', function (Blueprint $table) {
            // Set when this token was exchanged for its successor (revoked_at alone also covers sign-out).
            $table->timestamp('rotated_at')->nullable()->after('revoked_at');
            $table->foreignId('replaced_by_id')->nullable()->after('rotated_at')->constrained('auth_refresh_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auth_refresh_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropColumn('rotated_at');
        });
    }
};
