<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;

/**
 * Fills {{variables}} into a template and frames the message. Every WhatsApp message opens with the sender line
 * ("ভবঘুরে হলিডেজ · Bhabaghure Holidays") on its own first line, added here rather than in the editable template, so a
 * customer receiving a message from the notifications number always sees who it is from.
 */
final class MessageRenderer
{
    private const VARIABLE = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /** @return list<string> variables used in $body that $event doesn't provide */
    public static function unknownVariables(NotificationEvent $event, string $body): array
    {
        preg_match_all(self::VARIABLE, $body, $matches);

        return array_values(array_diff(array_unique($matches[1]), $event->variables()));
    }

    /** @param array<string, string> $values */
    public function fill(string $template, array $values): string
    {
        return trim((string) preg_replace_callback(self::VARIABLE, fn (array $m) => $values[$m[1]] ?? '', $template));
    }

    public function whatsApp(string $body): string
    {
        return self::senderLine()."\n".trim($body);
    }

    public static function senderLine(): string
    {
        return (string) config('bhabaghure.notifications.sender_line');
    }
}
