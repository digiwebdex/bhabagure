<?php

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CmsMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    #[Test]
    public function a_phone_photo_becomes_webp_card_and_detail_variants_without_being_enlarged(): void
    {
        $response = $this->actingAsApi($this->staff('tour_operator'))->post('/api/v1/admin/media', [
            'file' => $this->jpeg(3000, 2000, 'IMG_2041.JPG'),
            'alt_en' => 'Phewa Lake at dawn',
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = Media::query()->sole();
        $this->assertSame('IMG_2041.JPG', $media->original_filename);
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame([3000, 2000], [$media->width, $media->height]);
        $this->assertGreaterThan(0, $media->bytes);

        $expectedWidths = ['thumb' => 400, 'card' => 800, 'detail' => 1600, 'full' => 2400];
        foreach ($expectedWidths as $name => $width) {
            $variant = $media->variants[$name];
            $this->assertSame($width, $variant['width'], $name);
            // Each size is scaled from the previous one, so heights may differ from exact 3:2 by a pixel.
            $this->assertEqualsWithDelta($width * 2 / 3, $variant['height'], 1, $name);
            Storage::disk('public')->assertExists($variant['path']);
            $this->assertStringEndsWith('.webp', $variant['path']);
            $this->assertSame('image/webp', getimagesizefromstring(Storage::disk('public')->get($variant['path']))['mime']);
        }
        $response->assertJsonPath('data.variants.card.width', 800);

        // A small image is stored at its own size in every variant.
        $this->actingAsApi($this->staff('admin'))->post('/api/v1/admin/media', ['file' => $this->jpeg(600, 400)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.variants.full.width', 600)->assertJsonPath('data.variants.card.width', 600);
    }

    #[Test]
    public function uploads_over_five_megabytes_are_rejected(): void
    {
        $this->actingAsApi($this->staff('admin'))
            ->post('/api/v1/admin/media', ['file' => UploadedFile::fake()->create('huge.jpg', 5121, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, Media::query()->count());
    }

    #[Test]
    public function files_that_are_not_images_are_rejected_whatever_their_name(): void
    {
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->post('/api/v1/admin/media', ['file' => UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "hi";')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAsApi($admin)->post('/api/v1/admin/media', ['file' => UploadedFile::fake()->create('brochure.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function exif_data_such_as_gps_location_is_not_carried_into_the_stored_files(): void
    {
        $this->actingAsApi($this->staff('admin'))->post('/api/v1/admin/media', ['file' => $this->jpegWithExif()], ['Accept' => 'application/json'])->assertCreated();

        foreach (Media::query()->sole()->storedPaths() as $path) {
            $bytes = Storage::disk('public')->get($path);
            $this->assertStringNotContainsString('Exif', $bytes);
            $this->assertStringNotContainsString('GPS', $bytes);
        }
    }

    #[Test]
    public function an_image_still_in_use_cannot_be_deleted(): void
    {
        $admin = $this->staff('admin');
        $id = $this->actingAsApi($admin)->post('/api/v1/admin/media', ['file' => $this->jpeg(800, 600)], ['Accept' => 'application/json'])->json('data.id');
        $this->actingAsApi($admin)->postJson('/api/v1/admin/team', ['name_bn' => 'হাবিব', 'name_en' => 'Habib', 'role_bn' => 'অপারেটর', 'role_en' => 'Operator', 'photo_media_id' => $id])->assertCreated();

        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$id}")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

        $member = $this->actingAsApi($admin)->getJson('/api/v1/admin/team')->json('data.0.id');
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/team/{$member}")->assertOk();
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/media/{$id}")->assertOk();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function jpeg(int $width, int $height, string $name = 'photo.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), $height, imagecolorallocate($image, 242, 106, 27));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** A JPEG with an APP1 Exif segment containing a GPS marker, as a phone would write. */
    private function jpegWithExif(): UploadedFile
    {
        $jpeg = (string) $this->jpeg(1200, 900)->getContent();
        $payload = "Exif\0\0".'MM'."\0*".'GPSLatitude 23.7806N GPSLongitude 90.3790E';
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return UploadedFile::fake()->createWithContent('gps.jpg', substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }
}
