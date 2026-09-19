<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Staff;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use Illuminate\Support\Facades\DB;

/**
 * "+ New booking" in the admin (docs/phase-5-admin-core.md §4.3). The same BookingCreator as the website — pricing,
 * seat checks and holds, messages — with the office's differences: the customer is picked from the staff member's
 * records or entered as a new lead, passports may follow later, and the booking belongs to whoever made it.
 */
final class StaffBookingCreator
{
    public function __construct(private readonly BookingCreator $creator, private readonly Ownership $ownership) {}

    /**
     * @param  array{name: string, phone: string, email: ?string, source: string}|null  $newCustomer
     *
     * @throws PriceChanged|SeatsUnavailable|CustomerExists
     */
    public function create(BookingRequest $request, Staff $staff, ?Customer $customer, ?array $newCustomer): Booking
    {
        return DB::transaction(fn () => $this->creator->create($request, $this->customer($request, $staff, $customer, $newCustomer), $staff)['booking']);
    }

    /**
     * A custom service instead of a package (docs/custom-service-bookings.md): its name and items, each at a price per
     * person. Same customer handling as a package booking.
     *
     * @param  list<array{title: string, unitPrice: int}>  $items
     * @param  array{name: string, phone: string, email: ?string, source: string}|null  $newCustomer
     *
     * @throws PriceChanged|CustomerExists
     */
    public function createCustom(string $title, array $items, BookingRequest $request, Staff $staff, ?Customer $customer, ?array $newCustomer): Booking
    {
        return DB::transaction(fn () => $this->creator->createCustom($title, $items, $request, $this->customer($request, $staff, $customer, $newCustomer), $staff)['booking']);
    }

    /**
     * The customer the booking is for: a new lead entered here (refused if the number is already a customer's), or the
     * one picked — who comes to this staff member with the booking when nobody owned them.
     *
     * @param  array{name: string, phone: string, email: ?string, source: string}|null  $newCustomer
     *
     * @throws CustomerExists
     */
    private function customer(BookingRequest $request, Staff $staff, ?Customer $customer, ?array $newCustomer): Customer
    {
        if ($customer === null) {
            $existing = Customer::query()->where('phone', $newCustomer['phone'])->first();
            if ($existing) {
                throw new CustomerExists($existing);
            }
            $emailFree = $newCustomer['email'] !== null && ! Customer::query()->where('email', $newCustomer['email'])->exists();

            return Customer::query()->create([
                'name' => $newCustomer['name'], 'phone' => $newCustomer['phone'], 'email' => $emailFree ? $newCustomer['email'] : null,
                'stage' => 'lead', 'source' => $newCustomer['source'], 'locale' => $request->locale, 'assigned_staff_id' => $staff->id,
            ]);
        }
        if ($customer->assigned_staff_id === null) {
            try {
                $this->ownership->claim($customer, $staff); // an unowned lead comes with the booking the staff member makes
            } catch (OwnershipRefused) {
                // Not a claimable lead (an existing customer): the booking is still theirs.
            }
        }

        return $customer;
    }
}
