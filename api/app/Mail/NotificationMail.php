<?php

namespace App\Mail;

use App\Models\NotificationMessage;
use App\Models\SiteSetting;
use App\Models\Staff;
use App\Services\Notifications\MessageRenderer;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\NotificationVariables;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email copy of a notification — the channel that keeps working if WhatsApp doesn't. Sent from the company's own
 * domain, it also tells customers which WhatsApp number their automated messages come from, so they can check a
 * message before trusting it.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  ?string  $pdf  the invoice or quotation PDF, attached as $filename */
    public function __construct(
        public readonly NotificationMessage $notification,
        public readonly ?string $filename = null,
        public readonly ?string $pdf = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title ?: MessageRenderer::senderLine());
    }

    public function content(): Content
    {
        $locale = $this->notification->locale === 'en' ? 'en' : 'bn';
        $contact = SiteSetting::get('contact', []);

        return new Content(
            html: 'mail.notification',
            text: 'mail.notification-text',
            with: [
                'locale' => $locale,
                'senderLine' => MessageRenderer::senderLine(),
                'lines' => preg_split('/\R/u', $this->notification->body) ?: [],
                'toCustomer' => $this->notification->recipient_type !== (new Staff)->getMorphClass(),
                'notificationsNumber' => ($n = NotificationSettings::notificationsNumber()) ? NotificationVariables::displayPhone($n) : null,
                'mainNumber' => ($m = NotificationSettings::mainNumber()) ? NotificationVariables::displayPhone($m) : null,
                'email' => $contact['email'] ?? null,
                'website' => $contact['website'] ?? null,
                'address' => SiteSetting::get('address'),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->filename !== null && $this->pdf !== null
            ? [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')]
            : [];
    }
}
