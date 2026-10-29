<?php

namespace App\Services\Brochures;

use App\Enums\MediaVariant;
use App\Models\Media;
use App\Models\TourPackage;
use App\Models\TourPhoto;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The photos of a package's brochure (docs/group-tour-brochures.md): the package's cover as the banner, then its other
 * photos and published photos of the Group Tour Gallery, in an order that looks random but is the same on every
 * download of the same package. Rows are "justified": every photo keeps its own shape (never stretched, never cut) and
 * a row's photos share one height, so together they fill the page's width.
 */
class BrochurePhotos
{
    /** Width of the page's text area, and the space between two photos, in mm. */
    public const WIDTH_MM = 188.0;

    public const GAP_MM = 2.5;

    /**
     * The banner is as tall as its photo at full page width, within these: a taller photo is trimmed at top and bottom,
     * a very wide one at the sides, so the title over it always has room.
     */
    private const HERO_MIN_MM = 80.0;

    private const HERO_MAX_MM = 105.0;

    /**
     * @return array{hero: ?array{src: string, heightMm: float}, photos: list<array{src: string, aspect: float, caption: ?string}>}
     */
    public static function for(TourPackage $package, string $locale): array
    {
        // The banner: the cover, or the next of the package's photos when the cover's file is missing.
        $own = $package->images()->with('media')->get()->map->media->filter()->values();
        $hero = null;
        $cover = $own->first(function (Media $media) use (&$hero) {
            $src = self::dataUri($media, MediaVariant::Detail);
            $size = self::size($media, MediaVariant::Detail);
            $hero = $src === null || $size === null ? null : [
                'src' => $src,
                'heightMm' => round(max(self::HERO_MIN_MM, min(210.0 * $size[1] / $size[0], self::HERO_MAX_MM)), 1),
            ];

            return $hero !== null;
        });
        $own = $own->reject(fn (Media $media) => $media->id === $cover?->id)->values()->all();

        $caption = fn (TourPhoto $photo) => ($locale === 'en' ? ($photo->caption_en ?: $photo->caption_bn) : ($photo->caption_bn ?: $photo->caption_en)) ?: null;

        // The package's own photos lead, then its gallery photos; each shuffled by the package, so two brochures differ but one
        // brochure never changes between downloads.
        $candidates = array_map(fn (Media $media) => [$media, null], self::shuffled($own, $package->id, fn (Media $media) => $media->id));
        foreach (self::gallery($package) as $photo) {
            $candidates[] = [$photo->image, $caption($photo)];
        }

        $seen = $cover === null ? [] : [$cover->id => true];
        $photos = [];
        foreach ($candidates as [$media, $text]) {
            $src = isset($seen[$media->id]) ? null : self::dataUri($media, MediaVariant::Card);
            $size = self::size($media, MediaVariant::Card);
            if ($src === null || $size === null) {
                continue;
            }
            $seen[$media->id] = true;
            $photos[] = ['src' => $src, 'aspect' => $size[0] / $size[1], 'caption' => $text];
        }

        return ['hero' => $hero, 'photos' => $photos];
    }

    /**
     * The Group Tour Gallery's published photos of this tour — the brochure's and the landing page's memories
     * (docs/tour-landing-pages.md §2): only those staff linked to this package, never another tour's or an unlinked one
     * (client, 2026-10-29: no Thailand trip in a Mustang brochure). Shuffled by the package, so two brochures differ but
     * one never changes between downloads. None: no memories.
     *
     * @return list<TourPhoto> with image loaded
     */
    public static function gallery(TourPackage $package): array
    {
        $own = TourPhoto::query()->published()->where('tour_package_id', $package->id)
            ->with(['image', 'tourPackage:id,destination_id,slug,status,title_en,title_bn'])->get()
            ->filter(fn (TourPhoto $photo) => $photo->image !== null)->values()->all();

        return self::shuffled($own, $package->id, fn (TourPhoto $photo) => $photo->media_id);
    }

    /** Changes when a photo the brochure prints does, so stored PDFs are made again. */
    public static function fingerprint(TourPackage $package): string
    {
        $gallery = TourPhoto::query()->published()->where('tour_package_id', $package->id)->orderBy('id')->get(['id', 'media_id', 'caption_bn', 'caption_en', 'updated_at']);
        $own = $package->images()->get(['id', 'media_id', 'is_cover', 'sort_order']);

        return sha1(json_encode([$gallery->map(fn (TourPhoto $photo) => [$photo->id, $photo->media_id, $photo->caption_bn, $photo->caption_en, $photo->updated_at?->getTimestamp()]), $own->toArray()]));
    }

