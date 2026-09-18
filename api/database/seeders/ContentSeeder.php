<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\PackageStatus;
use App\Models\Addon;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Destination;
use App\Models\Media;
use App\Models\PackageInclusion;
use App\Models\PricingSlab;
use App\Models\SiteSetting;
use App\Models\Tag;
use App\Models\TeamMember;
use App\Models\TourPackage;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads packages/content-seed: destinations, packages (from the client's WordPress data and the design's
 * bilingual copy), blog, team, pricing and site settings.
 *
 * Safe on a live database: it only creates what is missing and never updates or deletes an existing row,
 * so re-running it cannot overwrite an edit made in the CMS. It never truncates (shared MySQL instance).
 * The team is starter content: once the list has anybody in it, seedTeam leaves it alone, so a member deleted
 * in the CMS does not come back at the next deploy.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $destinations = $this->seedDestinations();
            $this->seedPackages($destinations);
            $this->seedBlog();
            $this->seedTeam();
            $this->seedPricing();
            $this->seedSettings();
        });
    }

    /** @return array<string, int> slug => id */
    private function seedDestinations(): array
    {
        foreach ($this->read('destinations.json') as $index => $row) {
            Destination::query()->firstOrCreate(['slug' => $row['slug']], [
                'name_bn' => $row['name']['bn'],
                'name_en' => $row['name']['en'],
                'country_code' => $row['countryCode'],
                'region' => $row['region'],
                'visa_on_arrival' => (bool) ($row['visaOnArrival'] ?? false),
                'sort_order' => $index + 1,
            ]);
        }

        return Destination::query()->pluck('id', 'slug')->all();
    }

    /** @param array<string, int> $destinations */
    private function seedPackages(array $destinations): void
    {
        foreach ($this->read('packages.json') as $index => $row) {
            if (TourPackage::withTrashed()->where('code', $row['code'])->exists()) {
                continue;
            }

            $destinationId = $destinations[$row['destination']]
                ?? throw new RuntimeException("Package {$row['code']}: unknown destination {$row['destination']}");
            $published = $row['status'] === PackageStatus::Published->value;

            $package = TourPackage::query()->create([
                'code' => $row['code'],
                'wp_trip_id' => $row['wpTripId'],
                'slug' => $row['slug'],
                'destination_id' => $destinationId,
                'title_en' => $row['title']['en'],
                'title_bn' => $row['title']['bn'],
                'summary_en' => $row['summary']['en'],
                'summary_bn' => $row['summary']['bn'],
                'duration_days' => $row['durationDays'],
                'duration_nights' => $row['durationNights'],
                'regular_price' => $row['regularPrice'],
                'sale_price' => $row['salePrice'],
                'includes_airfare' => $row['includesAirfare'],
                'group_mode' => $row['groupMode'],
                'min_pax' => $row['minPax'],
                'departure_mode' => $row['departureMode'],
                'source_image_url' => $row['images'][0]['url'] ?? null,
                'status' => $row['status'],
                'published_at' => $published ? now() : null,
                'sort_order' => $index + 1,
            ]);

            foreach ($row['itinerary'] as $day) {
                $package->itineraryDays()->create([
                    'day_number' => $day['day'],
                    'title_en' => $day['title']['en'] ?: null,
                    'title_bn' => $day['title']['bn'] ?: null,
                    'body_en' => $day['body']['en'],
                    'body_bn' => $day['body']['bn'],
                ]);
            }

            foreach ([PackageInclusion::INCLUDE => $row['includes'], PackageInclusion::EXCLUDE => $row['excludes']] as $kind => $items) {
                foreach ($items as $position => $item) {
                    $package->inclusions()->create(['kind' => $kind, 'text_en' => $item['en'], 'text_bn' => $item['bn'], 'sort_order' => $position + 1]);
                }
            }

            $package->syncTagNames([Tag::ACTIVITY => $row['activities'], Tag::TRIP_TYPE => $row['tripTypes']]);

            foreach ($row['images'] as $position => $image) {
                $media = Media::query()->create([
                    'disk' => 'public',
                    'mime' => 'image/jpeg',
                    'source_url' => $image['url'],
                    'is_placeholder' => $image['isPlaceholder'] ?? false,
                    'alt_bn' => $image['alt']['bn'] ?? null,
                    'alt_en' => $image['alt']['en'] ?? null,
                    'credit' => $image['credit'] ?? null,
                    'credit_url' => $image['creditUrl'] ?? null,
                ]);
                $package->images()->create(['media_id' => $media->id, 'sort_order' => $position + 1, 'is_cover' => $position === 0]);
            }
        }
    }

    private function seedBlog(): void
    {
        $blog = $this->read('posts.json');

        foreach ($blog['categories'] as $index => $row) {
            BlogCategory::query()->firstOrCreate(['slug' => $row['slug']], [
                'name_bn' => $row['name']['bn'],
                'name_en' => $row['name']['en'],
                'tone' => $row['tone'],
                'sort_order' => $index + 1,
            ]);
        }
        $categories = BlogCategory::query()->pluck('id', 'slug');

        foreach ($blog['posts'] as $row) {
            if (BlogPost::withTrashed()->where('slug', $row['slug'])->exists()) {
                continue;
            }

            BlogPost::query()->create([
                'slug' => $row['slug'],
                'blog_category_id' => $categories[$row['category']],
                'title_bn' => $row['title']['bn'],
                'title_en' => $row['title']['en'],
                'excerpt_bn' => $row['excerpt']['bn'],
                'excerpt_en' => $row['excerpt']['en'],
                // Seed HTML goes through the same sanitiser as CMS saves.
                'body_bn' => HtmlSanitizer::clean($row['body']['bn']),
                'body_en' => HtmlSanitizer::clean($row['body']['en']),
                'author_bn' => $row['author']['bn'],
                'author_en' => $row['author']['en'],
                'reading_minutes' => $row['readingMinutes'],
                'reading_minutes_override' => true,
                'status' => $row['status'],
                'published_at' => $row['publishedAt'].' 09:00:00',
            ]);
        }
    }

    private function seedTeam(): void
    {
        // Starter content only. Once the agency has entered its own people, the seed leaves the list alone: otherwise a
        // member deleted in the CMS would walk back in at the next deploy, which is what happened on 2026-09-18.
        if (TeamMember::query()->exists()) {
            return;
        }

        foreach ($this->read('team.json') as $row) {
            TeamMember::query()->firstOrCreate(['employee_code' => $row['employeeCode']], [
                'name_bn' => $row['name']['bn'],
                'name_en' => $row['name']['en'],
                'role_bn' => $row['role']['bn'],
                'role_en' => $row['role']['en'],
                'status' => $row['isVisible'] ? ContentStatus::Published : ContentStatus::Draft,
                'sort_order' => $row['sortOrder'],
            ]);
        }
    }

    private function seedPricing(): void
    {
        $pricing = $this->read('pricing.json');

        if (! PricingSlab::query()->exists()) {
            foreach ($pricing['slabs'] as $slab) {
                PricingSlab::query()->create(['min_pax' => $slab['minPax'], 'discount_percent' => $slab['discountPercent']]);
            }
        }

        foreach ($pricing['addons'] as $index => $row) {
            Addon::query()->firstOrCreate(['code' => $row['code']], [
                'name_bn' => $row['name']['bn'],
                'name_en' => $row['name']['en'],
                'price' => $row['price'],
                'unit' => $row['unit'],
                'sort_order' => $index + 1,
            ]);
        }

        SiteSetting::query()->firstOrCreate(['key' => 'pricing'], ['value' => [
            'singleRoomSupplementPercent' => $pricing['singleRoomSupplementPercent'],
            'serviceChargePercent' => $pricing['serviceChargePercent'],
            'maxTravellers' => $pricing['maxTravellers'],
            'onlinePaymentChargePercent' => $pricing['onlinePaymentChargePercent'] ?? 0,
        ]]);
    }

    private function seedSettings(): void
    {
        foreach ($this->read('settings.json') as $key => $value) {
            SiteSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function read(string $file): array
    {
        $path = rtrim((string) config('bhabaghure.content_seed_path'), '/\\').DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            throw new RuntimeException("Content seed file not found: {$path} (set CONTENT_SEED_PATH)");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
