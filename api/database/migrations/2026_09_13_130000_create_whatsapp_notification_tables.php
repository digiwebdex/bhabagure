<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-4-whatsapp.md §4 — editable message templates, customer WhatsApp opt-out, staff WhatsApp numbers (verified
 * before they can receive alerts), and delivery details on the notifications log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('channel', 10);
            // Email only: the subject line. WhatsApp messages have none.
            $table->string('subject_bn', 190)->nullable();
            $table->string('subject_en', 190)->nullable();
            // Plain text with {{variables}}. The sender line "ভবঘুরে হলিডেজ · Bhabaghure Holidays" is added when sending,
            // so a template can't leave it out.
            $table->text('body_bn');
            $table->text('body_en');
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event', 'channel']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            // WaSender returns its own integer id on send; delivery webhooks carry WhatsApp's message id.
            $table->string('provider_whatsapp_id', 100)->nullable()->after('provider_message_id');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->string('skipped_reason', 60)->nullable()->after('last_error');
            $table->foreignId('triggered_by_staff_id')->nullable()->after('skipped_reason')->constrained('staff')->nullOnDelete();

            $table->index('provider_whatsapp_id');
            $table->index('provider_message_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            // A STOP reply (or staff, on the customer's request) turns automated WhatsApp messages off. Email continues.
            $table->timestamp('whatsapp_opted_out_at')->nullable()->after('email_verified_at');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->string('whatsapp_number', 15)->nullable()->after('phone');
            $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp_number');
            $table->char('whatsapp_code_hash', 64)->nullable()->after('whatsapp_verified_at');
            $table->timestamp('whatsapp_code_expires_at')->nullable()->after('whatsapp_code_hash');
            $table->unsignedTinyInteger('whatsapp_code_attempts')->default(0)->after('whatsapp_code_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'whatsapp_verified_at', 'whatsapp_code_hash', 'whatsapp_code_expires_at', 'whatsapp_code_attempts']);
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('whatsapp_opted_out_at'));
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['provider_whatsapp_id']);
            $table->dropIndex(['provider_message_id']);
            $table->dropConstrainedForeignId('triggered_by_staff_id');
            $table->dropColumn(['provider_whatsapp_id', 'read_at', 'skipped_reason']);
        });
        Schema::dropIfExists('notification_templates');
    }
};
