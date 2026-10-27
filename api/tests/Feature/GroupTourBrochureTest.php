<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\PackageDeparture;
use App\Models\PackageImage;
use App\Models\TourPackage;
use App\Models\TourPhoto;
use App\Services\Brochures\BrochurePdf;
use App\Services\Brochures\GroupTourPhotos;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A group tour's photo brochure (docs/group-tour-brochures.md). */
class GroupTourBrochureTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private TourPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(ContentSeeder::class);
        $this->package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $this->package->update(['trip_type' => TourPackage::GROUP_FIXED]);
        PackageDeparture::query()->create([
            'tour_package_id' => $this->package->id, 'departs_on' => now('Asia/Dhaka')->addDays(30)->toDateString(),
            'returns_on' => now('Asia/Dhaka')->addDays(36)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled',
        ]);
    }

    #[Test]
    public function every_published_gallery_photo_is_printed_once_in_an_order_that_never_changes(): void
    {
        $cover = $this->media('cover', 1600, 900);
        PackageImage::query()->where('tour_package_id', $this->package->id)->update(['is_cover' => false]);
        PackageImage::query()->create(['tour_package_id' => $this->package->id, 'media_id' => $cover->id, 'is_cover' => true, 'sort_order' => 0]);
        $sizes = [[1280, 960], [959, 1280], [900, 1600], [1600, 720], [2048, 1536], [1440, 1034], [1226, 880], [1600, 1200], [1280, 960], [1440, 1080]];
        $published = [];
        foreach ($sizes as $i => [$width, $height]) {
            $published[] = $this->photo("trip-{$i}", $width, $height, $i === 7 ? $this->package->id : null);
        }
        $draft = $this->photo('draft', 1280, 960, null, 'draft');

        $html = $this->brochure();

        $this->assertSame($html, $this->brochure(), 'The same brochure on every download.');
        // The cover is the banner, at full width: 1600×900 would be 118.1 mm tall at 210 mm, kept to 105 mm.
        $this->assertStringContainsString('<div class="hero" style="height: 105mm">', $html);
        $this->assertSame(1, substr_count($html, $this->src('cover', 'detail')));
        foreach ($published as $photo) {
            $this->assertSame(1, substr_count($html, $this->src($photo->caption_en, 'card')), $photo->caption_en);
            $this->assertStringContainsString($photo->caption_en, $html);
        }
        $this->assertStringNotContainsString($this->src($draft->caption_en, 'card'), $html);
        // The gallery's photo of this tour comes before the rest of the gallery.
        $first = strpos($html, $this->src('trip-7', 'card'));
        foreach ($published as $photo) {
            $this->assertGreaterThanOrEqual($first, strpos($html, $this->src($photo->caption_en, 'card')));
        }
        // Departure and return.
        $this->assertStringContainsString(' – ', $html);
        $this->assertStringContainsString('Day-by-day itinerary', $html);
        $this->assertStringContainsString('Moments from our group tours', $html);
    }

    #[Test]
    public function photos_go_into_the_pdf_as_jpeg_which_chrome_does_not_inflate(): void
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put('media/real-card.webp', (string) ob_get_clean());
        $media = Media::query()->create(['disk' => 'public', 'mime' => 'image/webp', 'width' => 40, 'height' => 30, 'is_placeholder' => false,
            'variants' => ['card' => ['path' => 'media/real-card.webp', 'width' => 40, 'height' => 30]]]);
        TourPhoto::query()->create(['media_id' => $media->id, 'caption_en' => 'Real', 'caption_bn' => 'Real', 'status' => 'published', 'sort_order' => 0]);

        $html = $this->brochure();

        $this->assertStringContainsString('src="data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('data:image/webp', $html);
    }

    #[Test]
    public function without_photos_the_brochure_still_has_its_title_prices_and_dates(): void
    {
        $html = $this->brochure();

        $this->assertStringNotContainsString('class="hero"', $html);
        $this->assertStringNotContainsString('class="row"', $html);
        $this->assertStringContainsString('class="titleblock"', $html);
        $this->assertStringContainsString('Group Tour · Fixed Departure', $html);
        $this->assertStringContainsString('Departure dates', $html);
    }

    #[Test]
    public function rows_keep_each_photos_shape_and_fill_the_pages_width(): void
    {
        $aspects = [4 / 3, 959 / 1280, 900 / 1600, 1600 / 720, 4 / 3, 1440 / 1034, 1226 / 880, 4 / 3, 4 / 3, 4 / 3, 4 / 3];
        $photos = array_map(fn (float $aspect, int $i) => ['src' => "p{$i}", 'aspect' => $aspect, 'caption' => null], $aspects, array_keys($aspects));

        $rows = GroupTourPhotos::rows($photos, 52, 34);

        $this->assertSame(array_column($photos, 'src'), array_merge(...array_map(fn (array $row) => array_column($row['photos'], 'src'), $rows)));
        foreach ($rows as $i => $row) {
            $this->assertLessThanOrEqual(52, $row['heightMm']);
            $width = array_sum(array_column($row['photos'], 'widthMm')) + GroupTourPhotos::GAP_MM * (count($row['photos']) - 1);
            $this->assertLessThanOrEqual(GroupTourPhotos::WIDTH_MM, $width);
            if ($i < count($rows) - 1) {
                $this->assertGreaterThan(GroupTourPhotos::WIDTH_MM - 0.1, $width, 'A full row reaches the page’s edge.');
            }
            foreach ($row['photos'] as $photo) {
                // Its shape: width over height as the photo's own, to a hundredth of a millimetre.
                $this->assertEqualsWithDelta($photo['aspect'] * $row['heightMm'], $photo['widthMm'], 0.01);
            }
        }
        // A short last row joins the one before it rather than standing alone as a tall photo.
        $this->assertCount(1, GroupTourPhotos::rows(array_slice($photos, 0, 3), 70, 0));
    }

    private function brochure(): string
    {
        $package = $this->package->fresh(['destination', 'itineraryDays', 'inclusions']);

        return app(BrochurePdf::class)->packageHtml($package, null, 2, 'en');
    }

    private function photo(string $name, int $width, int $height, ?int $packageId, string $status = 'published'): TourPhoto
    {
        return TourPhoto::query()->create([
            'media_id' => $this->media($name, $width, $height)->id, 'caption_en' => $name, 'caption_bn' => $name,
            'tour_package_id' => $packageId, 'status' => $status, 'sort_order' => 0,
        ]);
    }

    private function media(string $name, int $width, int $height): Media
    {
        $variants = [];
        foreach (['card' => 800, 'detail' => 1600] as $variant => $max) {
            $scale = min(1, $max / $width);
            Storage::disk('public')->put("media/{$name}-{$variant}.webp", "{$name}-{$variant}-bytes");
            $variants[$variant] = ['path' => "media/{$name}-{$variant}.webp", 'width' => (int) round($width * $scale), 'height' => (int) round($height * $scale)];
        }

        return Media::query()->create(['disk' => 'public', 'mime' => 'image/webp', 'width' => $width, 'height' => $height, 'variants' => $variants, 'is_placeholder' => false]);
    }

    private function src(string $name, string $variant): string
    {
        return 'data:image/webp;base64,'.base64_encode("{$name}-{$variant}-bytes");
    }
}
