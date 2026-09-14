<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Quotation;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Customers and leads for the admin (docs/phase-5-admin-core.md §4.4). Passport numbers never leave the API here. */
final class AdminCustomer
{
    /** Months of validity a passport needs before it counts as "on file" rather than "expiring". */
    private const PASSPORT_MONTHS = 6;

    /** The facts every row shows, computed in the query (no N+1). */
    public static function withRowFacts(Builder $query): Builder
    {
        $today = now('Asia/Dhaka')->toDateString();

        return $query->withLeadFacts()->withExists('quotations as has_quotations')->with('assignedStaff')
            ->withCount(['bookings as trips_completed' => fn (Builder $b) => $b->where('status', 'completed')])
            ->addSelect([
                'last_contact_at' => CustomerContact::query()->select('occurred_at')->whereColumn('customer_id', 'customers.id')->orderByDesc('occurred_at')->orderByDesc('id')->limit(1),
                'next_follow_up_at' => CustomerContact::query()->select('next_follow_up_at')->whereColumn('customer_id', 'customers.id')->orderByDesc('occurred_at')->orderByDesc('id')->limit(1),
                'next_trip_id' => Booking::query()->select('id')->whereColumn('customer_id', 'customers.id')->where('status', 'confirmed')->whereDate('travel_start', '>=', $today)->orderBy('travel_start')->limit(1),
                'next_trip_start' => Booking::query()->select('travel_start')->whereColumn('customer_id', 'customers.id')->where('status', 'confirmed')->whereDate('travel_start', '>=', $today)->orderBy('travel_start')->limit(1),
                // Only whether a passport is on file and until when — the number itself stays encrypted and unread.
                'passport_valid_until' => BookingTraveller::query()->select('passport_expiry')->whereColumn('customer_id', 'customers.id')->whereNotNull('passport_number')->orderByDesc('passport_expiry')->limit(1),
            ]);
    }

    /** @return array<string, mixed> */
    public static function row(Customer $customer, Staff $viewer): array
    {
        $now = now();
        $followUp = $customer->next_follow_up_at ? Carbon::parse($customer->next_follow_up_at) : null;
        $validUntil = $customer->passport_valid_until ? Carbon::parse($customer->passport_valid_until) : null;
        $works = Customer::seesAll($viewer) || $customer->assigned_staff_id === $viewer->id;

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'stage' => $customer->stage,
            'source' => $customer->source,
            'interest' => $customer->interest,
            'lead_state' => $customer->leadState(),
            'lost_reason' => $customer->lost_reason,
            'assigned_staff' => $customer->assignedStaff ? ['id' => $customer->assignedStaff->id, 'name' => $customer->assignedStaff->name] : null,
            'claimable' => $customer->assigned_staff_id === null && $customer->stage === 'lead',
            'created_at' => $customer->created_at->toIso8601String(),
            'age_minutes' => (int) floor($customer->created_at->diffInMinutes($now, true)),
            'last_contact_at' => $customer->last_contact_at ? Carbon::parse($customer->last_contact_at)->toIso8601String() : null,
            'next_follow_up_at' => $followUp?->toIso8601String(),
            'follow_up_overdue' => $followUp !== null && $followUp->lt($now),
            'passport_status' => $validUntil === null ? 'missing' : ($validUntil->lt(now('Asia/Dhaka')->addMonths(self::PASSPORT_MONTHS)) ? 'expiring' : 'on_file'),
            'trips_completed' => (int) $customer->trips_completed,
            'next_trip' => $customer->next_trip_id ? ['booking_id' => (int) $customer->next_trip_id, 'travel_start' => Carbon::parse($customer->next_trip_start)->toDateString()] : null,
            'whatsapp_opted_out' => $customer->whatsapp_opted_out_at !== null,
            'has_bookings' => (bool) $customer->has_booking,
            'has_quotations' => (bool) $customer->has_quotations,
            'actions' => [
                'claim' => $customer->assigned_staff_id === null && $customer->stage === 'lead' && $viewer->can('customers.manage'),
                'edit' => $works && $viewer->can('customers.manage'),
                'log_contact' => $works && $viewer->can('customers.manage'),
                'mark_lost' => $works && $viewer->can('customers.manage') && $customer->stage === 'lead' && $customer->lost_at === null,
                'assign' => $viewer->can('records.assign'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Customer $customer, Staff $viewer): array
    {
        $customer->loadMissing(['contacts.staff']);

        return array_replace(self::row($customer, $viewer), [
            'address' => $customer->address,
            'notes' => $customer->notes,
            'locale' => $customer->locale,
            'contacts' => $customer->contacts->map(fn (CustomerContact $contact) => [
                'id' => $contact->id,
                'channel' => $contact->channel,
                'outcome' => $contact->outcome,
                'note' => $contact->note,
                'next_follow_up_at' => $contact->next_follow_up_at?->toIso8601String(),
                'occurred_at' => $contact->occurred_at->toIso8601String(),
                'staff' => $contact->staff ? ['id' => $contact->staff->id, 'name' => $contact->staff->name] : null,
            ])->values(),
            'bookings' => Booking::query()->visibleTo($viewer)->where('customer_id', $customer->id)->with(['customer', 'assignedStaff'])
                ->withExists(['invoices as has_invoice' => fn (Builder $q) => $q->where('status', 'issued'), 'transactions as has_payments'])
                ->latest('id')->limit(50)->get()
                ->map(AdminBooking::summary(...))->values(),
            'quotations' => Quotation::seesAll($viewer) || Quotation::seesOwn($viewer)
                ? Quotation::query()->visibleTo($viewer)->where('customer_id', $customer->id)->with(AdminQuotation::RELATIONS)
                    ->latest('id')->limit(50)->get()
                    ->map(fn (Quotation $quotation) => AdminQuotation::row($quotation, $viewer))->values()
                : [],
        ]);
    }
}
