<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staff software is English only (docs/phase-5-admin-core.md, 2026-09-16). The two sources this app ships with were
 * named in both languages; sources the owner added themselves are left exactly as they typed them.
 */
return new class extends Migration
{
    private const RENAMES = [
        'ব্যক্তিগত · Personal' => 'Personal',
        'অন্যান্য · Other' => 'Other',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::connection('wallet')->table('wallet_sources')->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::connection('wallet')->table('wallet_sources')->where('name', $to)->update(['name' => $from, 'updated_at' => now()]);
        }
    }
};
