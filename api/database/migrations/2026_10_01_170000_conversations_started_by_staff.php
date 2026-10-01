<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WhatsApp chat staff started from the inbox (client, 2026-10-01; docs/admin-inbox.md §8): who started it. It also
 * counts the day's new chats against the limit that keeps the main number clear of WhatsApp's spam rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('started_by_staff_id')->nullable()->after('assigned_staff_id')->constrained('staff')->nullOnDelete();
            $table->index(['started_by_staff_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['started_by_staff_id', 'created_at']);
            $table->dropConstrainedForeignId('started_by_staff_id');
        });
    }
};
