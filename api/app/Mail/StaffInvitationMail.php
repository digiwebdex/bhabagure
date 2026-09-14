<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use App\Services\Hr\StaffInvitations;
use App\Support\Numerals;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A link to set a staff password: the invitation to a new admin account, or a reset of a forgotten password. */
class StaffInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $purpose,
        public readonly string $language,
    ) {}

    public function envelope(): Envelope
    {
        $invite = $this->purpose === StaffInvitation::INVITE;

        return new Envelope(subject: $this->language === 'en'
            ? ($invite ? 'Your Bhabaghure Holidays admin account' : 'Reset your Bhabaghure Holidays admin password')
            : ($invite ? 'ভবঘুরে হলিডেজ অ্যাডমিন অ্যাকাউন্ট' : 'ভবঘুরে হলিডেজ অ্যাডমিন পাসওয়ার্ড রিসেট'));
    }

    public function content(): Content
    {
        $invite = $this->purpose === StaffInvitation::INVITE;
        $name = e($this->name);
        $link = '<p><a href="'.e($this->url).'" style="display:inline-block;padding:10px 18px;border-radius:10px;background:#0B5ED7;color:#fff;text-decoration:none;font-weight:600">';

        if ($this->language === 'en') {
            $valid = $invite ? Numerals::number(StaffInvitations::INVITE_HOURS, 'en').' hours' : Numerals::number(StaffInvitations::RESET_MINUTES, 'en').' minutes';
            $body = $invite
                ? "<p>Dear {$name},</p><p>An account has been made for you in the Bhabaghure Holidays admin. Choose your password to sign in:</p>{$link}Set my password</a></p><p>The link works once, for {$valid}. If you weren't expecting this, ignore this email.</p>"
                : "<p>Dear {$name},</p><p>Someone asked to reset your Bhabaghure Holidays admin password. Choose a new one here:</p>{$link}Choose a new password</a></p><p>The link works once, for {$valid}, and signs you out everywhere else. If you didn't ask for it, tell your admin.</p>";
        } else {
            $valid = $invite ? Numerals::number(StaffInvitations::INVITE_HOURS, 'bn').' ঘণ্টা' : Numerals::number(StaffInvitations::RESET_MINUTES, 'bn').' মিনিট';
            $body = $invite
                ? "<p>প্রিয় {$name},</p><p>ভবঘুরে হলিডেজ অ্যাডমিনে আপনার জন্য একটি অ্যাকাউন্ট তৈরি হয়েছে। সাইন ইন করতে পাসওয়ার্ড ঠিক করুন:</p>{$link}পাসওয়ার্ড ঠিক করুন</a></p><p>লিংকটি একবারই কাজ করবে, {$valid} পর্যন্ত। আপনি এটি আশা না করলে ইমেইলটি উপেক্ষা করুন।</p>"
                : "<p>প্রিয় {$name},</p><p>আপনার ভবঘুরে হলিডেজ অ্যাডমিন পাসওয়ার্ড রিসেটের অনুরোধ এসেছে। নতুন পাসওয়ার্ড এখানে ঠিক করুন:</p>{$link}নতুন পাসওয়ার্ড</a></p><p>লিংকটি একবারই কাজ করবে, {$valid} পর্যন্ত, এবং অন্য সব ডিভাইস থেকে সাইন আউট করবে। আপনি অনুরোধ না করলে অ্যাডমিনকে জানান।</p>";
        }

        return new Content(htmlString: $body);
    }
}
