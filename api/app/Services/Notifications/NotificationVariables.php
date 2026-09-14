<?php

namespace App\Services\Notifications;

use App\Enums\InquiryType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\PackageDeparture;
use App\Models\SiteSetting;
use App\Services\Booking\DepartureSeats;
use App\Services\Invoices\InvoiceShortLink;
use App\Support\Numerals;
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

        $values = match (true) {
            $related instanceof Booking => $this->booking($related, $locale, $money),
            $related instanceof Inquiry => $this->inquiry($related, $locale),
            $related instanceof PackageDeparture => $this->departure($related, $locale),
            default => [],
        };

        return array_intersect_key($extra + $values + $this->company($locale), array_flip($event->variables()));
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

    /** @return array<string, string> */
    private function inquiry(Inquiry $inquiry, string $locale): array
    {
        $details = $inquiry->details ?? [];
        $summary = $inquiry->type === InquiryType::AirQuote
            ? sprintf('%s → %s, %s%s, %s × %s', $details['from'] ?? '?', $details['to'] ?? '?', $details['departOn'] ?? '?',
                empty($details['returnOn']) ? '' : ' – '.$details['returnOn'], $inquiry->pax ?? 1, $details['cabinClass'] ?? 'economy')
            : Str::limit((string) ($details['message'] ?? ''), 300);

        return [
            'name' => $inquiry->name,
            'phone' => self::displayPhone($inquiry->phone),
            'kind' => $inquiry->type === InquiryType::AirQuote
                ? ($locale === 'en' ? 'Air ticket quote' : 'এয়ার টিকেট কোটেশন')
                : ($locale === 'en' ? 'Contact form' : 'যোগাযোগ ফর্ম'),
            'details' => $summary !== '' ? $summary : '—',
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
