<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-6-customer-portal.md §0.3, §3.5: support tickets a customer opens in the portal, answered from the admin
 * Support queue. Messages are append-only; a ticket's status says whose turn it is (open: ours, answered: theirs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('subject', 160);
            // open (waiting for staff) · answered (waiting for the customer) · closed
            $table->string('status', 20)->default('open');
            $table->timestamp('last_customer_message_at');
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'last_customer_message_at']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            // customer · staff
            $table->string('author', 10);
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
