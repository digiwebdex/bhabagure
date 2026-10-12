<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The holiday calendar (docs/holiday-calendar.md): a holiday of several days (Eid, Puja) is entered as one date range,
 * stored as one row per day — so attendance and salary keep reading single days — tied together by `group_id`.
 * Holidays entered before this each become a group of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->char('group_id', 36)->nullable()->after('id')->index();
        });

        foreach (DB::table('holidays')->whereNull('group_id')->pluck('id') as $id) {
            DB::table('holidays')->where('id', $id)->update(['group_id' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn('group_id');
        });
    }
};
