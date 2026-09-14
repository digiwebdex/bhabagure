<?php

namespace App\Services\Quotations;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PackageDeparture;
use App\Models\Quotation;
use App\Models\Staff;
use App\Models\TourPackage;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\AuditLogger;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\PriceChanged;
use App\Services\Booking\SeatsUnavailable;
use App\Services\Documents\DocumentNumbers;
use App\Services\Notifications\NotificationPlanner;
use App\Support\Money;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that changes a quotation (docs/phase-5-admin-core.md §4.5, staff-side lifecycle):
 *
 *   draft ──send──▶ sent ──accept──▶ accepted ──convert──▶ converted
 *                    │  └──────────────convert──────────────▶
 *                    ├──decline / withdraw (also from accepted)
 *                    └──revise──▶ a new draft pointing back; sending it withdraws the original
 *
 * A draft is priced with the website's pricing service and checked against the total the editor showed. From then on
 * the lines and amounts are frozen: sending, accepting and converting never re-price. "Expired" is a sent quotation
 * past valid_until in Dhaka; it can't be accepted or converted, only declined, withdrawn or revised. Every change is
 * audited, each under a row lock.
 */
final class QuotationService
{
    public function __construct(
        private readonly BookingCreator $bookings,
        private readonly DocumentNumbers $numbers,
        private readonly AuditLogger $audit,
        private readonly Ownership $ownership,
        private readonly NotificationPlanner $planner,
    ) {}

    /** @throws PriceChanged */
    public function create(QuotationInput $input, Customer $customer, Staff $staff): Quotation
    {
        return DB::transaction(function () use ($input, $customer, $staff) {
            if ($customer->assigned_staff_id === null) {
                try {
                    $this->ownership->claim($customer, $staff); // an unowned lead comes with the quotation, as with a booking
                } catch (OwnershipRefused) {
                    // Not a claimable lead (an existing customer): the quotation is still theirs.
                }
            }

            $quotation = new Quotation([
                'number' => $this->numbers->quotationNumber(),
                'customer_id' => $customer->id,
                'status' => Quotation::DRAFT,
                'share_token' => Str::random(40),
                'assigned_staff_id' => $staff->id,
                'created_by_staff_id' => $staff->id,
            ]);
            $this->price($quotation, $input);
            $this->audit->record('quotation.created', $staff, $quotation, ['total' => (float) $quotation->total_amount]);

            return $quotation->refresh();
        });
    }

    /** @throws PriceChanged|QuotationRefused */
    public function update(Quotation $quotation, QuotationInput $input, Staff $staff): Quotation
    {
        return DB::transaction(function () use ($quotation, $input, $staff) {
            $locked = $this->lock($quotation);
            if ($locked->status !== Quotation::DRAFT) {
                throw new QuotationRefused('not_draft');
            }
            $fields = ['tour_package_id', 'travel_date', 'pax_count', 'room_type', 'addons_amount', 'discount_amount', 'vat_rate', 'total_amount', 'validity_days'];
            $before = $locked->only($fields);
            $this->price($locked, $input);
            $this->audit->record('quotation.updated', $staff, $locked, ['before' => $before, 'after' => $locked->only($fields)]);

            return $locked->refresh();
        });
    }

    /**
     * To the customer: validity starts today, WhatsApp with the PDF and email with it attached. Sending a revision
     * withdraws the quotation it replaces, so only one offer is ever open.
     *
     * @throws QuotationRefused
     */
    public function send(Quotation $quotation, Staff $staff): Quotation
    {
        return DB::transaction(function () use ($quotation, $staff) {
            $locked = $this->lock($quotation);
            if ($locked->status !== Quotation::DRAFT) {
                throw new QuotationRefused('not_draft');
            }
            if ($locked->travel_date !== null && $locked->travel_date->toDateString() < self::today()) {
                throw new QuotationRefused('travel_date_past');
            }
            $original = $locked->revision_of_id ? Quotation::query()->whereKey($locked->revision_of_id)->lockForUpdate()->first() : null;
            if ($original?->status === Quotation::CONVERTED) {
                throw new QuotationRefused('original_converted');
            }

            $locked->forceFill(['status' => Quotation::SENT, 'sent_at' => now(), 'valid_until' => self::validUntil($locked->validity_days)])->save();
            if ($original !== null && in_array($original->status, [Quotation::SENT, Quotation::ACCEPTED], true)) {
                $original->forceFill(['status' => Quotation::WITHDRAWN, 'withdrawn_at' => now()])->save();
                $this->audit->record('quotation.withdrawn', $staff, $original, ['reason' => 'revised', 'revision' => $locked->number]);
            }
            $this->audit->record('quotation.sent', $staff, $locked, ['valid_until' => $locked->valid_until->toDateString(), 'total' => (float) $locked->total_amount]);
            $this->planner->quotationSent($locked);

            return $locked->refresh();
        });
    }

