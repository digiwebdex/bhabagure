<?php

namespace App\Mail;

use App\Services\Customers\LoginCodes;
use App\Support\Numerals;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A one-time code by email, beside the SMS and WhatsApp copies (docs/booking-phone-verification.md §6): the portal's
 * sign-in code, or the code that confirms a website booking.
 */
class LoginCodeMail extends Mailable
{
    public function __construct(public readonly string $code, public readonly string $purpose, public readonly string $language) {}

    public function envelope(): Envelope
    {
        $booking = $this->purpose === LoginCodes::BOOKING;

        return new Envelope(subject: match (true) {
            $booking && $this->language === 'en' => "Your booking code: {$this->code} · Bhabaghure Holidays",
            $booking => "আপনার বুকিং কোড: {$this->code} · ভবঘুরে হলিডেজ",
            $this->language === 'en' => "Your sign-in code: {$this->code} · Bhabaghure Holidays",
            default => "আপনার লগইন কোড: {$this->code} · ভবঘুরে হলিডেজ",
        });
    }

    public function content(): Content
    {
        $booking = $this->purpose === LoginCodes::BOOKING;
        $minutes = Numerals::number(LoginCodes::MINUTES, $this->language);
        $code = '<p style="font-size:24px;font-weight:700;letter-spacing:4px">'.e($this->code).'</p>';
        $body = $this->language === 'en'
            ? '<p>'.($booking ? 'Your code to confirm your booking on bhabaghure.com.bd:' : 'Your code to sign in to your Bhabaghure Holidays account:')."</p>{$code}<p>It works for {$minutes} minutes. Never share it with anyone. If you didn't ask for it, ignore this email.</p>"
            : '<p>'.($booking ? 'bhabaghure.com.bd-এ আপনার বুকিং নিশ্চিত করার কোড:' : 'ভবঘুরে হলিডেজ অ্যাকাউন্টে লগইনের কোড:')."</p>{$code}<p>কোডটি {$minutes} মিনিট কাজ করবে। কাউকে জানাবেন না। আপনি না চাইলে এই ইমেইলটি উপেক্ষা করুন।</p>";

        return new Content(htmlString: $body);
    }
}
