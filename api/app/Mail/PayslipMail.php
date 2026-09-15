<?php

namespace App\Mail;

use App\Models\PayrollItem;
use App\Support\Numerals;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A finalised month's payslip, to the staff member's own email only, with the PDF attached. */
class PayslipMail extends Mailable
{
    public function __construct(public readonly PayrollItem $item, public readonly string $pdf, public readonly string $language) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->language === 'en' ? 'Payslip · ' : 'পে-স্লিপ · ').$this->monthLabel());
    }

    public function content(): Content
    {
        $name = e($this->item->staff->name);
        $month = e($this->monthLabel());
        $payable = e(Numerals::bdt($this->item->payable, $this->language));

        return new Content(htmlString: $this->language === 'en'
            ? "<p>Dear {$name},</p><p>Your payslip for {$month} is attached. Payable: <strong>{$payable}</strong>.</p><p>If anything in it looks wrong, tell HR.</p>"
            : "<p>প্রিয় {$name},</p><p>{$month}-এর পে-স্লিপ সংযুক্ত। প্রদেয়: <strong>{$payable}</strong>।</p><p>কিছু ভুল মনে হলে এইচআরকে জানান।</p>");
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $pdf = $this->pdf;

        return [Attachment::fromData(fn () => $pdf, "payslip-{$this->item->run->month->format('Y-m')}.pdf")->withMime('application/pdf')];
    }

    private function monthLabel(): string
    {
        return (string) preg_replace('/^\S+\s/u', '', Numerals::date($this->item->run->month->format('Y-m-d'), $this->language));
    }
}
