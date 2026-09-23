<?php

namespace App\Services\Coupons;

use App\Support\Numerals;
use Illuminate\Support\Carbon;
use RuntimeException;

/** A coupon that can't be used for this booking, and why (docs/coupons.md §2.3). */
final class CouponRefused extends RuntimeException
{
    /**
     * @param  string  $reason  not_found · inactive · not_started · expired · package · min_amount · passport · used_up ·
     *                          customer_limit · already_applied · below_minimum
     * @param  array{date?: Carbon, amount?: int|float}  $details  for the sentence the customer reads
     */
    public function __construct(public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct("Coupon refused: {$reason}");
    }

    /**
     * What the customer (or staff member) reads, in their language: dates in Dhaka time, amounts in taka — "৳" on the
     * website, "BDT" on staff screens.
     *
     * @param  'symbol'|'code'  $currency
     */
    public function reasonText(?string $locale = null, string $currency = 'symbol'): string
    {
        $locale = $locale === 'bn' || ($locale === null && app()->getLocale() === 'bn') ? 'bn' : 'en';
        $replace = [];
        if (isset($this->details['date'])) {
            $at = $this->details['date']->copy()->setTimezone('Asia/Dhaka');
            $time = $at->format('H:i');
            // The time only when it says something: a coupon that starts at 10:00 is not usable at 09:00 that day.
            $replace['date'] = Numerals::date($at->toDateString(), $locale).(in_array($time, ['00:00', '23:59'], true) ? '' : ', '.Numerals::localizeDigits($time, $locale));
        }
        if (isset($this->details['amount'])) {
            $replace['amount'] = Numerals::bdt($this->details['amount'], $locale, currency: $currency);
        }

        return __("coupons.refused.{$this->reason}", $replace, $locale);
    }
}
