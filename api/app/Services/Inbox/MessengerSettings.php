<?php

namespace App\Services\Inbox;

use App\Models\SiteSetting;
use App\Models\Staff;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Facebook Page the inbox answers for (docs/admin-inbox.md §4): its id and name, the Page access token and the App
 * secret, entered in Admin → Inbox → Settings. The token and secret are kept encrypted with the app key and never sent
 * back to the browser; the verify token is made here and shown for Meta's webhook form. Not a public site setting.
 */
final class MessengerSettings
{
    public const KEY = 'inbox_messenger';

    /** @return array{page_id: ?string, page_name: ?string, page_token: ?string, app_secret: ?string, verify_token: string, connected_at: ?string} */
    public static function get(): array
    {
        $value = (array) SiteSetting::get(self::KEY, []);

        return [
            'page_id' => $value['page_id'] ?? null,
            'page_name' => $value['page_name'] ?? null,
            'page_token' => self::decrypt($value['page_token'] ?? null),
            'app_secret' => self::decrypt($value['app_secret'] ?? null),
            'verify_token' => (string) ($value['verify_token'] ?? self::ensureVerifyToken()),
            'connected_at' => $value['connected_at'] ?? null,
        ];
    }

    public static function connected(): bool
    {
        $settings = self::get();

        return $settings['page_id'] !== null && $settings['page_token'] !== null && $settings['app_secret'] !== null;
    }

    /** Saves a checked connection; a blank token or secret keeps the one stored. */
    public static function save(string $pageId, string $pageName, ?string $pageToken, ?string $appSecret, Staff $by): void
    {
        $current = (array) SiteSetting::get(self::KEY, []);
        SiteSetting::query()->updateOrCreate(['key' => self::KEY], [
            'value' => [
                'page_id' => $pageId,
                'page_name' => $pageName,
                'page_token' => $pageToken !== null && $pageToken !== '' ? Crypt::encryptString($pageToken) : ($current['page_token'] ?? null),
                'app_secret' => $appSecret !== null && $appSecret !== '' ? Crypt::encryptString($appSecret) : ($current['app_secret'] ?? null),
                'verify_token' => $current['verify_token'] ?? self::ensureVerifyToken(),
                'connected_at' => now()->toIso8601String(),
            ],
            'updated_by_staff_id' => $by->id,
        ]);
    }

    public static function disconnect(Staff $by): void
    {
        $current = (array) SiteSetting::get(self::KEY, []);
        SiteSetting::query()->updateOrCreate(['key' => self::KEY], [
            'value' => ['verify_token' => $current['verify_token'] ?? Str::random(40)],
            'updated_by_staff_id' => $by->id,
        ]);
    }

    private static function ensureVerifyToken(): string
    {
        $current = (array) SiteSetting::get(self::KEY, []);
        if (isset($current['verify_token'])) {
            return (string) $current['verify_token'];
        }
        $token = Str::random(40);
        SiteSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $current + ['verify_token' => $token]]);

        return $token;
    }

    private static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Encrypted under another app key: treated as not set, so staff enter it again.
            return null;
        }
    }
}