    /** @throws QuotationRefused */
    public function accept(Quotation $quotation, Staff $staff): Quotation
    {
        return $this->transition($quotation, $staff, 'quotation.accepted', function (Quotation $locked) {
            if ($locked->status !== Quotation::SENT) {
                throw new QuotationRefused('not_sent');
            }
            if ($locked->isExpired()) {
                throw new QuotationRefused('expired');
            }
            $locked->forceFill(['status' => Quotation::ACCEPTED, 'accepted_at' => now(), 'accepted_via' => 'staff']);
        });
    }

    /**
     * The customer accepted a valid quotation in the portal (docs/phase-6-customer-portal.md §3.2). Nothing is booked:
     * the owner is told and converts it, choosing the date and travellers with the customer.
     *
     * @throws QuotationRefused
     */
    public function acceptFromPortal(Quotation $quotation, Customer $customer): Quotation
    {
        return DB::transaction(function () use ($quotation, $customer) {
            $locked = $this->lock($quotation);
            if ($locked->status !== Quotation::SENT) {
                throw new QuotationRefused('not_sent');
            }
            if ($locked->isExpired()) {
                throw new QuotationRefused('expired');
            }
            $locked->forceFill(['status' => Quotation::ACCEPTED, 'accepted_at' => now(), 'accepted_via' => 'portal', 'viewed_at' => $locked->viewed_at ?? now()])->save();
            $this->audit->record('quotation.accepted', $customer, $locked, ['via' => 'portal']);
            $this->planner->quotationAccepted($locked);

            return $locked->refresh();
        });
    }

    /** The first time its customer opens a sent quotation in the portal. Staff previews never count. */
    public function markViewed(Quotation $quotation, Customer $customer): void
    {
        if ($quotation->status !== Quotation::SENT || $quotation->viewed_at !== null) {
            return;
        }
        if (Quotation::query()->whereKey($quotation->id)->whereNull('viewed_at')->update(['viewed_at' => now()]) === 1) {
            $this->audit->record('quotation.viewed', $customer, $quotation);
        }
    }

    /** @throws QuotationRefused */
    public function decline(Quotation $quotation, Staff $staff, ?string $reason): Quotation
    {
        return $this->transition($quotation, $staff, 'quotation.declined', function (Quotation $locked) {
            self::mustBeOffered($locked);
            $locked->forceFill(['status' => Quotation::DECLINED, 'declined_at' => now()]);
        }, ['reason' => $reason]);
    }

    /** Taken back by the office. A quote_sent message still waiting to go out is dropped when its turn comes. */
    public function withdraw(Quotation $quotation, Staff $staff, ?string $reason): Quotation
    {
        return $this->transition($quotation, $staff, 'quotation.withdrawn', function (Quotation $locked) {
            self::mustBeOffered($locked);
            $locked->forceFill(['status' => Quotation::WITHDRAWN, 'withdrawn_at' => now()]);
        }, ['reason' => $reason]);
    }

