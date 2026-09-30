<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\Media\HeroVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/hero-video.md: staff replace the home page's hero video with an upload (sent in pieces) or a direct link; the
 * website plays what is set, or the video it ships with.
 */
class HeroVideoTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/settings/hero-video';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    #[Test]
    public function an_upload_arrives_in_pieces_and_goes_on_the_home_page_with_its_poster(): void
    {
        $admin = $this->staff('admin');
        $video = $this->mp4(9 * 1024 * 1024);
        $upload = (string) Str::uuid();
        $piece = HeroVideo::chunkBytes();
        $pieces = (int) ceil(strlen($video) / $piece);
        $this->assertGreaterThanOrEqual(3, $pieces);
        $this->assertSame(['maxBytes' => HeroVideo::MAX_BYTES, 'chunkBytes' => $piece], $this->actingAsApi($admin)->getJson(self::BASE)->assertOk()->json('meta'));

        // A piece sent twice (a retry after a lost answer) is not added twice.
        $this->chunk($admin, $upload, $video, 0)->assertOk()->assertJsonPath('data.received', $piece);
        $this->chunk($admin, $upload, $video, 0)->assertOk()->assertJsonPath('data.received', $piece);
        // Out of order: refused, nothing written.
        $this->chunk($admin, $upload, $video, 2)->assertUnprocessable()->assertJsonValidationErrors('chunk');
        for ($index = 1; $index < $pieces - 1; $index++) {
            $this->chunk($admin, $upload, $video, $index)->assertOk()->assertJsonPath('data.received', ($index + 1) * $piece);
        }
        // Not finished: it can't be published yet.
        $this->actingAsApi($admin)->post(self::BASE, ['upload' => $upload])->assertUnprocessable()->assertJsonValidationErrors('upload');
        $this->chunk($admin, $upload, $video, $pieces - 1)->assertOk()->assertJsonPath('data.received', strlen($video));

        $hero = $this->actingAsApi($admin)->post(self::BASE, ['upload' => $upload, 'poster' => UploadedFile::fake()->image('poster.jpg', 1280, 720)])
            ->assertOk()->assertJsonPath('data.source', 'upload')->assertJsonPath('data.name', 'Sajek valley.mp4')->assertJsonPath('data.bytes', strlen($video))->json('data');
        $stored = SiteSetting::get('hero');
        Storage::disk('public')->assertExists($stored['video']);
        Storage::disk('public')->assertExists($stored['poster']);
        $this->assertSame($video, Storage::disk('public')->get($stored['video']), 'the pieces make the whole file');
        $this->assertStringEndsWith('.mp4', $hero['videoUrl']);

        // The website gets the URLs to play, never the stored paths.
        $public = $this->getJson('/api/v1/public/settings')->assertOk()->json('data.hero');
        $this->assertSame(['videoUrl' => $hero['videoUrl'], 'posterUrl' => $hero['posterUrl']], $public);

        // A new video replaces the old one, and the old file and poster are deleted (the disk is shared and nearly full).
        $this->actingAsApi($admin)->post(self::BASE.'/link', ['url' => 'https://cdn.example.com/hero/winter.mp4'])->assertOk()
            ->assertJsonPath('data.source', 'link')->assertJsonPath('data.videoUrl', 'https://cdn.example.com/hero/winter.mp4')->assertJsonPath('data.posterUrl', null);
        Storage::disk('public')->assertMissing($stored['video']);
        Storage::disk('public')->assertMissing($stored['poster']);

        // Back to the video the website ships with.
        $this->actingAsApi($admin)->delete(self::BASE)->assertNoContent();
        $this->assertNull($this->getJson('/api/v1/public/settings')->json('data.hero'));
        $this->actingAsApi($admin)->getJson(self::BASE)->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.maxBytes', HeroVideo::MAX_BYTES);
    }

    #[Test]
    public function only_a_real_mp4_or_webm_of_20_mb_or_less_is_taken(): void
    {
        $admin = $this->staff('admin');

        // Over 20 MB: refused before a byte is stored.
        $this->actingAsApi($admin)->post(self::BASE.'/chunks', [
            'upload' => (string) Str::uuid(), 'index' => 0, 'size' => HeroVideo::MAX_BYTES + 1, 'type' => 'video/mp4', 'name' => 'long.mp4',
            'chunk' => UploadedFile::fake()->createWithContent('chunk', str_repeat('x', 1024)),
        ])->assertUnprocessable()->assertJsonValidationErrors('size');

        // Called a video by the browser, but it isn't one: refused when it is put on the page, and nothing is kept.
        $fake = str_repeat('not a video ', 100);
        $upload = (string) Str::uuid();
        $this->chunk($admin, $upload, $fake, 0, type: 'video/mp4')->assertOk();
        $this->actingAsApi($admin)->post(self::BASE, ['upload' => $upload])->assertUnprocessable()->assertJsonValidationErrors('upload');
        $this->assertNull(SiteSetting::get('hero'));
        $this->assertSame([], Storage::disk('public')->allFiles());

        // A page that shows a video is not a video file.
        $this->actingAsApi($admin)->post(self::BASE.'/link', ['url' => 'https://www.youtube.com/watch?v=abc'])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->actingAsApi($admin)->post(self::BASE.'/link', ['url' => 'http://cdn.example.com/hero.mp4'])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    #[Test]
    public function website_editors_change_it_and_nobody_can_through_the_general_settings(): void
    {
        $this->actingAsApi($this->staff('sales_agent'))->getJson(self::BASE)->assertForbidden();
        $this->actingAsApi($this->staff('sales_agent'))->post(self::BASE.'/link', ['url' => 'https://cdn.example.com/a.mp4'])->assertForbidden();

        // Its stored paths are files the hero video deletes: the general settings update never writes them.
        $this->actingAsApi($this->staff('admin'))->putJson('/api/v1/admin/settings/hero', ['value' => ['source' => 'upload', 'video' => '../../.env']])->assertNotFound();
        $this->assertNull(SiteSetting::get('hero'));
    }

    private function chunk(Staff $staff, string $upload, string $bytes, int $index, string $type = 'video/mp4')
    {
        $piece = substr($bytes, $index * HeroVideo::chunkBytes(), HeroVideo::chunkBytes());

        return $this->actingAsApi($staff)->post(self::BASE.'/chunks', [
            'upload' => $upload, 'index' => $index, 'size' => strlen($bytes), 'type' => $type, 'name' => 'Sajek valley.mp4',
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $piece),
        ], ['Accept' => 'application/json']);
    }

    /** An ISO base media file header ("ftyp", MP4 brands) and padding: what the file type check reads. */
    private function mp4(int $bytes): string
    {
        $header = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41";

        return $header.str_repeat("\x00", $bytes - strlen($header));
    }
}
