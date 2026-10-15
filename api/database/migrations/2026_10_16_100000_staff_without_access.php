<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff without software access (docs/staff-without-access.md): a peon, an office assistant or a video editor on the staff
 * list, the attendance and the salary sheet, who never signs in — so no email is needed. And each person's salary basis:
 * worked out from attendance, as before, or a fixed monthly amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->change();
            $table->boolean('software_access')->default(true)->after('status');
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            // attendance · fixed
            $table->string('salary_basis', 12)->default('attendance')->after('payout_account');
        });

        // A day's status set by hand (present, absent, paid or unpaid leave): `leave_unpaid` needs a wider column. Only
        // the column changes; no row does (the table stays append-only).
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->string('kind', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn('salary_basis');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('software_access');
        });
    }
};
