<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §6: salary from attendance. Base salaries are a history by effective month
 * (append-only). A month's payroll is a draft, figured live from attendance, until it is finalised: then each person's
 * figures, day statuses and the rules used are frozen in payroll_items, and paying one records a company cash-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff');
            $table->date('effective_month');
            $table->decimal('amount', 12, 2);
            $table->string('reason', 300);
            $table->foreignId('created_by_staff_id')->constrained('staff');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_id', 'effective_month']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            // draft · finalised
            $table->string('status', 12)->default('draft');
            $table->json('rules')->nullable();
            $table->foreignId('created_by_staff_id')->constrained('staff');
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('finalised_by_staff_id')->nullable()->constrained('staff');
            $table->timestamps();
        });

        // An allowance (+) or a recovery (−) for one person in one month's run, with a reason.
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('staff_id')->constrained('staff');
            $table->decimal('amount', 12, 2);
            $table->string('reason', 300);
            $table->foreignId('created_by_staff_id')->constrained('staff');
            $table->timestamps();

            $table->index(['payroll_run_id', 'staff_id']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('staff_id')->constrained('staff');
            $table->decimal('base', 12, 2);
            $table->decimal('day_rate', 12, 4);
            $table->json('totals');
            $table->json('days');
            $table->decimal('deductions', 12, 2);
            $table->decimal('adjustments', 12, 2);
            $table->decimal('payable', 12, 2);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_staff_id')->nullable()->constrained('staff');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->timestamps();

            $table->unique(['payroll_run_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('staff_salaries');
    }
};
