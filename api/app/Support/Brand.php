<?php

namespace App\Support;

use App\Enums\MediaVariant;
use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Models\Media;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;

/**
 * The logos and browser icon chosen in Site settings → Brand (docs/brand-logo.md): media-library images by id, in the
 * site setting `brand` = { logo, logoLight, icon }. Any left unset means the original that ships with the site.
 */
final class Brand
{
    public const SLOTS = ['logo', 'logoLight', 'icon'];

    /** @return array{logo: ?int, logoLight: ?int, icon: ?int} */
    public static function ids(): array
    {
        $saved = SiteSetting::get(SiteSettingKeys::BRAND);

        return collect(self::SLOTS)->mapWithKeys(fn (string $slot) => [$slot => is_numeric($saved[$slot] ?? null) ? (int) $saved[$slot] : null])->all();
    }

    /**
     * Where the website, the portal and the admin load them from; null keeps the original file.
     *
     * @return array{logo: ?string, logoLight: ?string, icon: ?string}
     */
    public static function urls(): array
    {
        $media = self::media();

        return collect(self::SLOTS)->mapWithKeys(fn (string $slot) => [
            // A logo is shown at most a few hundred pixels wide; the icon at most 512 (thumb is 400 wide).
            $slot => $media[$slot]?->url($slot === 'icon' ? MediaVariant::Thumb : MediaVariant::Card),
        ])->all();
    }

    /** The logo for printed documents (invoices, quotations, payslips, brochures), or null for the original file. */
    public static function documentLogo(): ?string
    {
        $logo = self::media()['logo'];
        $path = $logo?->variants[MediaVariant::Card->value]['path'] ?? null;
        if ($logo === null || $path === null || ! Storage::disk($logo->disk)->exists($path)) {
            return null;
        }

        return 'data:image/webp;base64,'.base64_encode((string) Storage::disk($logo->disk)->get($path));
    }

    /** Changes when a logo does, so stored PDFs made with the old one are made again. */
    public static function fingerprint(): string
    {
        return implode(',', array_map(fn (?int $id) => (string) $id, self::ids()));
    }

    /** @return list<int> media in use as a logo or icon: the media library won't delete them */
    public static function mediaIds(): array
    {
        return array_values(array_filter(self::ids()));
    }

    /** @return array{logo: ?Media, logoLight: ?Media, icon: ?Media} */
    private static function media(): array
    {
        $ids = self::ids();
        $found = Media::query()->whereIn('id', array_filter($ids))->get()->keyBy('id');

        return collect($ids)->map(fn (?int $id) => $id === null ? null : $found->get($id))->all();
    }
}
