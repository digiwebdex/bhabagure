<?php

namespace App\Support;

use App\Models\NewsletterSubscriber;

/**
 * The signed token in a newsletter unsubscribe link: `{subscriber id}-{signature}` (no dot: the website's router treats
 * dotted paths as static files).
 *
 * The signature is an HMAC (key derived from APP_KEY) over the id and the subscriber's stored random token,
 * so the link works without signing in, can't be forged or edited to target another subscriber, and stops
 * working when the stored token is rotated.
 */
final class NewsletterUnsubscribeToken
{
    private const PURPOSE = 'newsletter-unsubscribe';

    public static function for(NewsletterSubscriber $subscriber): string
    {
        return $subscriber->id.'-'.self::signature($subscriber);
    }

    /** The subscriber a token belongs to, or null when it is malformed, forged or stale. */
    public static function resolve(string $token): ?NewsletterSubscriber
    {
        if (preg_match('/^(\d{1,19})-([A-Za-z0-9_-]{43})$/', $token, $match) !== 1) {
            return null;
        }

        $subscriber = NewsletterSubscriber::query()->find((int) $match[1]);

        return $subscriber !== null && hash_equals(self::signature($subscriber), $match[2]) ? $subscriber : null;
    }

    /** Link to the website's unsubscribe page, which shows a button that POSTs back to the API. */
    public static function url(NewsletterSubscriber $subscriber): string
    {
        return rtrim((string) config('bhabaghure.web_url'), '/').'/newsletter/unsubscribe/'.self::for($subscriber);
    }

    private static function signature(NewsletterSubscriber $subscriber): string
    {
        $key = hash_hmac('sha256', self::PURPOSE, (string) config('app.key'), true);
        $mac = hash_hmac('sha256', $subscriber->id.'|'.$subscriber->unsubscribe_token, $key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
