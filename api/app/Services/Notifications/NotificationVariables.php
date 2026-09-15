<?php

namespace App\Services\Notifications;

use App\Enums\HotelCategory;
use App\Enums\InquiryType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\AttendanceDevice;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PackageDeparture;
use App\Models\Quotation;
use App\Models\SiteSetting;
use App\Models\StaffDocument;
use App\Models\SupportTicket;
use App\Services\Booking\DepartureSeats;
use App\Services\Invoices\InvoiceShortLink;
use App\Support\Numerals;
use App\Support\Payments\PaymentOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The values for an event's {{variables}}, in the recipient's language. Amounts and dates go through Numerals (the PHP
 * twin of the site's formatter), so a message says ৳ ১,৫৩,০০০ exactly as the website and invoice do — except SMS, which
 * says BDT ১,৫৩,০০০: "৳" is not in the GSM-7 alphabet and would make every English SMS Unicode (70 characters a part
 * instead of 160). That choice is made here and nowhere else. Phone numbers and references stay in Latin digits so
 * WhatsApp makes them tappable.
 */
final class NotificationVariables
{
    /**
     * @param  array<string, string|int|float>  $extra  values only the caller knows (the private booking link; a payment
     *                                                  `amount` as a number — it is formatted here, for the channel)
     * @return array<string, string>
     */
    public function for(NotificationEvent $event, object $related, string $locale, NotificationChannel $channel, array $extra = []): array
    {
        $currency = $channel === NotificationChannel::Sms ? 'code' : 'symbol';
        $money = fn (int|float|string $amount): string => Numerals::bdt($amount, $locale, currency: $currency);
        if (isset($extra['amount'])) {
            $extra['amount'] = $money($extra['amount']);
        }
        if (isset($extra['score'])) {
            $extra['score'] = Numerals::number($extra['score'], $locale);
        }
        // An offline device's reason is a code from the planner; the words are the recipient's language.
        $deviceReason = (string) ($extra['reason'] ?? '');
        if ($related instanceof AttendanceDevice) {
            unset($extra['reason']);
        }

        $values = match (true) {
            $related instanceof Booking => $this->booking($related, $locale, $money) + ['how_to_pay' => self::howToPay($related, $locale, $channel)],
            $related instanceof Inquiry => $this->inquiry($related, $locale),
            $related instanceof PackageDeparture => $this->departure($related, $locale),
            $related instanceof Quotation => $this->quotation($related, $locale, $money),
            $related instanceof SupportTicket => $this->supportTicket($related, $locale),
            $related instanceof StaffDocument => $this->staffDocument($related, $locale),
            $related instanceof AttendanceDevice => $this->attendanceDevice($related, $locale, $deviceReason),
            default => [],
        };

        return array_intersect_key($extra + $values + $this->company($locale), array_flip($event->variables()));
    }

    /**
     * {{how_to_pay}} (Phase 8 §4.F): the balance by bank transfer, payment link and bKash, one line each, or nothing when
     * nothing is due or set. The WhatsApp leaves the link out: the first WhatsApp to a customer carries no link
     * (WaSenderAPI's anti-ban guidance); the email has it.
     */
    private static function howToPay(Booking $booking, string $locale, NotificationChannel $channel): string
    {
        $due = (float) $booking->due_amount;
        $checkout = $channel === NotificationChannel::WhatsApp ? true : null;
        $lines = PaymentOptions::lines($due, $locale, $checkout);
        if ($lines === []) {
            return '';
        }
        $heading = $locale === 'en' ? "How to pay (write {$booking->reference} as the reference):" : "পেমেন্টের উপায় (রেফারেন্সে {$booking->reference} লিখুন):";

        return $heading."\n".implode("\n", array_map(fn (string $line) => "• {$line}", $lines));
    }

    /**
     * @param  callable(int|float|string): string  $money
     * @return array<string, string>
     */
    private function booking(Booking $booking, string $locale, callable $money): array
    {
        $booking->loadMissing(['customer', 'travellers', 'departure.groupLeader']);
        $lead = $booking->travellers->firstWhere('is_lead', true);
        $invoice = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first();
        $missing = $booking->travellers->filter(fn (BookingTraveller $t) => blank($t->passport_number))->pluck('full_name');

        return [
            'name' => $lead?->full_name ?? $booking->customer->name,
            'package' => $locale === 'en' ? $booking->package_title_en : ($booking->package_title_bn ?: $booking->package_title_en),
            'ref' => $booking->reference,
            'date' => $booking->travel_start ? Numerals::date($booking->travel_start->toDateString(), $locale) : '—',
            'total' => $money($booking->total_amount),
            'paid' => $money($booking->paid_amount),
            'due' => $money($booking->due_amount),
            'invoice' => $invoice?->invoice_number ?? '',
            'link' => $invoice ? url("/api/v1/public/invoices/{$invoice->share_token}") : '',
            // For SMS: the full share link alone would take most of a Bangla SMS part.
            'short_link' => $invoice ? InvoiceShortLink::url($invoice) : '',
            'travellers' => $missing->implode(', '),
            'leader' => $booking->departure?->groupLeader?->name ?? ($locale === 'en' ? 'our team' : 'আমাদের টিম'),
            'pax' => Numerals::number($booking->pax_count, $locale),
            'payment' => match ($booking->payment_status) {
                'paid' => $locale === 'en' ? 'paid' : 'পরিশোধিত',
                'partial' => $locale === 'en' ? 'part paid' : 'আংশিক পরিশোধিত',
                default => $locale === 'en' ? 'unpaid' : 'অপরিশোধিত',
            },
            'customer' => $booking->customer->name,
            'phone' => self::displayPhone($lead?->phone ?: $booking->customer->phone),
        ];
    }

    /**
     * @param  callable(int|float|string): string  $money
     * @return array<string, string>
     */
    private function quotation(Quotation $quotation, string $locale, callable $money): array
    {
        $quotation->loadMissing('customer');

        return [
            'name' => $quotation->customer->name,
            'package' => $locale === 'en' ? $quotation->package_title_en : ($quotation->package_title_bn ?: $quotation->package_title_en),
            'number' => $quotation->number,
            'date' => $quotation->travel_date ? Numerals::date($quotation->travel_date->toDateString(), $locale) : ($locale === 'en' ? 'date to be fixed' : 'তারিখ পরে ঠিক হবে'),
            'pax' => Numerals::number($quotation->pax_count, $locale),
            'total' => $money($quotation->total_amount),
            'valid_until' => Numerals::date($quotation->valid_until->toDateString(), $locale),
            'customer' => $quotation->customer->name,
            'phone' => self::displayPhone($quotation->customer->phone),
            'link' => url("/api/v1/public/quotations/{$quotation->share_token}".($locale === 'en' ? '?lang=en' : '')),
        ];
    }

    /** @return array<string, string> */
    private function supportTicket(SupportTicket $ticket, string $locale): array
    {
        $ticket->loadMissing(['customer', 'booking']);

        return [
            'name' => $ticket->customer->name,
            'customer' => $ticket->customer->name,
            'phone' => self::displayPhone($ticket->customer->phone),
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'ref' => $ticket->booking?->reference ?? '—',
            'link' => rtrim((string) config('bhabaghure.portal_url'), '/').($locale === 'en' ? '/en' : '')."/support/{$ticket->number}",
        ];
    }

    /** @return array<string, string> the document's name, its owner and when it expires — never its number */
    private function staffDocument(StaffDocument $document, string $locale): array
    {
        $document->loadMissing('staff');
        $en = $locale === 'en';
        $type = self::STAFF_DOCUMENT_TYPES[$document->type][$en ? 1 : 0] ?? $document->type;
        $days = $document->expires_on === null ? null
            : (int) StaffDocument::today()->diffInDays(CarbonImmutable::parse($document->expires_on->toDateString(), 'Asia/Dhaka'), false);

        return [
            'staff' => $document->staff->name,
            'document' => filled($document->title) ? "{$type} · {$document->title}" : $type,
            'expires' => $document->expires_on === null ? '—' : Numerals::date($document->expires_on->toDateString(), $locale),
            'days' => match (true) {
                $days === null => '—',
                $days === 0 => $en ? 'today' : 'আজ',
                $days > 0 => $en ? 'in '.Numerals::number($days, $locale).' '.Str::plural('day', $days) : Numerals::number($days, $locale).' দিন পর',
                default => $en ? Numerals::number(-$days, $locale).' '.Str::plural('day', -$days).' ago' : Numerals::number(-$days, $locale).' দিন আগে',
            },
            'link' => rtrim((string) config('bhabaghure.admin_url'), '/').'/vault?status=attention',
        ];
    }

    /**
     * @param  string  $reason  pc_silent: the office PC stopped checking in; device_unreachable: it can't reach the device
     * @return array<string, string>
     */
    private function attendanceDevice(AttendanceDevice $device, string $locale, string $reason): array
    {
        $en = $locale === 'en';
        $since = $device->last_pull_ok_at?->timezone('Asia/Dhaka');

        return [
            'device' => $device->name,
            'since' => $since === null ? ($en ? 'never' : 'কখনও নয়') : Numerals::date($since->toDateString(), $locale).', '.Numerals::localizeDigits($since->format('H:i'), $locale),
            'reason' => $reason === 'pc_silent'
                ? ($en ? 'the office PC that reads it has stopped checking in (is it switched on and online?)' : 'যে অফিস পিসি এটি পড়ে সেটি সাড়া দিচ্ছে না (পিসি চালু ও ইন্টারনেটে আছে কি?)')
                : ($en ? 'the office PC can’t reach the device (power, cable or network)' : 'অফিস পিসি ডিভাইসে পৌঁছাতে পারছে না (বিদ্যুৎ, ক্যাবল বা নেটওয়ার্ক)'),
            'link' => rtrim((string) config('bhabaghure.admin_url'), '/').'/attendance',
        ];
    }

    /** Staff document types as the alert names them: [bn, en]. The admin's own labels live in its i18n files. */
    private const STAFF_DOCUMENT_TYPES = [
        'passport' => ['পাসপোর্ট', 'Passport'],
        'nid' => ['জাতীয় পরিচয়পত্র', 'NID'],
        'driving_licence' => ['ড্রাইভিং লাইসেন্স', 'Driving licence'],
        'cv' => ['সিভি', 'CV'],
        'appointment_letter' => ['নিয়োগপত্র', 'Appointment letter'],
        'contract' => ['চুক্তিপত্র', 'Contract'],
        'certificate' => ['সনদ', 'Certificate'],
        'photo' => ['ছবি', 'Photo'],
        'other' => ['অন্যান্য', 'Other'],
    ];

    /** @return array<string, string> */
    private function inquiry(Inquiry $inquiry, string $locale): array
    {
        $details = $inquiry->details ?? [];
        $en = $locale === 'en';
        $summary = match ($inquiry->type) {
            InquiryType::AirQuote => sprintf('%s → %s, %s%s, %s × %s', $details['from'] ?? '?', $details['to'] ?? '?', $details['departOn'] ?? '?',
                empty($details['returnOn']) ? '' : ' – '.$details['returnOn'], $inquiry->pax ?? 1, $details['cabinClass'] ?? 'economy'),
            InquiryType::HotelQuote => sprintf('%s, %s – %s', $details['location'] ?? '?', $details['checkIn'] ?? '?', $details['checkOut'] ?? '?'),
            InquiryType::Contact => Str::limit((string) ($details['message'] ?? ''), 300),
        };
        $values = [
            'name' => $inquiry->name,
            'phone' => self::displayPhone($inquiry->phone),
            'kind' => match ($inquiry->type) {
                InquiryType::AirQuote => $en ? 'Air ticket quote' : 'এয়ার টিকেট কোটেশন',
                InquiryType::HotelQuote => $en ? 'Hotel quotation' : 'হোটেল কোটেশন',
                InquiryType::Contact => $en ? 'Contact form' : 'যোগাযোগ ফর্ম',
            },
            'details' => $summary !== '' ? $summary : '—',
            // What the customer asked for, as a reply names it: "your Dhaka → Bangkok air ticket request".
            'request' => match ($inquiry->type) {
                InquiryType::AirQuote => trim(sprintf('%s → %s %s', $details['from'] ?? '', $details['to'] ?? '', $en ? 'air ticket' : 'এয়ার টিকেট')),
                InquiryType::HotelQuote => trim(sprintf('%s %s', $details['location'] ?? '', $en ? 'hotel' : 'হোটেল')),
                InquiryType::Contact => $en ? 'enquiry' : 'জিজ্ঞাসা',
            },
        ];
        if ($inquiry->type !== InquiryType::HotelQuote) {
            return $values;
        }

        $checkIn = $details['checkIn'] ?? null;
        $checkOut = $details['checkOut'] ?? null;
        $nights = $checkIn && $checkOut ? (int) CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut)) : null;
        $guests = (int) ($inquiry->pax ?? 1);

        return $values + [
            'location' => (string) ($details['location'] ?? '—'),
            'check_in' => $checkIn ? Numerals::date($checkIn, $locale) : '—',
            'check_out' => $checkOut ? Numerals::date($checkOut, $locale) : '—',
            'nights' => $nights === null ? '—' : ($en ? Numerals::number($nights, $locale).' '.($nights === 1 ? 'night' : 'nights') : Numerals::number($nights, $locale).' রাত'),
            'category' => HotelCategory::tryFrom((string) ($details['hotelCategory'] ?? ''))?->label($locale) ?? '—',
            'guests' => $en ? Numerals::number($guests, $locale).' '.($guests === 1 ? 'guest' : 'guests') : Numerals::number($guests, $locale).' জন',
            'note' => filled($details['note'] ?? null) ? Str::limit((string) $details['note'], 500) : '—',
            'link' => rtrim((string) config('bhabaghure.admin_url'), '/').'/hotel-requests',
        ];
    }

    /** @return array<string, string> */
    private function departure(PackageDeparture $departure, string $locale): array
    {
        $departure->loadMissing('package');

        return [
            'package' => $locale === 'en' ? $departure->package->title_en : ($departure->package->title_bn ?: $departure->package->title_en),
            'date' => Numerals::date($departure->departs_on->toDateString(), $locale),
            'seats' => Numerals::number(DepartureSeats::available($departure), $locale),
        ];
    }

    /** @return array<string, string> */
    private function company(string $locale): array
    {
        $contact = SiteSetting::get('contact', []);

        return [
            'office' => self::displayPhone($contact['phone'] ?? ''),
            'review' => (string) ($contact['facebook'] ?? $contact['website'] ?? ''),
        ];
    }

    /** +8801743939300 / 8801743939300 → 01743939300 */
    public static function displayPhone(?string $phone): string
    {
        return (string) preg_replace('/^\+?88/', '', (string) $phone);
    }
}
