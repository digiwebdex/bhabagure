<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationPlanner;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * WaSender's send response gives its own message id; delivery webhooks carry WhatsApp's. Asks WaSender for the
 * WhatsApp id a little after sending, so "delivered" and "read" can be matched to the message. It also reads the
 * status: WaSender accepts a message for a number without WhatsApp and fails it afterwards, so if the failure webhook
 * never arrives, this is where a money-critical message still falls back to SMS. Still pending: asked again later.
 */
class LinkWhatsAppMessageId implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const PENDING = 1;

    private const ERROR = 0;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(WhatsAppGateway $gateway, NotificationPlanner $planner): void
    {
        $row = NotificationMessage::query()->find($this->notificationId);
        if (! $row || $row->provider_message_id === null || $row->status !== NotificationStatus::Sent) {
            return;
        }
        $info = $gateway->messageInfo($row->provider_message_id);
        if ($info === null) {
            return;
        }

        if ($info['whatsapp_id'] !== null && $row->provider_whatsapp_id === null) {
            $row->forceFill(['provider_whatsapp_id' => $info['whatsapp_id']])->save();
        }
        if ($info['status'] === self::ERROR) {
            $row->forceFill(['status' => NotificationStatus::Failed, 'failed_at' => now(), 'last_error' => 'whatsapp_error'])->save();
            $planner->smsFallback($row);

            return;
        }
        if ($info['status'] === self::PENDING && $this->attempts() < $this->tries) {
            $this->release(240);
        }
    }
}
