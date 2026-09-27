<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin inbox (docs/admin-inbox.md): customers' WhatsApp chats on the main number (WaSender) and the Facebook Page's
 * Messenger chats, read and answered by staff. One conversation per customer per channel; attachments are kept
 * encrypted on the private disk, like traveller documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            // WhatsApp: the number as digits (8801711000001), or WhatsApp's own id when it hides the number. Messenger:
            // the customer's page-scoped id.
            $table->string('external_id', 64);
            $table->string('name', 120)->nullable();
            $table->string('phone', 20)->nullable();
            // WhatsApp: the chat's address as WaSender last gave it (…@s.whatsapp.net or …@lid), and the privacy id
            // (…@lid) WhatsApp may use instead of the number, so both land in one conversation.
            $table->string('jid', 100)->nullable();
            $table->string('lid', 100)->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('status', 10)->default('open');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_direction', 3)->nullable();
            // Messenger lets a page answer only within 24 hours of the customer's last message.
            $table->timestamp('last_incoming_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index(['status', 'last_message_at']);
            $table->index('unread_count');
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            // in: the customer. out: staff here, the phone or the Page inbox (`phone`), or an automated message.
            $table->string('origin', 10);
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_kind', 12)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->string('attachment_name', 180)->nullable();
            $table->unsignedInteger('attachment_bytes')->nullable();
            // Media not fetched yet: the provider's own reference to it, until FetchInboxMedia stores the file.
            $table->json('attachment_source')->nullable();
            $table->string('provider_message_id', 100)->nullable();
            $table->string('external_message_id', 190)->nullable();
            $table->string('status', 12)->default('received');
            $table->string('error', 250)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index('external_message_id');
            $table->index('provider_message_id');
        });

        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->text('body');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Starters staff edit in Inbox → Canned replies; nothing here states a price, date or policy.
        $now = now();
        DB::table('canned_replies')->insert([
            ['title' => 'Greeting', 'body' => 'আসসালামু আলাইকুম! ভবঘুরে হলিডেজে যোগাযোগের জন্য ধন্যবাদ। কীভাবে সাহায্য করতে পারি?', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Travellers and date', 'body' => 'কতজন যাবেন এবং কোন তারিখে যেতে চান জানালে আমরা সঠিক মূল্য জানিয়ে দেব।', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'We will call', 'body' => 'ধন্যবাদ! আমাদের একজন প্রতিনিধি শিগগিরই আপনাকে কল করবেন।', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
