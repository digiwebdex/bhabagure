<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The four most-watched reels on the company's Facebook page, shown near the bottom of the design's home page, were only
 * in the local demo seed, so the live site had no gallery (the client, 2026-09-15). They are published once here; they
 * play in Facebook's embedded player, so they need no thumbnail. A migration rather than the content seeder: the seeder
 * runs on every deploy and would put back a reel staff had deleted in Admin → Gallery. Recorded in the audit log.
 */
return new class extends Migration
{
    private const REELS = [
        ['https://www.facebook.com/reel/1097420422945413', 104000],
        ['https://www.facebook.com/reel/1576018203982273', 58000],
        ['https://www.facebook.com/reel/1249439448252776', 17000],
        ['https://www.facebook.com/reel/1070950702232596', 11000],
    ];

    public function up(): void
    {
        if (DB::table('gallery_items')->where('kind', 'reel')->exists()) {
            return;
        }

        $offset = (int) DB::table('gallery_items')->max('sort_order');
        foreach (self::REELS as $index => [$url, $views]) {
            $id = DB::table('gallery_items')->insertGetId([
                'kind' => 'reel',
                'url' => $url,
                'view_count' => $views,
                'status' => 'published',
                'sort_order' => $offset + $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'action' => 'cms.gallery_item.created',
                'auditable_type' => 'gallery_item',
                'auditable_id' => $id,
                'changes' => json_encode(['status' => 'published', 'reason' => 'The Facebook page reels from the design (client request, 2026-09-15)']),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Content, not a schema change: removed in Admin → Gallery, never by rolling back.
    }
};
