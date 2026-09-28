<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\Inbox\WhatsAppInbox;
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

    /**
     * How a message a staff member writes to a customer (a request reply, "Send WhatsApp") goes by WhatsApp:
     * `notifications` — the automated messages' number, when they are on and it is published; `inbox` — else from the
     * main number through the admin inbox's sender, and into that customer's chat (decided 2026-09-28,
     * docs/admin-inbox.md §6); null when neither is set up.
     */
    public static function staffWhatsAppRoute(): ?string
    {
        $published = self::notificationsNumber() !== null;
        if ($published && config('bhabaghure.notifications.whatsapp.mode') !== 'off') {
            return 'notifications';
        }
        if (WhatsAppInbox::enabled()) {
            return 'inbox';
        }

        // As before the inbox: with the number published a message is planned, and the log says if WhatsApp is off.
        return $published ? 'notifications' : null;
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
        $events = NotificationEvent::staffAlerts();

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
