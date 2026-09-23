<?php

// Coupons (docs/coupons.md §2.3): why a code can't be used, as the customer (or staff member) reads it.
return [
    'refused' => [
        'not_found' => 'This coupon code isn\'t valid. Check it and try again.',
        'inactive' => 'This coupon isn\'t active.',
        'not_started' => 'This coupon can be used from :date.',
        'expired' => 'This coupon expired on :date.',
        'package' => 'This coupon doesn\'t apply to this package.',
        'min_amount' => 'This coupon needs a booking of at least :amount.',
        'passport' => 'This coupon is for a particular passport holder. Enter that traveller\'s passport number in the travellers\' details.',
        'used_up' => 'This coupon has been used up.',
        'customer_limit' => 'You have already used this coupon.',
        'already_applied' => 'This booking already has a coupon. Remove it before applying another.',
        'below_minimum' => 'This booking\'s coupon needs at least :amount before discounts. Remove the coupon first, or keep the booking at :amount or more.',
    ],
    'applied' => 'Coupon applied — you save :amount.',
];
