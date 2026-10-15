<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment requests (docs/payment-requests.md): a customer who paid by bank transfer or bKash sends the amount, the date,
 * a reference and a screenshot from the booking page or the portal; staff approve it — the payment is recorded as
 * staff record one, with the screenshot as its receipt — or reject it with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // bank_transfer · bkash
            $table->string('method', 20);
            // Which of the office's accounts it went to, as the How to pay box showed it.
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            // The screenshot, on the private disk; it becomes the payment's receipt when approved.
            $table->string('evidence_path', 200);
            // booking_page · portal
            $table->string('via', 12);
            // pending · approved · rejected
            $table->string('status', 10)->default('pending');
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('decided_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
