<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBooking;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\NotificationMessage;
use App\Models\PackageDeparture;
use App\Models\Staff;
use App\Services\Booking\DepartureSeats;
use App\Services\Ledger\PaymentFigures;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /admin/dashboard (docs/phase-5-admin-core.md §4.2). Every number is computed now, in Dhaka time, from the same
 * rules as the screens it links to; a widget the staff member may not see is left out of the response, not zeroed.
 */
class DashboardController extends Controller
{
    private const DEPARTURE_WINDOW_DAYS = 30;

    private const PASSPORT_ALERT_DAYS = 7;

    public function __invoke(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        $now = now('Asia/Dhaka');
        $today = $now->toDateString();
        $month = $now->format('Y-m');
        $seesBookings = Booking::seesAll($staff) || Booking::seesOwn($staff);

        $departures = PackageDeparture::query()->where('status', 'scheduled')
            ->whereBetween('departs_on', [$today, $now->copy()->addDays(self::DEPARTURE_WINDOW_DAYS)->toDateString()])
            ->with('package:id,title_en,title_bn')->orderBy('departs_on')->orderBy('id')->get();
        $seats = $departures->filter(fn (PackageDeparture $d) => $d->seats_total !== null)->mapWithKeys(fn (PackageDeparture $d) => [$d->id => [
            'total' => (int) $d->seats_total, 'sold' => DepartureSeats::sold($d), 'held' => DepartureSeats::held($d), 'left' => DepartureSeats::available($d),
        ]]);

        $data = [
            'today' => $today,
            'month' => $month,
            'departures' => [
                'count' => $departures->count(),
                'next_date' => $departures->first()?->departs_on->toDateString(),
                'seats_left' => (int) $seats->sum('left'),
                'groups' => $seats->count(),
            ],
            'upcoming' => $departures->take(5)->map(fn (PackageDeparture $d) => [
                'id' => $d->id,
                'package_id' => $d->tour_package_id,
                'title_en' => $d->package?->title_en,
                'title_bn' => $d->package?->title_bn,
                'date' => $d->departs_on->toDateString(),
                'seats_total' => $seats[$d->id]['total'] ?? null,
                'booked' => isset($seats[$d->id]) ? $seats[$d->id]['sold'] + $seats[$d->id]['held'] : null,
                'seats_left' => $seats[$d->id]['left'] ?? null,
            ])->values(),
            'alerts' => [],
        ];

        if ($staff->can('payments.view')) {
            $data['collected'] = ['amount' => PaymentFigures::collected($month), 'invoiced' => PaymentFigures::invoiced($month)];
            $data['by_destination'] = PaymentFigures::collectedByDestination($month);
            $review = PaymentFigures::reviewQueue()->count();
            if ($review > 0) {
                $data['alerts'][] = ['kind' => 'payments_review', 'count' => $review, 'link' => '/payments'];
            }
        }

        if ($seesBookings) {
            $data['bookings'] = $this->bookingFigures($staff, $now);
            $data['passports_missing'] = $this->missingPassports($staff, $today)->count();
            $data['recent_bookings'] = Booking::query()->visibleTo($staff)->with(['customer', 'assignedStaff'])
                ->withExists(['invoices as has_invoice' => fn (Builder $q) => $q->where('status', 'issued'), 'transactions as has_payments'])
                ->latest('id')->limit(8)->get()->map(fn (Booking $booking) => AdminBooking::summary($booking))->values();

            // Trips within a week still missing passport numbers, one alert per booking.
            $soon = $this->missingPassports($staff, $today)
                ->whereHas('booking', fn (Builder $b) => $b->whereDate('travel_start', '<=', $now->copy()->addDays(self::PASSPORT_ALERT_DAYS)->toDateString()))
                ->with('booking:id,reference,travel_start,package_title_en,package_title_bn')->get()->groupBy('booking_id');
            foreach ($soon as $travellers) {
                $booking = $travellers->first()->booking;
                $data['alerts'][] = [
                    'kind' => 'passports_missing', 'count' => $travellers->count(), 'link' => "/bookings/{$booking->id}",
                    'reference' => $booking->reference, 'date' => $booking->travel_start->toDateString(),
                    'package_en' => $booking->package_title_en, 'package_bn' => $booking->package_title_bn,
                    'names' => $travellers->pluck('full_name')->take(3)->values(),
                ];
            }
        }

        if (Customer::seesOwn($staff)) {
            $new = fn () => Customer::query()->visibleTo($staff)->where('customers.stage', 'lead')->leadState('new');
            $data['leads'] = ['new' => $new()->count(), 'waiting_over_24h' => $new()->where('customers.created_at', '<=', now()->subDay())->count()];
            if ($data['leads']['waiting_over_24h'] > 0) {
                $data['alerts'][] = ['kind' => 'leads_unanswered', 'count' => $data['leads']['waiting_over_24h'], 'link' => '/customers?state=new'];
            }
        }

        if ($staff->can('notifications.manage')) {
            $failed = NotificationMessage::query()->where('status', NotificationStatus::Failed)->where('failed_at', '>=', now()->subDay())->count();
            if ($failed > 0) {
                $data['alerts'][] = ['kind' => 'notifications_failed', 'count' => $failed, 'link' => '/notifications'];
            }
            $session = NotificationSettings::sessionStatus();
            if (config('bhabaghure.notifications.whatsapp.mode') !== 'off' && $session['checkedAt'] !== null && $session['status'] !== 'connected') {
                $data['alerts'][] = ['kind' => 'whatsapp_disconnected', 'status' => $session['status'], 'checked_at' => $session['checkedAt'], 'link' => '/notifications'];
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Bookings confirmed this Dhaka month, against the same number of elapsed days last month, and those still waiting for
     * money (not cancelled, not fully paid).
     *
     * @return array{confirmed: int, previous: int, awaiting_payment: int}
     */
    private function bookingFigures(Staff $staff, Carbon $now): array
    {
        $start = $now->copy()->startOfMonth();
        $previousStart = $start->copy()->subMonthNoOverflow();
        // The same elapsed time into last month, capped at its end (31 March → 28 February).
        $previousEnd = $previousStart->copy()->add($start->diff($now));
        if ($previousEnd->greaterThan($previousStart->copy()->endOfMonth())) {
            $previousEnd = $previousStart->copy()->endOfMonth();
        }
        $confirmed = fn (Carbon $from, Carbon $to) => Booking::query()->visibleTo($staff)->whereNotNull('confirmed_at')
            ->whereBetween('confirmed_at', [$from->copy()->utc(), $to->copy()->utc()])->count();

        return [
            'confirmed' => $confirmed($start, $now),
            'previous' => $confirmed($previousStart, $previousEnd),
            'awaiting_payment' => Booking::query()->visibleTo($staff)->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Confirmed])
                ->where('payment_status', '!=', 'paid')->count(),
        ];
    }

    /** Travellers on the staff member's upcoming confirmed trips with no passport number — the documents-pending rule. */
    private function missingPassports(Staff $staff, string $today): Builder
    {
        return BookingTraveller::query()->whereNull('passport_number')
            ->whereHas('booking', fn (Builder $b) => $b->visibleTo($staff)->where('status', BookingStatus::Confirmed)->whereDate('travel_start', '>=', $today));
    }
}
