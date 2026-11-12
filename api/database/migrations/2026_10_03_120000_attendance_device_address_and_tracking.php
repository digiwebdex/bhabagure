<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance (client, 2026-10-03; docs/phase-7-hr-attendance-bonus-wallet.md §5):
 * - the fingerprint device's network address and port are kept here, set on the device card, and handed to the office
 *   agent at its check-in, so a change reaches the agent within a minute. Private IPv4 addresses only.
 * - `first_check_in_at`: when the agent first checked in. An agent that checks in but never reads the device alerts an
 *   hour after this, like a device that stopped answering.
 * - `uses_attendance_device` (default on): off leaves a staff member out of attendance; they stay on the payroll, with
 *   no attendance cuts. Someone without a profile row counts as on.
 *
 * Columns only, with defaults: every existing device and staff member keeps working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->string('device_address', 45)->nullable()->after('name');
            $table->unsignedSmallInteger('device_port')->default(4370)->after('device_address');
            $table->timestamp('first_check_in_at')->nullable()->after('agent_version');
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->boolean('uses_attendance_device')->default(true)->after('left_on');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', fn (Blueprint $table) => $table->dropColumn('uses_attendance_device'));
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropColumn(['device_address', 'device_port', 'first_check_in_at']);
        });
    }
};
