<?php

namespace App\Http\Resources;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationDelivery;
use Carbon\CarbonInterface;

/** Notifications for the admin (snake_case). Private booking links in message bodies are masked. */
final class AdminNotification
{
    /** @return array<string, mixed> */
    public static function message(NotificationMessage $row): array
    {
        return [
            'id' => $row->id,
            'event' => $row->event->value,
            'channel' => $row->channel->value,
            'status' => $row->status->value,
            // Who carried it: wasender, bulksmsbd, or the mailer. "log" or "array" means the email went nowhere.
            'provider' => $row->provider,
            'to' => $row->channel === NotificationChannel::Email ? $row->to_address : self::maskPhone($row->to_address),
            'group_key' => $row->group_key,
            'fallback_of_id' => $row->fallback_of_id,
            'sms_parts' => $row->sms_parts,
            'sms_encoding' => $row->sms_encoding,
            // Estimated BDT when sent: SMS parts × rate; WhatsApp and email 0; null while not sent.
            'cost' => $row->cost === null ? null : (float) $row->cost,
            'recipient_type' => $row->recipient_type,
            'related' => $row->related_type ? ['type' => $row->related_type, 'id' => $row->related_id] : null,
            'title' => $row->title,
            'body' => $row->event === NotificationEvent::WhatsAppVerification ? NotificationDelivery::maskCodes($row->body) : self::maskTokens($row->body),
            'has_attachment' => $row->attachment_path !== null,
            'attempts' => $row->attempts,
            'last_error' => $row->last_error,
            'skipped_reason' => $row->skipped_reason,
            'scheduled_for' => $row->scheduled_for?->toIso8601String(),
            'sent_at' => $row->sent_at?->toIso8601String(),
            'delivered_at' => $row->delivered_at?->toIso8601String(),
            'read_at' => $row->read_at?->toIso8601String(),
            'failed_at' => $row->failed_at?->toIso8601String(),
            'triggered_by_staff_id' => $row->triggered_by_staff_id,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function template(NotificationTemplate $template): array
    {
        return [
            'id' => $template->id,
            'event' => $template->event->value,
            'channel' => $template->channel->value,
            'audience' => $template->event->audience(),
            'timing' => $template->event->timing(),
            'variables' => $template->event->variables(),
            'subject_bn' => $template->subject_bn,
            'subject_en' => $template->subject_en,
            'body_bn' => $template->body_bn,
            'body_en' => $template->body_en,
            'is_enabled' => $template->is_enabled,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One entry per logical message (a group key): WhatsApp, email and SMS side by side, each with its own status —
     * never one combined state. Newest first.
     *
     * @param  iterable<NotificationMessage>  $rows
     * @return list<array<string, mixed>>
     */
    public static function groups(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row->group_key ?? $row->dedupe_key;
            $groups[$key] ??= [
                'group_key' => $key,
                'event' => $row->event->value,
                'recipient' => [
                    'type' => $row->recipient_type,
                    'name' => $row->recipient_type === 'staff' ? $row->recipient?->name : null,
                ],
                'created_at' => $row->created_at?->toIso8601String(),
                'channels' => ['whatsapp' => null, 'email' => null, 'sms' => null],
            ];
            // The newest row per channel wins (a message is planned once per channel, so normally there is one).
            $groups[$key]['channels'][$row->channel->value] ??= self::message($row);
            if ($row->created_at && $row->created_at->toIso8601String() < $groups[$key]['created_at']) {
                $groups[$key]['created_at'] = $row->created_at->toIso8601String();
            }
        }

        return array_values($groups);
    }

    /**
     * Messages sent and estimated cost per channel for a Dhaka calendar month.
     *
     * @return array{month: string, channels: array<string, array{messages: int, parts: int, cost: float}>}
     */
    public static function monthlyCosts(CarbonInterface $monthInDhaka): array
    {
        $from = $monthInDhaka->copy()->startOfMonth()->utc();
        $to = $monthInDhaka->copy()->endOfMonth()->utc();
        $totals = NotificationMessage::query()->whereNotNull('sent_at')->whereBetween('sent_at', [$from, $to])
            ->selectRaw('channel, COUNT(*) AS messages, COALESCE(SUM(sms_parts), 0) AS parts, COALESCE(SUM(cost), 0) AS cost')
            ->groupBy('channel')->get()->keyBy(fn ($r) => $r->getRawOriginal('channel'));

        $channels = [];
        foreach (NotificationChannel::cases() as $channel) {
            $row = $totals->get($channel->value);
            $channels[$channel->value] = [
                'messages' => (int) ($row?->getRawOriginal('messages') ?? 0),
                'parts' => (int) ($row?->getRawOriginal('parts') ?? 0),
                'cost' => round((float) ($row?->getRawOriginal('cost') ?? 0), 2),
            ];
        }

        return ['month' => $monthInDhaka->format('Y-m'), 'channels' => $channels];
    }

    /** @return list<string> */
    public static function events(): array
    {
        return array_map(fn (NotificationEvent $e) => $e->value, NotificationEvent::templated());
    }

    public static function maskTokens(string $body): string
    {
        return (string) preg_replace('/#t=[A-Za-z0-9]+/', '#t=…', $body);
    }

    /** 8801711223344 → 01711•••344 */
    private static function maskPhone(string $phone): string
    {
        $local = (string) preg_replace('/^\+?88/', '', $phone);

        return strlen($local) >= 8 ? substr($local, 0, 5).'•••'.substr($local, -3) : $local;
    }
}
