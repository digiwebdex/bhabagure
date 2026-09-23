<?php

/*
 * One side of CouponConcurrencyTest, run as its own PHP process with its own database connection: reserves a coupon's
 * use for a booking the way BookingCreator and BookingQuoteEditor do — evaluate(lock: true), then reserve, in one
 * transaction. With a hold it keeps the transaction (and so the coupon's lock) open for that many milliseconds after
 * checking the uses, and writes "locked" to the ready file first, so the other side can try in the middle.
 *
 * Prints JSON: what happened ("reserved" or the refusal's reason), when it started checking and when it finished.
 *
 *   php tests/Fixtures/coupon-race.php <code> <booking id> <hold ms> <ready file or ->
 */

use App\Models\Booking;
use App\Services\Coupons\CouponCheck;
use App\Services\Coupons\CouponRefused;
use App\Services\Coupons\CouponService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $code, $bookingId, $holdMs, $ready] = $argv;
$coupons = app(CouponService::class);
$booking = Booking::query()->findOrFail((int) $bookingId);
$checking = microtime(true);

try {
    DB::transaction(function () use ($coupons, $booking, $code, $holdMs, $ready) {
        $offer = $coupons->evaluate($code, new CouponCheck(null, 150000, $booking->customer_id, null, []), lock: true);
        if ($ready !== '-') {
            file_put_contents($ready, 'locked');
        }
        usleep((int) $holdMs * 1000);
        $coupons->reserve($offer['coupon'], $booking, ['eligible' => 150000, 'discount' => $offer['discount'], 'originalTotal' => 153000, 'finalTotal' => 153000 - $offer['discount']], 'website');
    });
    $result = 'reserved';
} catch (CouponRefused $e) {
    $result = $e->reason;
}

echo json_encode(['result' => $result, 'checking' => $checking, 'done' => microtime(true)]);
