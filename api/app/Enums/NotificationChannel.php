<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    /** A fallback for money-critical messages WhatsApp couldn't deliver, and the departure-day message (phase-4 doc §10). */
    case Sms = 'sms';
}
