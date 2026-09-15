<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Models\GalleryItem;
use App\Models\PackageDeparture;
use App\Models\Review;
use App\Models\TourPackage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Illustrative departures, reviews and gallery tiles (packages/content-seed/demo) so those website sections
 * can be checked locally. The reviews are not real customers — this never runs outside APP_ENV=local.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoContentSeeder only runs with APP_ENV=local: its reviews are illustrative.');
        }

        $path = rtrim((string) config('bhabaghure.content_seed_path'), '/\\').'/demo/';
        $read = fn (string $file) => json_decode((string) file_get_contents($path.$file), true, flags: JSON_THROW_ON_ERROR);

        if (! PackageDeparture::query()->exists()) {
            foreach ($read('departures.json') as $index => $row) {
                $package = TourPackage::query()->where('code', $row['packageCode'])->first();
                if ($package === null) {
                    continue;
                }
                // The demo file has no real dates; space departures a few weeks apart.
                $departsOn = now()->addWeeks(3 + $index * 2)->startOfDay();
                PackageDeparture::query()->create([
                    'tour_package_id' => $package->id,
                    'departs_on' => $departsOn,
                    'returns_on' => $departsOn->copy()->addDays($package->duration_days - 1),
                    'seats_total' => $row['seatsTotal'],
                    'is_guaranteed' => $row['isGuaranteed'],
                ]);
            }
        }

        if (! Review::query()->exists()) {
            foreach ($read('reviews.json') as $index => $row) {
                Review::query()->create([
                    'quote_bn' => $row['quote']['bn'],
                    'quote_en' => $row['quote']['en'],
                    'reviewer_name' => $row['reviewerName'],
                    'trip_label_bn' => $row['tripLabel']['bn'],
                    'trip_label_en' => $row['tripLabel']['en'],
                    'rating' => $row['rating'],
                    'status' => ContentStatus::Published,
                    'sort_order' => $index + 1,
                ]);
            }
        }

        // The real reels are already published by a migration; this adds the demo's photo tiles beside them.
        if (! GalleryItem::query()->where('kind', 'photo')->exists()) {
            foreach ($read('gallery.json') as $index => $row) {
                GalleryItem::query()->firstOrCreate(['url' => $row['url']], [
                    'kind' => $row['kind'],
                    'caption_bn' => $row['caption']['bn'] ?? null,
                    'caption_en' => $row['caption']['en'] ?? null,
                    'view_count' => $row['viewsThousands'] === null ? null : $row['viewsThousands'] * 1000,
                    'status' => ContentStatus::Published,
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }
}
