<?php

namespace App\Mail;

use App\Services\Customers\ProfileChanges;
use App\Support\Numerals;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The code that confirms a new email address for a portal account. */
class CustomerEmailCodeMail extends Mailable
{
    public function __construct(public readonly string $code, public readonly string $language) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->language === 'en' ? 'Confirm your email · Bhabaghure Holidays' : 'ইমেইল নিশ্চিত করুন · ভবঘুরে হলিডেজ');
    }

    public function content(): Content
    {
        $minutes = Numerals::number(ProfileChanges::EMAIL_MINUTES, $this->language);
        $body = $this->language === 'en'
            ? '<p>Your code to add this email address to your Bhabaghure Holidays account:</p><p style="font-size:24px;font-weight:700;letter-spacing:4px">'.e($this->code)."</p><p>It works for {$minutes} minutes. If you didn't ask for it, ignore this email.</p>"
            : '<p>ভবঘুরে হলিডেজ অ্যাকাউন্টে এই ইমেইল যোগ করার কোড:</p><p style="font-size:24px;font-weight:700;letter-spacing:4px">'.e($this->code)."</p><p>কোডটি {$minutes} মিনিট কাজ করবে। আপনি না চাইলে এই ইমেইলটি উপেক্ষা করুন।</p>";

        return new Content(htmlString: $body);
    }
}
