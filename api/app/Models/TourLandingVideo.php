<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video on a landing page (docs/tour-landing-pages.md §9): a guest's feedback or a travel vlog, as a link to YouTube
 * (played in the page after a click) or Facebook (opened there).
 */
class TourLandingVideo extends Model
{
    use HasLocalizedFields;

    public const GUEST = 'guest';

    public const VLOG = 'vlog';

    public const SECTIONS = [self::GUEST, self::VLOG];

    /** facebook.com, its m. and web. hosts, and fb.watch links. */
    public const FACEBOOK_URL = '#^https://(?:(?:www|m|web)\.)?(?:facebook\.com|fb\.watch)/\S+$#i';

    protected $fillable = ['section', 'provider', 'url', 'youtube_id', 'title_en', 'title_bn', 'thumbnail_media_id', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_media_id');
    }

    /** @return array{provider: string, youtube_id: ?string}|null what the link is, or null for neither YouTube nor Facebook */
    public static function recognise(string $url): ?array
    {
        $youtube = CreatorVideo::idFromUrl($url);
        if ($youtube !== null) {
            return ['provider' => 'youtube', 'youtube_id' => $youtube];
        }

        return preg_match(self::FACEBOOK_URL, trim($url)) === 1 ? ['provider' => 'facebook', 'youtube_id' => null] : null;
    }
}
