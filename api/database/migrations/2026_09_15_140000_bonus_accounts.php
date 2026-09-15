<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §7: a bonus account per staff member, its append-only ledger (a mistake is
 * a reversing entry), and withdrawals — requested, approved or rejected, paid — with every step in an append-only log.
 * Paying one records a company cash-out under a new Staff bonuses expense account.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->insertOrIgnore([
            'code' => '5240', 'name_en' => 'Staff bonuses and commission', 'name_bn' => 'স্টাফ বোনাস ও কমিশন', 'type' => 'expense',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('bonus_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff');
            $table->timestamps();
        });

        Schema::create('bonus_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_account_id')->constrained('bonus_accounts');
            $table->foreignId('staff_id')->constrained('staff');
            $table->decimal('amount', 12, 2);
            $table->string('note', 300)->nullable();
            // pending · approved · rejected · cancelled · paid
            $table->string('status', 12)->default('pending');
            $table->foreignId('decided_by_staff_id')->nullable()->constrained('staff');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_staff_id')->nullable()->constrained('staff');
            // The company cash-out that paid it; cleared again if that cash-out is reversed.
            $table->foreignId('cash_transaction_id')->nullable()->constrained('transactions');
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['staff_id', 'id']);
        });

        Schema::create('bonus_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_account_id')->constrained('bonus_accounts');
            // credit · debit
            $table->string('direction', 6);
            $table->decimal('amount', 12, 2);
            // commission · manual · withdrawal · reversal
            $table->string('kind', 12);
            $table->foreignId('booking_id')->nullable()->constrained('bookings');
            $table->foreignId('bonus_withdrawal_id')->nullable()->constrained('bonus_withdrawals');
            // The commission rule as it stood when the entry was made.
            $table->json('rule')->nullable();
            // Each entry is reversed at most once.
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('bonus_transactions');
            $table->string('reason', 300)->nullable();
            // Null: written by the system.
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bonus_account_id', 'id']);
        });

        Schema::create('bonus_withdrawal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_withdrawal_id')->constrained('bonus_withdrawals');
            // requested · cancelled · approved · rejected · paid · payment_reversed
            $table->string('action', 20);
            $table->foreignId('actor_staff_id')->nullable()->constrained('staff');
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bonus_withdrawal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_withdrawal_events');
        Schema::dropIfExists('bonus_transactions');
        Schema::dropIfExists('bonus_withdrawals');
        Schema::dropIfExists('bonus_accounts');
        DB::table('accounts')->where('code', '5240')->delete();
    }
};
