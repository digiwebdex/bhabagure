<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\InquiryReceived;
use App\Events\PaymentRecorded;
use App\Services\Notifications\NotificationPlanner;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Domain events → planned messages. Planning never breaks the business action that triggered it: a booking or payment
 * is already committed when these run, so a notification problem is logged, not thrown back at the customer.
 */
final class PlanNotifications
{
    public function __construct(private readonly NotificationPlanner $planner) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            BookingCreated::class => 'onBookingCreated',
            BookingConfirmed::class => 'onBookingConfirmed',
            BookingCancelled::class => 'onBookingCancelled',
            PaymentRecorded::class => 'onPaymentRecorded',
            InquiryReceived::class => 'onInquiryReceived',
        ];
    }

    public function onBookingCreated(BookingCreated $event): void
    {
        $this->safely(fn () => $this->planner->bookingCreated($event->booking->fresh(), $event->privateLink), 'booking_created');
    }

    public function onBookingConfirmed(BookingConfirmed $event): void
    {
        $this->safely(fn () => $this->planner->bookingConfirmed($event->booking->fresh()), 'booking_confirmed');
    }

    public function onBookingCancelled(BookingCancelled $event): void
    {
        $this->safely(fn () => $this->planner->bookingCancelled($event->booking), 'booking_cancelled');
    }

    public function onPaymentRecorded(PaymentRecorded $event): void
    {
        if (! $event->confirmedBooking) {
            $this->safely(fn () => $this->planner->paymentReceived($event->booking->fresh(), $event->payment), 'payment_received');
        }
    }

    public function onInquiryReceived(InquiryReceived $event): void
    {
        $this->safely(fn () => $this->planner->leadReceived($event->inquiry), 'new_lead_alert');
    }

    private function safely(callable $plan, string $what): void
    {
        try {
            $plan();
        } catch (Throwable $e) {
            Log::error("Could not plan {$what} notifications", ['error' => $e->getMessage()]);
            report($e);
        }
    }
}
