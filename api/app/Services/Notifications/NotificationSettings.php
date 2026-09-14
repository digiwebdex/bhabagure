<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Models\SiteSetting;
use App\Models\Staff;
use Illuminate\Support\Collection;

/** Notification settings kept in site_settings, editable without a deploy. */
final class NotificationSettings
{
    /** The WhatsApp number automated messages come from, as published on the website, invoice and booking page. */
    public static function notificationsNumber(): ?string
    {
        $number = SiteSetting::get('contact', [])['notificationsWhatsapp'] ?? null;

        return is_string($number) && $number !== '' ? $number : null;
    }

    public static function mainNumber(): ?string
    {
        $number = SiteSetting::get('contact', [])['phone'] ?? null;

        return is_string($number) && $number !== '' ? $number : null;
    }

    /** @return array<string, list<int>> staff ids per alert event */
    public static function alertRecipients(): array
    {
        $saved = self::all()['alertRecipients'] ?? [];
        $events = [NotificationEvent::NewBookingAlert, NotificationEvent::NewLeadAlert, NotificationEvent::LowSeatAlert];

        return collect($events)->mapWithKeys(fn (NotificationEvent $e) => [$e->value => array_values(array_map('intval', $saved[$e->value] ?? []))])->all();
    }

    /** Active staff on an alert's list. */
    public static function recipientsFor(NotificationEvent $event): Collection
    {
        $ids = self::alertRecipients()[$event->value] ?? [];

        return Staff::query()->whereIn('id', $ids)->where('status', 'active')->get();
    }

    /** @param array<string, list<int>> $recipients */
    public static function saveAlertRecipients(array $recipients, Staff $by): void
    {
        self::save(['alertRecipients' => $recipients] + self::all(), $by);
    }

    /** @return array{status: string, checkedAt: ?string} */
    public static function sessionStatus(): array
    {
        $session = self::all()['session'] ?? [];

        return ['status' => (string) ($session['status'] ?? 'unknown'), 'checkedAt' => $session['checkedAt'] ?? null];
    }

    public static function recordSessionStatus(string $status): void
    {
        self::save(['session' => ['status' => $status, 'checkedAt' => now()->toIso8601String()]] + self::all());
    }

    /** @return array<string, mixed> */
    private static function all(): array
    {
        return SiteSetting::get(SiteSettingKeys::NOTIFICATIONS, []);
    }

    /** @param array<string, mixed> $value */
    private static function save(array $value, ?Staff $by = null): void
    {
        SiteSetting::query()->updateOrCreate(['key' => SiteSettingKeys::NOTIFICATIONS], array_filter([
            'value' => $value,
            'updated_by_staff_id' => $by?->id,
        ], fn ($v) => $v !== null));
    }
}
