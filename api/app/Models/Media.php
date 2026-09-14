<?php

namespace App\Models;

use App\Enums\MediaVariant;
use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasLocalizedFields;

    protected $table = 'media';

    protected $fillable = [
        'disk', 'directory', 'original_filename', 'mime', 'bytes', 'width', 'height', 'variants',
        'source_url', 'is_placeholder', 'alt_bn', 'alt_en', 'credit', 'credit_url', 'uploaded_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['variants' => 'array', 'is_placeholder' => 'boolean', 'bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    /** Public URL of a variant; falls back to the largest stored variant, or to the seeded remote URL. */
    public function url(MediaVariant $variant = MediaVariant::Detail): ?string
    {
        if ($this->source_url !== null) {
            return $this->source_url;
        }

        $variants = $this->variants ?? [];
        $path = $variants[$variant->value]['path'] ?? null;
        foreach (array_reverse(MediaVariant::cases()) as $fallback) {
            $path ??= $variants[$fallback->value]['path'] ?? null;
        }

        return $path === null ? null : Storage::disk($this->disk)->url($path);
    }

    /** @return array<string, array{url: string, width: int, height: int}> */
    public function variantUrls(): array
    {
        $out = [];
        foreach ($this->variants ?? [] as $name => $variant) {
            $out[$name] = [
                'url' => Storage::disk($this->disk)->url($variant['path']),
                'width' => (int) $variant['width'],
                'height' => (int) $variant['height'],
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function storedPaths(): array
    {
        return array_values(array_map(fn (array $variant) => $variant['path'], $this->variants ?? []));
    }
}
