<?php

namespace App\Services\Coupons;

/** What a coupon is checked against (docs/coupons.md §2.3): the booking as it would be, and who is booking. */
final class CouponCheck
{
    /**
     * @param  list<string>  $passportHashes  BookingTraveller::passportHash of every passport number on the booking
     */
    public function __construct(
        /** The package booked; null for a custom service. */
        public readonly ?int $packageId,
        /** Every line before any discount and before the service charge, in whole taka (docs/coupons.md §2.2). */
        public readonly int $eligibleAmount,
        /** The customer record, when known (a signed-in customer, or an existing booking's). */
        public readonly ?int $customerId,
        /** Otherwise the lead traveller's number, which finds their record if they have booked before. */
        public readonly ?string $phone,
        public readonly array $passportHashes,
    ) {}
}
