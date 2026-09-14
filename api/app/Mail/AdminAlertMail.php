<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A short operational alert to the staff who manage notifications (for example: WhatsApp is disconnected). */
class AdminAlertMail extends Mailable
{
    public function __construct(public readonly string $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bhabaghure: WhatsApp notifications need attention');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->alert).'</p><p>Admin → Notifications shows the connection and every waiting message.</p>');
    }
}