    /**
     * A new draft with a new number, pointing back to this quotation, starting from its frozen lines and amounts. Saving
     * the draft re-prices it. Asking again while that draft exists returns the same draft.
     *
     * @throws QuotationRefused
     */
    public function revise(Quotation $quotation, Staff $staff): Quotation
    {
        return DB::transaction(function () use ($quotation, $staff) {
            $locked = $this->lock($quotation);
            if ($locked->status === Quotation::DRAFT) {
                throw new QuotationRefused('revise_draft');
            }
            if ($locked->status === Quotation::CONVERTED) {
                throw new QuotationRefused('already_converted');
            }
            $existing = Quotation::query()->where('revision_of_id', $locked->id)->where('status', Quotation::DRAFT)->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }

            $revision = $locked->replicate(['number', 'status', 'sent_at', 'accepted_at', 'declined_at', 'withdrawn_at', 'converted_at', 'converted_booking_id', 'share_token', 'deleted_at']);
            $revision->forceFill([
                'number' => $this->numbers->quotationNumber(),
                'status' => Quotation::DRAFT,
                'revision_of_id' => $locked->id,
                'share_token' => Str::random(40),
                'created_by_staff_id' => $staff->id,
                'valid_until' => self::validUntil($locked->validity_days),
            ])->save();
            foreach ($locked->lines as $line) {
                $revision->lines()->create($line->only(['kind', 'code', 'title_en', 'title_bn', 'quantity', 'unit_price', 'amount', 'sort_order']));
            }
            $this->audit->record('quotation.revised', $staff, $revision, ['revision_of' => $locked->number]);

            return $revision->refresh();
        });
    }

    /**
     * An inquiry booking at the frozen price, owned by the quotation's owner. Converting twice returns the first
     * booking. A sent quotation must still be valid; an accepted one was accepted in time and keeps its price.
     *
     * @param  list<array<string, mixed>>  $travellers
     * @return array{booking: Booking, created: bool}
     *
     * @throws QuotationRefused|SeatsUnavailable
     */
    public function convert(Quotation $quotation, ?string $travelDate, array $travellers, Staff $staff): array
    {
        return DB::transaction(function () use ($quotation, $travelDate, $travellers, $staff) {
            $locked = $this->lock($quotation);
            if ($locked->status === Quotation::CONVERTED) {
                return ['booking' => Booking::query()->findOrFail($locked->converted_booking_id), 'created' => false];
            }
            if (! in_array($locked->status, [Quotation::SENT, Quotation::ACCEPTED], true)) {
                throw new QuotationRefused('not_convertible');
            }
            if ($locked->status === Quotation::SENT && $locked->isExpired()) {
                throw new QuotationRefused('expired');
            }
            $date = $travelDate ?? $locked->travel_date?->toDateString();
            if ($date === null) {
                throw new QuotationRefused('travel_date_required');
            }
            if ($date < self::today()) {
                throw new QuotationRefused('travel_date_past');
            }

            $booking = $this->bookings->createFromQuotation($locked, $date, $travellers, $staff)['booking'];
            $locked->forceFill(['status' => Quotation::CONVERTED, 'converted_at' => now(), 'converted_booking_id' => $booking->id])->save();
            $this->audit->record('quotation.converted', $staff, $locked, ['booking' => $booking->reference, 'travel_date' => $date]);

            return ['booking' => $booking, 'created' => true];
        });
    }

    /**
     * A booking converted from a quotation was deleted (a mistaken conversion): the quotation is accepted again and can
     * be converted once more. Runs inside the booking deletion's transaction.
     */
    public function bookingDeleted(Booking $booking, Staff $staff): void
    {
        if ($booking->quotation_id === null) {
            return;
        }
        $quotation = Quotation::query()->whereKey($booking->quotation_id)->lockForUpdate()->first();
        $booking->forceFill(['quotation_id' => null])->save();
        if ($quotation?->status !== Quotation::CONVERTED || $quotation->converted_booking_id !== $booking->id) {
            return;
        }
        $quotation->forceFill(['status' => Quotation::ACCEPTED, 'accepted_at' => $quotation->accepted_at ?? now(), 'converted_at' => null, 'converted_booking_id' => null])->save();
        $this->audit->record('quotation.conversion_undone', $staff, $quotation, ['booking' => $booking->reference]);
    }

    /** @throws QuotationRefused */
    public function delete(Quotation $quotation, Staff $staff): void
    {
        DB::transaction(function () use ($quotation, $staff) {
            $locked = $this->lock($quotation);
            if ($locked->status !== Quotation::DRAFT) {
                throw new QuotationRefused('delete_not_draft');
            }
            $locked->delete();
            $this->audit->record('quotation.deleted', $staff, $locked, ['number' => $locked->number]);
        });
    }

    /** The end of validity for a quotation sent today: the Dhaka date $days from now, honoured to its last minute. */
    public static function validUntil(int $days): string
    {
        return now('Asia/Dhaka')->addDays($days)->toDateString();
    }

    /**
     * Prices the editor's inputs with current package and add-on prices, then freezes them onto the quotation.
     *
     * @throws PriceChanged
     */
    private function price(Quotation $quotation, QuotationInput $input): void
    {
        $package = TourPackage::query()->published()->where('slug', $input->packageSlug)->firstOrFail();
        $addons = Addon::query()->where('is_active', true)->whereIn('code', $input->addonCodes)->orderBy('sort_order')->get();
        $listPrice = $this->bookings->listPrice($package);
        $quote = PricingService::quoteBooking(
            $listPrice, $input->pax, $input->room,
            $addons->map(fn (Addon $addon) => ['code' => $addon->code, 'price' => Money::toNumber($addon->price), 'unit' => $addon->unit])->all(),
            PricingConfig::current(), $input->discount, $input->vatRate,
        );
        if ((int) round($input->expectedTotal) !== $quote['total']) {
            throw new PriceChanged($quote);
        }
        $departure = $input->travelDate === null ? null : PackageDeparture::query()->where('tour_package_id', $package->id)
            ->where('status', 'scheduled')->whereDate('departs_on', $input->travelDate)->first();

        $quotation->fill([
            'tour_package_id' => $package->id,
            'departure_id' => $departure?->id,
            'package_title_en' => $package->title_en,
            'package_title_bn' => $package->title_bn,
            'package_code' => $package->code,
            'duration_days' => $package->duration_days,
            'duration_nights' => $package->duration_nights,
            'includes_airfare' => $package->includes_airfare,
            'travel_date' => $input->travelDate,
            'pax_count' => $input->pax,
            'room_type' => $input->room,
            'list_price' => $listPrice,
            'unit_price' => $quote['perPerson'],
            'subtotal_amount' => $quote['subtotal'],
            'single_supplement_amount' => $quote['singleSupplement'],
            'addons_amount' => array_sum(array_column($quote['addons'], 'amount')),
            'discount_amount' => $quote['discount'],
            'vat_rate' => $quote['chargePercent'],
            'vat_amount' => $quote['serviceCharge'],
            'total_amount' => $quote['total'],
            'validity_days' => $input->validityDays,
            // Provisional while a draft: sending starts the validity again from that day.
            'valid_until' => self::validUntil($input->validityDays),
            'locale' => $input->locale,
            'notes' => $input->notes,
        ])->save();

        $quotation->lines()->delete();
        foreach (BookingCreator::lineRows($package, $quote, $addons->all()) as $index => $line) {
            $quotation->lines()->create($line + ['sort_order' => $index]);
        }
    }

    /**
     * @param  Closure(Quotation): void  $change  checks the state and fills the new one (or throws QuotationRefused)
     * @param  array<string, mixed>  $details
     */
    private function transition(Quotation $quotation, Staff $staff, string $action, Closure $change, array $details = []): Quotation
    {
        return DB::transaction(function () use ($quotation, $staff, $action, $change, $details) {
            $locked = $this->lock($quotation);
            $change($locked);
            $locked->save();
            $this->audit->record($action, $staff, $locked, array_filter($details) ?: null);

            return $locked->refresh();
        });
    }

    /** Still before the customer: sent (expired or not) or accepted. */
    private static function mustBeOffered(Quotation $quotation): void
    {
        if (! in_array($quotation->status, [Quotation::SENT, Quotation::ACCEPTED], true)) {
            throw new QuotationRefused('not_offered');
        }
    }

    private function lock(Quotation $quotation): Quotation
    {
        return Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
    }

    private static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }
}
