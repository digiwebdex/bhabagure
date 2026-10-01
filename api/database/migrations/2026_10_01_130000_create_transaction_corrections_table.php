<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit and Delete on the cash book (client, 2026-10-01; docs/transaction-edits.md). The books stay append-only: an edit
 * cancels the entry with a reversing one and records the corrected entry; a delete only cancels it. This log ties the
 * three together, says who did it, why, and what changed, and is itself append-only (LedgerTables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // edit · delete
            $table->foreignId('original_transaction_id')->unique()->constrained('transactions');
            $table->foreignId('reversal_transaction_id')->unique()->constrained('transactions');
            // The corrected entry an edit recorded; null for a delete.
            $table->foreignId('replacement_transaction_id')->nullable()->unique()->constrained('transactions');
            // For an edit: { field: [before, after] }, only the fields that changed.
            $table->json('changes')->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignId('staff_id')->constrained('staff');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_corrections');
    }
};
