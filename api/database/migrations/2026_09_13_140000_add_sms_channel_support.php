<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-4-whatsapp.md §10 — SMS (bulksmsbd.net) as a fallback channel: one logical message's WhatsApp, email and
 * SMS rows share a group key; an SMS sent because WhatsApp couldn't deliver points at that WhatsApp row; each SMS keeps
 * its part count and estimated cost. Invoices get a short code so an SMS can carry a link without doubling its parts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Shared by the channels of one message (e.g. the booking confirmation's WhatsApp, email and SMS).
            $table->string('group_key', 160)->nullable()->after('dedupe_key');
            // The WhatsApp message this SMS stands in for.
            $table->foreignId('fallback_of_id')->nullable()->after('group_key')->constrained('notifications')->nullOnDelete();
            $table->unsignedTinyInteger('sms_parts')->nullable()->after('attempts');
            $table->string('sms_encoding', 5)->nullable()->after('sms_parts');
            // Estimated cost in BDT when sent (SMS: parts × the configured rate; WhatsApp and email: 0).
            $table->decimal('cost', 10, 2)->nullable()->after('sms_encoding');

            $table->index('group_key');
            $table->index(['channel', 'sent_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Short link for SMS (APP_URL/i/{code}); the 40-character share token stays the real key.
            $table->char('short_code', 10)->nullable()->unique()->after('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['short_code']);
            $table->dropColumn('short_code');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['channel', 'sent_at']);
            $table->dropIndex(['group_key']);
            $table->dropConstrainedForeignId('fallback_of_id');
            $table->dropColumn(['group_key', 'sms_parts', 'sms_encoding', 'cost']);
        });
    }
};
