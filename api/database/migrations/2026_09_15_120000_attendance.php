<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §5: biometric attendance. Punches arrive from the office agent and are kept
 * exactly as the device reported them (office time, Asia/Dhaka); a replay inserts nothing, because a punch is unique on
 * device, device user and time. Punches, the sync log, corrections and leave events are append-only (LedgerTables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // SHA-256 of the agent's token; the token itself is shown once and never stored.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('token_rotated_at')->nullable();
            // Bound by the first report; a different serial is refused until an admin confirms a replacement.
            $table->string('serial_number', 40)->nullable();
            $table->timestamp('replacement_allowed_at')->nullable();
            $table->string('model', 40)->nullable();
            $table->string('firmware', 60)->nullable();
            $table->string('reported_address', 60)->nullable();
            $table->string('agent_version', 20)->nullable();
            $table->timestamp('last_check_in_at')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->timestamp('last_pull_ok_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->string('last_error', 300)->nullable();
            // Device clock minus the office PC's clock, in seconds, at the last report.
            $table->integer('clock_offset_seconds')->nullable();
            $table->unsignedInteger('users_count')->nullable();
            $table->unsignedInteger('fingers_count')->nullable();
            $table->unsignedInteger('records_count')->nullable();
            // A command waiting for the agent's next check-in: pull · test · set_clock.
            $table->string('pending_command', 12)->nullable();
            $table->timestamp('command_requested_at')->nullable();
            $table->timestamp('command_sent_at')->nullable();
            $table->foreignId('command_requested_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('offline_alerted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        // The device's own user list (IDs and names only), each matched to a staff member or ignored.
        Schema::create('attendance_device_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices');
            $table->string('device_user_id', 20);
            $table->string('name_on_device', 60)->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('staff');
            $table->timestamp('ignored_at')->nullable();
            $table->foreignId('mapped_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->timestamp('last_listed_at')->nullable();
            $table->timestamps();

            // Named: the default name passes MySQL's 64-character limit.
            $table->unique(['attendance_device_id', 'device_user_id'], 'attendance_device_users_unique');
            $table->index('staff_id');
        });

        Schema::create('attendance_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices');
            // report · punches · command · token
            $table->string('kind', 12);
            $table->string('status', 20)->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('stored')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->json('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attendance_device_id', 'id']);
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices');
            $table->string('device_user_id', 20);
            // The device's clock, as reported: office time.
            $table->dateTime('punched_at');
            $table->date('work_date');
            $table->unsignedSmallInteger('verify_type')->nullable();
            $table->unsignedSmallInteger('punch_state')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['attendance_device_id', 'device_user_id', 'punched_at'], 'attendance_punches_unique');
            $table->index(['work_date', 'device_user_id']);
        });

        // A staff day set by hand, with a reason: an in or out time, or "worked" (official duty). Undone by a reversal.
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff');
            $table->date('work_date');
            // in · out · worked · reversal
            $table->string('kind', 10);
            $table->time('time')->nullable();
            $table->string('reason', 300);
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('attendance_corrections');
            $table->foreignId('created_by_staff_id')->constrained('staff');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_id', 'work_date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500);
            // pending · approved · rejected · cancelled · revoked
            $table->string('status', 12)->default('pending');
            $table->boolean('paid')->default(true);
            $table->foreignId('filed_by_staff_id')->constrained('staff');
            $table->foreignId('decided_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_on']);
            $table->index(['staff_id', 'starts_on']);
        });

        Schema::create('leave_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests');
            // filed · approved · rejected · cancelled · revoked
            $table->string('action', 12);
            $table->foreignId('actor_staff_id')->nullable()->constrained('staff');
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The attendance and salary rules (§6), as versions by the month they take effect: earlier months keep theirs.
        Schema::create('attendance_rules', function (Blueprint $table) {
            $table->id();
            $table->date('effective_month');
            $table->time('duty_start');
            $table->time('duty_end');
            $table->unsignedSmallInteger('grace_minutes');
            $table->unsignedTinyInteger('late_early_pay_percent');
            $table->unsignedTinyInteger('working_days_per_month');
            // ISO weekdays, 1 = Monday … 7 = Sunday.
            $table->json('weekly_off_days');
            // late_early · absent · full
            $table->string('single_punch_counts_as', 12);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->unique('effective_month');
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name_en', 120);
            $table->string('name_bn', 120);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        // The design's Attendance screen defaults, in force from the start until someone changes them.
        DB::table('attendance_rules')->insert([
            'effective_month' => '2000-01-01', 'duty_start' => '11:00:00', 'duty_end' => '19:00:00', 'grace_minutes' => 0,
            'late_early_pay_percent' => 50, 'working_days_per_month' => 26, 'weekly_off_days' => json_encode([5]),
            'single_punch_counts_as' => 'late_early', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendance_rules');
        Schema::dropIfExists('leave_request_events');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_sync_events');
        Schema::dropIfExists('attendance_device_users');
        Schema::dropIfExists('attendance_devices');
    }
};
