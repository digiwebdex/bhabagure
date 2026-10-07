<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Models\SiteSetting;

/**
 * Which of the website's content features the public sees (docs/website-features.md): the site setting `features`, one
 * switch per feature, edited in Admin → Site settings. Off hides the feature on the website only; its content and its
 * admin screen stay as they are. A feature never saved counts as on, so the website is unchanged until someone switches
 * one off.
 */
final class WebsiteFeatures
{
    public const KEYS = ['blog', 'team', 'reviews', 'gallery', 'visas', 'creator', 'partners', 'offers', 'tourPhotos'];

    /** @return array<string, bool> every feature, on unless switched off */
    public static function all(): array
    {
        $saved = SiteSetting::query()->where('key', SiteSettingKeys::FEATURES)->value('value');

        return collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => ($saved[$key] ?? true) !== false])->all();
    }

    public static function enabled(string $feature): bool
    {
        return self::all()[$feature] ?? true;
    }
}
