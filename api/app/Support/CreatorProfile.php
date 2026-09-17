<?php

namespace App\Support;

/**
 * The travel host shown on the home page — for this agency, Shishir Deb — kept as the `creator` site setting:
 *
 *   { name: {bn, en}, bio: {bn, en} | null,
 *     facebook: {url, followers, photoMediaId, coverMediaId} | null,
 *     youtube:  {url, subscribers, videoCount, photoMediaId, coverMediaId} | null }
 *
 * The admin form sends it flat (facebook_url, youtube_subscribers…); this is the one place the two shapes meet.
 */
final class CreatorProfile
{
    public const KEY = 'creator';

    /** A Facebook page or profile link. */
    public const FACEBOOK_URL = '#^https://(www\.|m\.|web\.)?facebook\.com/.+#';

    /** A YouTube channel: @handle, /channel/UC…, /c/name or /user/name. */
    public const YOUTUBE_URL = '#^https://(www\.|m\.)?youtube\.com/(@[\w.-]+|channel/UC[\w-]{22}|c/[^/?\#]+|user/[^/?\#]+)/?([?\#].*)?$#u';

    /**
     * @param  array<string, mixed>  $data  the validated flat form
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        $text = fn (?string $value) => filled($value) ? trim($value) : null;
        $count = fn ($value) => $value === null || $value === '' ? null : (int) $value;
        $media = fn ($value) => $value === null || $value === '' ? null : (int) $value;

        return [
            'name' => ['bn' => trim($data['name_bn']), 'en' => trim($data['name_en'])],
            'bio' => $text($data['bio_bn'] ?? null) === null && $text($data['bio_en'] ?? null) === null
                ? null
                : ['bn' => $text($data['bio_bn'] ?? null), 'en' => $text($data['bio_en'] ?? null)],
            'facebook' => filled($data['facebook_url'] ?? null) ? [
                'url' => self::withoutTracking($data['facebook_url']),
                'followers' => $count($data['facebook_followers'] ?? null),
                'photoMediaId' => $media($data['facebook_photo_media_id'] ?? null),
                'coverMediaId' => $media($data['facebook_cover_media_id'] ?? null),
            ] : null,
            'youtube' => filled($data['youtube_url'] ?? null) ? [
                'url' => self::withoutTracking($data['youtube_url']),
                'subscribers' => $count($data['youtube_subscribers'] ?? null),
                'videoCount' => $count($data['youtube_video_count'] ?? null),
                'photoMediaId' => $media($data['youtube_photo_media_id'] ?? null),
                'coverMediaId' => $media($data['youtube_cover_media_id'] ?? null),
            ] : null,
        ];
    }

    /** Whether there is anything to show: a name alone links nowhere. */
    public static function isShown(?array $value): bool
    {
        return is_array($value) && (! empty($value['facebook']) || ! empty($value['youtube']));
    }

    /** @return list<int> every media id the profile shows, so the media library will not delete one in use */
    public static function mediaIds(?array $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter([
            $value['facebook']['photoMediaId'] ?? null, $value['facebook']['coverMediaId'] ?? null,
            $value['youtube']['photoMediaId'] ?? null, $value['youtube']['coverMediaId'] ?? null,
        ], fn ($id) => $id !== null));
    }

    /**
     * The link as the page's own address: shared links carry tracking (?si=, ?mibextid=) that is nobody's business.
     * A Facebook profile known only by number keeps its ?id=, which is the address.
     */
    public static function withoutTracking(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        if (str_ends_with($path, '/profile.php') && preg_match('/(?:^|&)id=(\d+)/', $parts['query'] ?? '', $id) === 1) {
            return "https://{$parts['host']}{$path}?id={$id[1]}";
        }

        return "https://{$parts['host']}{$path}";
    }
}
