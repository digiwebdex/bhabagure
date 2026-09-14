<?php

namespace App\Services\Portal;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\NpsResponse;
use App\Models\SiteSetting;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationPlanner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * NPS after a trip (docs/phase-6-customer-portal.md §0.4): one question about the most recent completed trip. 9–10 are
 * offered the public review link; 0–6 put a follow-up on the customer's contact log and alert the trip's owner.
 */
final class NpsSurvey
{
    /** Trips that ended longer ago than this aren't asked about any more. */
    public const ASK_WITHIN_DAYS = 180;

    public function __construct(private readonly AuditLogger $audit, private readonly NotificationPlanner $planner) {}

    /** The completed trip to ask about, if any. */
    public static function pending(Customer $customer): ?Booking
    {
        return Booking::query()->where('customer_id', $customer->id)->where('status', BookingStatus::Completed)
            ->whereDate('travel_end', '>=', now('Asia/Dhaka')->subDays(self::ASK_WITHIN_DAYS)->toDateString())
            ->whereDoesntHave('npsResponse')
            ->latest('travel_end')->latest('id')->first();
    }

    /** Where a promoter is sent: the page the Phase 4 review message links to. */
    public static function reviewUrl(): ?string
    {
        $contact = SiteSetting::get('contact', []);

        return filled($contact['facebook'] ?? null) ? (string) $contact['facebook'] : (filled($contact['website'] ?? null) ? (string) $contact['website'] : null);
    }

    /** @return NpsResponse|null null when this trip was already answered */
    public function record(Booking $booking, Customer $customer, int $score, ?string $comment): ?NpsResponse
    {
        try {
            return DB::transaction(function () use ($booking, $customer, $score, $comment) {
                $response = NpsResponse::query()->create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'score' => $score, 'comment' => $comment]);
                $this->audit->record('nps.answered', $customer, $booking, ['score' => $score]);

                if ($score <= NpsResponse::DETRACTOR_UP_TO) {
                    // A system entry on the contact log, due now: whoever owns the customer sees it as a follow-up.
                    CustomerContact::query()->create([
                        'customer_id' => $customer->id, 'staff_id' => null, 'channel' => CustomerContact::PORTAL, 'outcome' => CustomerContact::NPS_DETRACTOR,
                        'note' => trim("NPS {$score}/10 · {$booking->reference}".($comment ? " — {$comment}" : '')),
                        'next_follow_up_at' => now(), 'occurred_at' => now(),
                    ]);
                    $this->planner->npsFollowUp($response);
                }

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