    /**
     * Photos in rows of one height. Each row takes photos until it is no taller than $targetMm; a last row that would be
     * taller joins the row before it when the two still read well together, else it is shown at $targetMm, centred.
     *
     * @param  list<array{src: string, aspect: float, caption: ?string}>  $photos
     * @return list<array{heightMm: float, photos: list<array{src: string, aspect: float, caption: ?string, widthMm: float}>}>
     */
    public static function rows(array $photos, float $targetMm, float $minMm): array
    {
        $groups = [];
        $current = [];
        foreach ($photos as $photo) {
            $current[] = $photo;
            if (self::height($current) <= $targetMm) {
                $groups[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $last = array_key_last($groups);
            if ($last !== null && self::height([...$groups[$last], ...$current]) >= $minMm) {
                $groups[$last] = [...$groups[$last], ...$current];
            } else {
                $groups[] = $current;
            }
        }

        return array_map(function (array $group) use ($targetMm) {
            $height = round(min(self::height($group), $targetMm), 2);

            return [
                'heightMm' => $height,
                // Rounded down: a row is never wider than the page.
                'photos' => array_map(fn (array $photo) => $photo + ['widthMm' => floor($height * $photo['aspect'] * 100) / 100], $group),
            ];
        }, $groups);
    }

    /** The height at which these photos, side by side, fill the page's width. */
    private static function height(array $photos): float
    {
        $aspects = array_sum(array_column($photos, 'aspect'));

        return (self::WIDTH_MM - self::GAP_MM * (count($photos) - 1)) / $aspects;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): int  $key
     * @return list<T>
     */
    private static function shuffled(array $items, int $seed, callable $key): array
    {
        usort($items, fn ($a, $b) => crc32("{$seed}:{$key($a)}") <=> crc32("{$seed}:{$key($b)}") ?: $key($a) <=> $key($b));

        return $items;
    }

    /** @return array{0: int, 1: int}|null the stored variant's pixel size (the largest there is, when that one is missing) */
    private static function size(Media $media, MediaVariant $variant): ?array
    {
        $stored = self::variant($media, $variant);

        return $stored === null || (int) $stored['width'] < 1 || (int) $stored['height'] < 1 ? null : [(int) $stored['width'], (int) $stored['height']];
    }

    /**
     * The photo inline, as the PDF renderer loads nothing from the network. As a JPEG: Chrome puts a JPEG into the PDF as
     * it is, but stores a WebP uncompressed — twenty photos made a 28 MB brochure.
     */
    private static function dataUri(Media $media, MediaVariant $variant): ?string
    {
        $path = self::variant($media, $variant)['path'] ?? null;
        $disk = Storage::disk($media->disk);
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }
        $bytes = (string) $disk->get($path);
        try {
            return 'data:image/jpeg;base64,'.base64_encode((string) ImageManager::usingDriver(GdDriver::class)->decodeBinary($bytes)->encode(new JpegEncoder(quality: 82, strip: true)));
        } catch (Throwable) {
            // Not an image GD reads: as stored, which Chrome may still show.
            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'image/webp',
            };

            return "data:{$mime};base64,".base64_encode($bytes);
        }
    }

    /**
     * The photo as JPEG bytes for a PDF (the landing-page brochure's prepared copies, docs/tour-landing-pages.md §7), or
     * null when its file is missing or isn't an image GD reads — so a brochure never goes out with a photo missing.
     */
    public static function jpeg(Media $media, MediaVariant $variant = MediaVariant::Detail): ?string
    {
        $path = self::variant($media, $variant)['path'] ?? null;
        $disk = Storage::disk($media->disk);
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }
        try {
            return (string) ImageManager::usingDriver(GdDriver::class)->decodeBinary((string) $disk->get($path))->encode(new JpegEncoder(quality: 82, strip: true));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{path: string, width: int, height: int}|null */
    private static function variant(Media $media, MediaVariant $variant): ?array
    {
        $variants = $media->variants ?? [];

        return $variants[$variant->value] ?? $variants[MediaVariant::Full->value] ?? $variants[MediaVariant::Detail->value] ?? null;
    }
}
