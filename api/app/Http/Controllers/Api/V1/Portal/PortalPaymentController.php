<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Enums\BookingStatus;
use App\Enums\TransactionDirection;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payments (docs/phase-6-customer-portal.md §2 #5, §3.4): paid and due from the ledger-derived booking amounts, and the
 * history from the cash book — the customer's payments, online payment charges, and reversals of either. Staff notes,
 * receipts and gateway fees stay in the admin.
 */
class PortalPaymentController extends Controller
{
    private const HISTORY_LIMIT = 200;

    public function index(Request $request): JsonResponse
    {
        $bookings = PortalTripController::bookingsOf(PortalTripController::customer($request))->get()->keyBy('id');
        $en = app()->getLocale() === 'en';

        $rows = Transaction::query()
            ->whereIn('booking_id', $bookings->keys())
            ->whereIn('category', [LedgerService::CATEGORY_PAYMENT, LedgerService::CATEGORY_ONLINE_CHARGE])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
        $reversed = Transaction::query()->whereIn('reverses_transaction_id', $rows->pluck('id'))->pluck('reverses_transaction_id')->flip();
        $open = $bookings->reject(fn (Booking $b) => $b->status === BookingStatus::Cancelled);

        return response()->json(['data' => [
            'paid' => Money::toNumber($bookings->sum(fn (Booking $b) => (float) $b->paid_amount)),
            'due' => Money::toNumber($open->sum(fn (Booking $b) => max(0, (float) $b->due_amount))),
            'bookings' => $bookings->count(),
            'openBookings' => $open->filter(fn (Booking $b) => (float) $b->due_amount > 0)->count(),
            'history' => $rows->map(function (Transaction $row) use ($bookings, $reversed, $en) {
                $booking = $bookings->get($row->booking_id);

                return [
                    'id' => $row->id,
                    'at' => $row->occurred_at->toIso8601String(),
                    'reference' => $booking->reference,
                    'title' => $en ? $booking->package_title_en : ($booking->package_title_bn ?: $booking->package_title_en),
                    'kind' => $row->reverses_transaction_id !== null ? 'reversal' : ($row->category === LedgerService::CATEGORY_ONLINE_CHARGE ? 'charge' : 'payment'),
                    'method' => $row->method,
                    'externalRef' => $row->external_ref,
                    'amount' => Money::toNumber($row->direction === TransactionDirection::In ? $row->amount : -1 * (float) $row->amount),
                    'reversed' => $reversed->has($row->id),
                ];
            })->all(),
        ]]);
    }
}
