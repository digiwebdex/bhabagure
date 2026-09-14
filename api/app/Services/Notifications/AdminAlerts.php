<?php

namespace App\Services\Notifications;

use App\Mail\AdminAlertMail;
use App\Models\Staff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Emails the staff who manage notifications about a problem — at most once an hour per problem. */
final class AdminAlerts
{
    public const WHATSAPP_SESSION = 'whatsapp-session';

    public static function once(string $key, string $message): void
    {
        if (! Cache::add("bhabaghure:notification-alert:{$key}", true, 3600)) {
            return;
        }
        Log::error($message);

        Staff::query()->where('status', 'active')->get()
            ->filter(fn (Staff $staff) => $staff->can('notifications.manage'))
            ->each(function (Staff $staff) use ($message) {
                try {
                    Mail::to($staff->email)->send(new AdminAlertMail($message));
                } catch (Throwable) {
                    // Logged above.
                }
            });
    }

    /** The WhatsApp session isn't connected (or couldn't be checked): messages wait, email carries on. */
    public static function whatsAppSession(string $status): void
    {
        self::once(self::WHATSAPP_SESSION, "WhatsApp session status: {$status}. Automated WhatsApp messages are waiting until it is connected again — re-scan the QR code in the WaSender dashboard. Emails are still going out.");
    }
}
