<?php

namespace App\Services\Booking;

/** What a customer (or staff member) asked for, already validated. Prices are never part of it — only the total they saw. */
final class BookingRequest
{
    /**
     * @param  list<string>  $addonCodes
     * @param  list<array{name: string, passportNumber: ?string, dateOfBirth: ?string, passportExpiry: ?string, phone: ?string, email: ?string, passportScanToken?: ?string, ocrFilled?: bool}>  $travellers
     */
    public function __construct(
        public readonly string $packageSlug,
        /** Y-m-d. Null only for a custom service booked without a date (docs/custom-service-bookings.md). */
        public readonly ?string $travelDate,
        public readonly int $pax,
        public readonly string $room,
        public readonly array $addonCodes,
        public readonly array $travellers,
        public readonly int|float $expectedTotal,
        public readonly string $locale,
        public readonly string $source,
        public readonly bool $termsAccepted,
        /** For a package with a hotel-category price grid: '3', '4' or '5' (Phase 8 §4.D). */
        public readonly ?string $hotelCategory = null,
        /**
         * One per booking attempt, made by the website's form. The same key twice is the same booking — a second click
         * or a retry — so the database's unique index refuses the copy (2026-09-19: customers were booking twice).
         */
        public readonly ?string $idempotencyKey = null,
        /** A coupon code the customer applied (docs/coupons.md). Only the code: the discount is worked out on the server. */
        public readonly ?string $couponCode = null,
    ) {}
}
