<?php

namespace App\Services\Admin;

use App\Enums\StaffStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that changes who owns a booking, customer or enquiry (docs/phase-5-admin-core.md §0). Commission follows
 * the owner, so every change is written to the audit trail with who, from whom, to whom and why.
 */
final class Ownership
{
    private const KINDS = [Booking::class => 'booking', Customer::class => 'customer', Inquiry::class => 'inquiry'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Takes a record from the shared pool. Atomic: when two staff claim at once, one owns it and the other is refused.
     * Claiming a booking also claims its customer if that customer is still an unowned lead.
     *
     * @template T of Booking|Customer|Inquiry
     *
     * @param  T  $record
     * @return T
     */
    public function claim(Model $record, Staff $staff): Model
    {
        $kind = self::kind($record);

        return DB::transaction(function () use ($record, $staff, $kind) {
            $taken = $record->newQuery()->whereKey($record->getKey())->claimable()->update(['assigned_staff_id' => $staff->id]);
            if ($taken === 0) {
                throw new OwnershipRefused('not_claimable');
            }
            $this->audit->record("{$kind}.claimed", $staff, $record, ['to' => $staff->id]);

            if ($record instanceof Booking && $record->customer_id !== null) {
                $customer = Customer::query()->whereKey($record->customer_id)->claimable()->update(['assigned_staff_id' => $staff->id]);
                if ($customer > 0) {
                    $this->audit->record('customer.claimed', $staff, Customer::query()->find($record->customer_id), ['to' => $staff->id, 'with_booking' => $record->id]);
                }
            }

            return $record->fresh();
        });
    }

    /**
     * An admin moves a record to another staff member (or back to the pool with null).
     *
     * @template T of Booking|Customer|Inquiry
     *
     * @param  T  $record
     * @return T
     */
    public function assign(Model $record, ?Staff $to, Staff $by, string $reason): Model
    {
        $kind = self::kind($record);
        if ($to !== null && ($to->status !== StaffStatus::Active || ! ($record::seesAll($to) || $record::seesOwn($to)))) {
            throw new OwnershipRefused('cannot_own');
        }

        return DB::transaction(function () use ($record, $to, $by, $reason, $kind) {
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->assigned_staff_id;
            if ($from === $to?->id) {
                throw new OwnershipRefused('same_owner');
            }
            $locked->newQuery()->whereKey($locked->getKey())->update(['assigned_staff_id' => $to?->id]);
            $this->audit->record("{$kind}.reassigned", $by, $locked, ['from' => $from, 'to' => $to?->id, 'reason' => $reason]);

            return $locked->fresh();
        });
    }

    private static function kind(Model $record): string
    {
        return self::KINDS[$record::class] ?? throw new InvalidArgumentException($record::class.' has no owner.');
    }
}
