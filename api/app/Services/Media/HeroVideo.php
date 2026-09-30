<?php

namespace App\Services\Media;

use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Jobs\RevalidateWebsite;
use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\AuditLogger;
use finfo;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The home page's hero video (client, 2026-10-01; docs/hero-video.md): an MP4 or WebM staff upload, or a direct link to
 * one, with its poster — the still shown before it plays and, instead of it, to visitors on Data Saver.
 *
 * An upload arrives in pieces of chunkBytes(), each well inside the server's 8 MB request limit, so no server setting
 * changes and a dropped connection costs one piece, not the whole file. Only the video in use is kept: a replaced upload
 * and its poster are deleted (the server's disk is shared and nearly full). With nothing set, the website plays the
 * video it ships with.
 *
 * Stored in site_settings under `hero`, by this class alone — a path in it is a file this class may delete:
 *   { source: upload|link, video: disk path or https link, poster: ?disk path, name: ?string, bytes: ?int }
 */
final class HeroVideo
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** The largest piece: well inside the live server's 8 MB request limit (deploy/nginx, deploy/php-fpm). */
    private const MAX_CHUNK_BYTES = 4 * 1024 * 1024;

    /** What the browser says the file is → the extension it is stored under. */
    public const TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

    /** What the file itself turns out to be (finfo) → the extension. */
    private const DETECTED = ['video/mp4' => 'mp4', 'video/x-m4v' => 'mp4', 'video/webm' => 'webm'];

    private const DIR = 'hero';

    /** Pages that show a video but are not one: a <video> element can't play them. */
    private const PAGE_HOSTS = ['youtube.com', 'youtu.be', 'facebook.com', 'fb.watch', 'vimeo.com', 'tiktok.com', 'instagram.com'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The size of one piece: MAX_CHUNK_BYTES, or less where PHP takes smaller uploads (PHP's own default is 2 MB, as on a
     * developer's machine). The admin asks for it (GET …/hero-video) and cuts the file to match.
     */
    public static function chunkBytes(): int
    {
        $limit = min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size') - 64 * 1024);

        return max(256 * 1024, min(self::MAX_CHUNK_BYTES, $limit));
    }

    /** "6M" → 6291456; unset or 0 is no limit. */
    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;
        $bytes = match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };

        return $bytes > 0 ? $bytes : PHP_INT_MAX;
    }

    /** @return array<string, mixed>|null the stored value, or null for the video the website ships with */
    public static function stored(): ?array
    {
        $value = SiteSetting::get(SiteSettingKeys::HERO);

        return is_array($value) && filled($value['video'] ?? null) ? $value : null;
    }

    /** @return array{source: string, videoUrl: string, posterUrl: ?string, name: ?string, bytes: ?int}|null */
    public static function present(): ?array
    {
        $value = self::stored();
        if ($value === null) {
            return null;
        }
        $disk = Storage::disk('public');

        return [
            'source' => $value['source'],
            'videoUrl' => $value['source'] === 'upload' ? $disk->url($value['video']) : $value['video'],
            'posterUrl' => filled($value['poster'] ?? null) ? $disk->url($value['poster']) : null,
            'name' => $value['name'] ?? null,
            'bytes' => isset($value['bytes']) ? (int) $value['bytes'] : null,
        ];
    }

    /**
     * Adds one piece of an upload. Pieces come in order; one sent twice (a retry after a lost answer) is accepted again
     * without being added twice.
     *
     * @return int the bytes received so far
     */
    public function appendChunk(string $upload, int $index, int $size, string $type, string $name, UploadedFile $chunk): int
    {
        $part = $this->partPath($upload);
        $meta = "{$part}.json";
        if ($index === 0 && ! is_file($meta)) {
            $this->sweepStaleParts();
            if (! is_dir(dirname($part)) && ! mkdir(dirname($part), 0700, true) && ! is_dir(dirname($part))) {
                throw new RuntimeException('Cannot create the upload directory.');
            }
            file_put_contents($meta, json_encode(['size' => $size, 'type' => $type, 'name' => $name], JSON_THROW_ON_ERROR));
            touch($part);
        }
        $known = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (! is_array($known) || $known['size'] !== $size || $known['type'] !== $type) {
            throw ValidationException::withMessages(['upload' => __('media.hero_upload_lost')]);
        }

        $start = $index * self::chunkBytes();
        $length = min(self::chunkBytes(), $size - $start);
        if ($length <= 0 || $chunk->getSize() !== $length) {
            throw ValidationException::withMessages(['chunk' => __('media.hero_chunk_invalid')]);
        }
        clearstatcache(true, $part);
        $have = (int) filesize($part);
        if ($have >= $start + $length) {
            return $have;
        }
        if ($have !== $start) {
            throw ValidationException::withMessages(['chunk' => __('media.hero_chunk_order')]);
        }
        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($part, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $part);

        return (int) filesize($part);
    }

    /** Puts a finished upload on the home page, with the poster the admin took from it. */
    public function publishUpload(string $upload, ?UploadedFile $poster, Staff $by): array
    {
        $part = $this->partPath($upload);
        $known = is_file("{$part}.json") ? json_decode((string) file_get_contents("{$part}.json"), true) : null;
        clearstatcache(true, $part);
        if (! is_array($known) || ! is_file($part) || filesize($part) !== $known['size']) {
            throw ValidationException::withMessages(['upload' => __('media.hero_upload_unfinished')]);
        }
        // The file itself, not what the browser said: it must really be an MP4 or WebM video.
        $extension = self::DETECTED[(new finfo(FILEINFO_MIME_TYPE))->file($part) ?: ''] ?? null;
        if ($extension === null) {
            $this->discard($upload);
            throw ValidationException::withMessages(['upload' => __('media.hero_not_video')]);
        }

        $video = Storage::disk('public')->putFileAs(self::DIR, new File($part), Str::random(32).".{$extension}");
        $this->discard($upload);

        return $this->replace([
            'source' => 'upload', 'video' => $video, 'poster' => $this->storePoster($poster),
            'name' => mb_substr((string) $known['name'], 0, 200), 'bytes' => (int) $known['size'],
        ], $by);
    }

    /** A direct https link to an MP4 or WebM file, e.g. from cloud storage. */
    public function publishLink(string $url, ?UploadedFile $poster, Staff $by): array
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::PAGE_HOSTS as $page) {
            if ($host === $page || str_ends_with($host, ".{$page}")) {
                throw ValidationException::withMessages(['url' => __('media.hero_page_link')]);
            }
        }

        return $this->replace(['source' => 'link', 'video' => $url, 'poster' => $this->storePoster($poster), 'name' => null, 'bytes' => null], $by);
    }

    /** Back to the video the website ships with. */
    public function restore(Staff $by): void
    {
        $previous = self::stored();
        SiteSetting::query()->whereKey(SiteSettingKeys::HERO)->delete();
        $this->deleteFiles($previous, null);
        $this->audit->record('cms.hero_video.restored', $by);
        RevalidateWebsite::dispatch(['settings']);
    }

    /** @param array<string, mixed> $value */
    private function replace(array $value, Staff $by): array
    {
        $previous = self::stored();
        SiteSetting::query()->updateOrCreate(['key' => SiteSettingKeys::HERO], ['value' => $value, 'updated_by_staff_id' => $by->id]);
        $this->deleteFiles($previous, $value);
        $this->audit->record('cms.hero_video.updated', $by, changes: ['source' => $value['source'], 'name' => $value['name'], 'bytes' => $value['bytes'], 'link' => $value['source'] === 'link' ? $value['video'] : null]);
        RevalidateWebsite::dispatch(['settings']);

        return self::present();
    }

    private function storePoster(?UploadedFile $poster): ?string
    {
        return $poster?->storeAs(self::DIR, Str::random(32).'.'.($poster->guessExtension() ?: 'jpg'), 'public') ?: null;
    }

    /**
     * The files a replaced value pointed at, unless the new one still does. Only paths under hero/ are ever deleted.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $next
     */
    private function deleteFiles(?array $previous, ?array $next): void
    {
        if ($previous === null) {
            return;
        }
        $keep = array_filter([($next['source'] ?? null) === 'upload' ? $next['video'] : null, $next['poster'] ?? null]);
        $old = array_filter([$previous['source'] === 'upload' ? $previous['video'] : null, $previous['poster'] ?? null]);
        foreach (array_diff($old, $keep) as $path) {
            if (is_string($path) && str_starts_with($path, self::DIR.'/') && ! str_contains($path, '..')) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function partPath(string $upload): string
    {
        // A UUID (validated by the controller): nothing else reaches the file system.
        return storage_path('app/private/tmp/hero-video/'.Str::lower($upload).'.part');
    }

    private function discard(string $upload): void
    {
        $part = $this->partPath($upload);
        foreach ([$part, "{$part}.json"] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** Pieces of uploads nobody finished, a day on. */
    private function sweepStaleParts(): void
    {
        foreach (glob(storage_path('app/private/tmp/hero-video/*')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < now()->subDay()->getTimestamp()) {
                unlink($file);
            }
        }
    }
}
