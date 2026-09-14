<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one notifications row. Retries are rescheduled rows, not job retries, so they survive a worker restart. */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDelivery $delivery): void
    {
        $delivery->deliver($this->notificationId);
    }
}
