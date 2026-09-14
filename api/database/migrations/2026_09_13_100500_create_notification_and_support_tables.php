<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** docs/phase-1-schema.md §3.6–3.7 — outbound message log, document numbering, audit trail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('channel', 10);
            $table->nullableMorphs('recipient');
            $table->string('to_address', 190);
            $table->nullableMorphs('related');
            $table->char('locale', 2)->default('bn');
            $table->string('title', 255)->nullable();
            $table->text('body');
            $table->string('attachment_path', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('provider', 20);
            $table->string('provider_message_id', 100)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('scheduled_for');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('dedupe_key', 190)->unique();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('key', 30)->primary();
            $table->string('prefix', 20);
            $table->unsignedInteger('next_value')->default(1);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('actor');
            $table->string('action', 60);
            $table->nullableMorphs('auditable');
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // Append-only (see LedgerTables): no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('notifications');
    }
};
