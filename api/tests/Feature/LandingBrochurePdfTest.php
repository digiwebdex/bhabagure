<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Download;
use App\Models\Media;
use App\Models\PackageDeparture;
use App\Models\PackageImage;
use App\Models\Review;
use App\Models\SiteSetting;
use App\Models\TourLandingPage;
use App\Models\TourPackage;
use App\Models\TourPhoto;
use App\Services\Brochures\PageRenderer;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use App\Support\LandingPreviewToken;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** A group tour's brochure as its landing page printed (docs/tour-landing-pages.md §7). */
class LandingBrochurePdfTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private TourPackage $package;

    private TourLandingPage $page;

    private PackageDeparture $departure;

    /** @var list<array<string, mixed>> what the page renderer was asked for */
    private array $printed = [];

    /** @var list<string> the photo brochure's HTML, when it was made instead */
    private array $fallbacks = [];

    private bool $rendererFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(ContentSeeder::class);
        config(['bhabaghure.brochures.landing_pdf' => true, 'bhabaghure.brochures.website_internal_url' => 'http://127.0.0.1:3340', 'bhabaghure.release' => 'release-1']);

        $this->package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $this->package->update(['trip_type' => TourPackage::GROUP_FIXED, 'status' => 'published']);
        PackageImage::query()->where('tour_package_id', $this->package->id)->delete();
        PackageImage::query()->create(['tour_package_id' => $this->package->id, 'media_id' => $this->media('cover')->id, 'is_cover' => true, 'sort_order' => 0]);
        $this->departure = PackageDeparture::query()->create(['tour_package_id' => $this->package->id, 'departs_on' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled']);
        TourPhoto::query()->create(['media_id' => $this->media('memory')->id, 'caption_en' => 'Mustang 2025', 'caption_bn' => 'মুস্তাং', 'tour_package_id' => $this->package->id, 'status' => 'published', 'sort_order' => 0]);

        $this->page = TourLandingPage::query()->create(['slug' => 'mustang-valley-trip', 'tour_package_id' => $this->package->id]);
        $this->page->forceFill(['status' => 'published'])->save();
        $this->page->dayPhotos()->create(['day_number' => 1, 'media_id' => $this->media('day-1')->id, 'sort_order' => 1]);

        $test = $this;
        $this->app->instance(PageRenderer::class, new class($test) extends PageRenderer
        {
            public function __construct(private readonly LandingBrochurePdfTest $test) {}

            public function render(array $manifest): string
            {
                return $this->test->print($manifest);
            }
        });
        $fallbacks = &$this->fallbacks;
        $this->app->instance(InvoicePdf::class, new class(app(InvoiceView::class), $fallbacks) extends InvoicePdf
        {
            public function __construct(InvoiceView $view, private array &$fallbacks)
            {
                parent::__construct($view);
            }

            public function render(string $html): string
            {
                $this->fallbacks[] = $html;

                return '%PDF photo brochure';
            }
        });
    }

    /** The fake page renderer: notes the manifest, checks every photo is a prepared JPEG file, fails when asked to. */
    public function print(array $manifest): string
    {
        if ($this->rendererFails) {
            throw new RuntimeException('Landing page PDF failed: page answered 502');
        }
        foreach ($manifest['assets'] as $file) {
            $this->assertSame("\xFF\xD8", substr((string) file_get_contents($file), 0, 2), 'A prepared JPEG.');
        }
        $this->printed[] = $manifest;

        return '%PDF landing page';
    }

    #[Test]
    public function a_published_landing_page_is_the_brochure_downloaded_signed_in_and_logged(): void
    {
        $customer = $this->lead();
        $this->getJson('/api/v1/portal/downloads/packages/'.self::MUSTANG)->assertUnauthorized();

        $response = $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?pax=2&locale=en')->assertOk();

        $this->assertSame('%PDF landing page', $response->getContent());
        $this->assertSame(1, Download::query()->where('customer_id', $customer->id)->count());
        $this->assertCount(1, $this->printed);
        $this->assertSame([], $this->fallbacks);
        $manifest = $this->printed[0];
        // Exactly the published page's print view, on the brochure's Dhaka date; no preview token.
        $this->assertSame('http://127.0.0.1:3340/en/tours/mustang-valley-trip/print?asOf='.now('Asia/Dhaka')->toDateString(), $manifest['url']);
        $this->assertSame([], $manifest['headers']);
        $this->assertSame('http://127.0.0.1:3340/_next/static/', $manifest['staticPrefix']);
        // The banner (the cover), the day's photo and the memory, at every address the website may use.
        foreach (['cover', 'day-1', 'memory'] as $name) {
            $this->assertNotEmpty(array_filter(array_keys($manifest['assets']), fn ($url) => str_contains($url, "/{$name}-")), $name);
        }

        // Stored: the same brochure again without printing.
        $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?pax=2&locale=en')->assertOk();
        $this->assertCount(1, $this->printed);
        $this->assertSame(2, Download::query()->count());
    }

    #[Test]
    public function any_change_to_what_is_printed_makes_it_again(): void
    {
        $customer = $this->lead();
        // Mid-morning in Dhaka, a minute between downloads: within the download limit, and the day unchanged until the
        // last change moves it.
        $this->travelTo(now('Asia/Dhaka')->setTime(10, 0));
        $download = function () use ($customer) {
            $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?locale=bn')->assertOk();
            $this->travel(61)->seconds();

            return count($this->printed);
        };
        $this->assertSame(1, $download());
        $this->assertSame(1, $download());

        $changes = [
            // Landing-page presentation only: the package untouched.
            'a day photo' => fn () => $this->page->dayPhotos()->create(['day_number' => 2, 'media_id' => $this->media('day-2')->id, 'sort_order' => 1]),
            'the banner' => fn () => $this->page->forceFill(['hero_media_id' => $this->media('banner')->id])->saveQuietly(),
            'the introduction' => fn () => $this->page->forceFill(['intro_en' => 'Our words.'])->saveQuietly(),
            // A review of the tour published, and a video added: the page shows both (§9).
            'a review' => fn () => Review::query()->create(['quote_bn' => 'চমৎকার', 'reviewer_name' => 'Rina', 'rating' => 5, 'tour_package_id' => $this->package->id, 'status' => 'published']),
            'a video' => fn () => $this->page->videos()->create(['section' => 'vlog', 'provider' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_id' => 'dQw4w9WgXcQ']),
            // The package's content, a day's text alone.
            'a day of the itinerary' => fn () => $this->package->itineraryDays()->first()->update(['body_en' => 'A changed day.']),
            // A gallery photo's caption.
            'a memory caption' => fn () => TourPhoto::query()->first()->update(['caption_en' => 'Mustang, October 2025']),
            // A departure's price.
            'a departure price' => fn () => $this->departure->update(['price' => 81000]),
            // The office's contact details on the back page.
            'the contact details' => fn () => SiteSetting::query()->updateOrCreate(['key' => 'contact'], ['value' => ['phone' => '+8801700000000']]),
            // A new release of the website's design.
            'a release' => fn () => config(['bhabaghure.release' => 'release-2']),
            // The next day in Dhaka: the countdown's "days from" changes.
            'the date' => fn () => $this->travelTo(now('Asia/Dhaka')->addDay()->setTime(0, 30)),
        ];
        $expected = 1;
        foreach ($changes as $what => $change) {
            $change();
            $this->assertSame(++$expected, $download(), "{$what} makes the brochure again");
        }
        $this->assertSame([], $this->fallbacks);
    }

    #[Test]
    public function off_a_draft_page_or_a_failure_gives_the_photo_brochure_and_nothing_is_stored(): void
    {
        $customer = $this->lead();
        $download = fn () => $this->actingAsApi($customer)->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?locale=en')->assertOk()->getContent();

        // Off: the photo brochure, as before.
        config(['bhabaghure.brochures.landing_pdf' => false]);
        $this->assertSame('%PDF photo brochure', $download());
        $this->assertSame([], $this->printed);

        // On, but the page is a draft: the same.
        config(['bhabaghure.brochures.landing_pdf' => true]);
        $this->page->forceFill(['status' => 'draft'])->save();
        Storage::disk('local')->deleteDirectory('brochures/packages');
        $this->assertSame('%PDF photo brochure', $download());
        $this->assertSame([], $this->printed);

        // Published, and the renderer fails: the photo brochure, a warning, nothing stored for the landing page.
        $this->page->forceFill(['status' => 'published'])->save();
        $this->rendererFails = true;
        Log::spy();
        Storage::disk('local')->deleteDirectory('brochures/packages');
        $this->assertSame('%PDF photo brochure', $download());
        $this->assertSame([], Storage::disk('local')->allFiles('brochures/landing'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'photo brochure is given instead') && str_contains($context['error'], '502'));

        // Next time it works: printed then, not the failure kept.
        $this->rendererFails = false;
        $this->assertSame('%PDF landing page', $download());
        $this->assertCount(1, Storage::disk('local')->allFiles('brochures/landing'));
    }

    #[Test]
    public function a_photo_whose_file_is_missing_never_goes_into_a_landing_brochure(): void
    {
        $dayPhoto = $this->page->dayPhotos()->first()->media;
        Storage::disk('public')->delete($dayPhoto->variants['detail']['path']);
        Storage::disk('public')->delete($dayPhoto->variants['full']['path']);

        $content = $this->actingAsApi($this->lead())->get('/api/v1/portal/downloads/packages/'.self::MUSTANG.'?locale=en')->assertOk()->getContent();

        $this->assertSame('%PDF photo brochure', $content);
        $this->assertSame([], $this->printed, 'Not even sent to the renderer.');
        $this->assertSame([], Storage::disk('local')->allFiles('brochures/landing'));
    }

    #[Test]
    public function a_draft_is_printed_for_review_only_through_the_preview_and_never_stored(): void
    {
        config(['bhabaghure.brochures.landing_pdf' => false]);
        $this->page->forceFill(['status' => 'draft'])->save();
        $out = storage_path('app/private/tmp/review-test.pdf');
        @mkdir(dirname($out), 0777, true);

        $this->artisan('brochures:landing-pdf', ['slug' => 'mustang-valley-trip', 'locale' => 'bn', '--out' => $out])->assertSuccessful();

        $manifest = $this->printed[0];
        $this->assertSame('http://127.0.0.1:3340/tours/preview/print?asOf='.now('Asia/Dhaka')->toDateString(), $manifest['url'], 'The token is not in the address.');
        $this->assertSame($this->page->id, LandingPreviewToken::pageId($manifest['headers']['x-preview-token']));
        $this->assertSame('%PDF landing page', file_get_contents($out));
        $this->assertSame([], Storage::disk('local')->allFiles('brochures/landing'));
        @unlink($out);
    }

    private function lead(): Customer
    {
        return Customer::query()->forceCreate(['name' => 'Tanvir Hasan', 'phone' => '8801711000400', 'stage' => 'lead', 'source' => 'website_form']);
    }

    private function media(string $name): Media
    {
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 110, 190));
        ob_start();
        imagewebp($image);
        $webp = (string) ob_get_clean();
        $variants = [];
        foreach (['card', 'detail', 'full'] as $variant) {
            Storage::disk('public')->put("media/{$name}-{$variant}.webp", $webp);
            $variants[$variant] = ['path' => "media/{$name}-{$variant}.webp", 'width' => 64, 'height' => 48];
        }

        return Media::query()->create(['disk' => 'public', 'mime' => 'image/webp', 'width' => 64, 'height' => 48, 'variants' => $variants, 'is_placeholder' => false]);
    }
}
