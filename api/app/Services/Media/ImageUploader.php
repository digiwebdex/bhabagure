<?php

namespace App\Services\Media;

use App\Enums\MediaVariant;
use App\Models\Media;
use App\Models\Staff;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns an uploaded photo into stored WebP variants.
 *
 * - The raw upload is never kept. Re-encoding drops EXIF, which on phone photos includes GPS location.
 * - Orientation from EXIF is applied first, so portrait phone photos don't come out sideways.
 * - Images are only ever scaled down, never enlarged.
 *
 * Size and type limits are validated by the request before this runs (config/bhabaghure.php → media).
 */
final class ImageUploader
{
    public function store(UploadedFile $file, ?Staff $uploader, array $attributes = []): Media
    {
        $disk = (string) config('bhabaghure.media.disk');
        $this->guardAgainstDecompressionBombs($file);

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodePath($file->getRealPath());
            $image->orient();
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => [__('media.unreadable')]]);
        }

        $width = $image->width();
        $height = $image->height();
        $directory = 'media/'.now()->format('Y/m');
        $basename = (string) Str::uuid();
        $variants = [];
        $written = [];

        try {
            // Largest first: each step scales the previous result down.
            foreach (array_reverse(MediaVariant::cases()) as $variant) {
                $image->scaleDown(width: $variant->maxWidth());
                $encoded = $image->encode(new WebpEncoder(quality: (int) config('bhabaghure.media.webp_quality'), strip: true));
                $path = "{$directory}/{$basename}-{$variant->value}.webp";

                Storage::disk($disk)->put($path, (string) $encoded, ['visibility' => 'public']);
                $written[] = $path;

                $variants[$variant->value] = [
                    'path' => $path,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'bytes' => strlen((string) $encoded),
                ];
            }
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($written);

            throw $exception;
        }

        return Media::query()->create([
            'disk' => $disk,
            'directory' => $directory,
            'original_filename' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'mime' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'variants' => array_reverse($variants),
            'alt_bn' => $attributes['alt_bn'] ?? null,
            'alt_en' => $attributes['alt_en'] ?? null,
            'credit' => $attributes['credit'] ?? null,
            'credit_url' => $attributes['credit_url'] ?? null,
            'uploaded_by_staff_id' => $uploader?->id,
        ]);
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->storedPaths());
        $media->delete();
    }

    /** Reads only the header: a 2 MB file can still claim to be 50,000 × 50,000 pixels. */
    private function guardAgainstDecompressionBombs(UploadedFile $file): void
    {
        $size = @getimagesize($file->getRealPath());
        if ($size === false) {
            throw ValidationException::withMessages(['file' => [__('media.unreadable')]]);
        }

        if ((int) config('bhabaghure.media.max_pixels') < $size[0] * $size[1]) {
            throw ValidationException::withMessages(['file' => [__('media.too_many_pixels')]]);
        }
    }
}
