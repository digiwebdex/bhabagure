<?php

namespace Tests\Feature;

use App\Jobs\RevalidateWebsite;
use App\Models\Media;
use App\Services\Invoices\InvoiceView;
use App\Support\Brand;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/brand-logo.md: the logos and browser icon chosen in Site settings, used by the website, admin and PDFs. */
class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function the_originals_are_used_until_logos_are_chosen_and_then_everywhere_until_reset(): void
    {
        Bus::fake([RevalidateWebsite::class]);
        $admin = $this->staff('admin');
        $save = fn (array $value) => $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/brand', ['value' => $value + ['logo' => null, 'logoLight' => null, 'icon' => null]]);

        // Nothing chosen: the website keeps its own files, and PDFs the original logo.
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.logos', ['logo' => null, 'logoLight' => null, 'icon' => null]);
        $original = app(InvoiceView::class)->letterhead('en')['logo'];
        $this->assertStringStartsWith('data:image/png;base64,', (string) $original);

        $logo = $this->upload(1200, 500);
        $light = $this->upload(1200, 500);
        $icon = $this->upload(512, 512);
        $save(['logo' => $logo->id, 'logoLight' => $light->id, 'icon' => $icon->id])->assertOk();
        Bus::assertDispatched(RevalidateWebsite::class, fn (RevalidateWebsite $job) => $job->tags === ['settings']);

        $brand = $this->getJson('/api/v1/public/settings')->json('data.logos');
        $this->assertStringEndsWith('-card.webp', $brand['logo']);
        $this->assertStringEndsWith('-card.webp', $brand['logoLight']);
        $this->assertStringEndsWith('-thumb.webp', $brand['icon']);
        // Printed documents use the chosen logo, and their stored copies are made again.
        $this->assertStringStartsWith('data:image/webp;base64,', (string) app(InvoiceView::class)->letterhead('en')['logo']);
        $this->assertSame("{$logo->id},{$light->id},{$icon->id}", Brand::fingerprint());

        // A logo in use can't be deleted from the media library.
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$logo->id}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

        // The icon must be square; an unknown image is refused.
        $save(['icon' => $logo->id])->assertUnprocessable()->assertJsonValidationErrors('value.icon');
        $save(['logo' => 999999])->assertUnprocessable()->assertJsonValidationErrors('value.logo');
        $this->actingAsApi($this->staff('sales_agent'))->putJson('/api/v1/admin/settings/brand', ['value' => ['logo' => null]])->assertForbidden();

        // Reset: back to the originals, and the image is free to delete again.
        $save([])->assertOk();
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.logos', ['logo' => null, 'logoLight' => null, 'icon' => null]);
        $this->assertSame($original, app(InvoiceView::class)->letterhead('en')['logo']);
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$logo->id}")->assertOk();
    }

    private function upload(int $width, int $height): Media
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, imagecolorallocate($image, 242, 106, 27));
        ob_start();
        imagepng($image);
        $file = UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());

        $id = $this->actingAsApi($this->staff('admin'))->post('/api/v1/admin/media', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Media::query()->findOrFail($id);
    }
}
