<?php

namespace App\Services\Coupons;

use App\Models\Coupon;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Marketing → Coupons (docs/coupons.md §2.6): the only code that creates, changes, archives or restores a
 * coupon, each audited. A change reaches only future uses: a booking keeps the terms its use copied.
 */
final class CouponManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data as CouponController validated it */
    public function create(array $data, Staff $staff): Coupon
    {
        return DB::transaction(function () use ($data, $staff) {
            $coupon = Coupon::query()->create($this->attributes($data) + ['created_by_staff_id' => $staff->id, 'updated_by_staff_id' => $staff->id]);
            $coupon->packages()->sync($this->packageIds($data));
            $this->audit->record('coupon.created', $staff, $coupon, ['code' => $coupon->code, 'kind' => $coupon->kind]);

            return $coupon;
        });
    }

    /**
     * @param  array<string, mixed>  $data  as CouponController validated it
     *
     * @throws ValidationException when a used coupon's code would change
     */
    public function update(Coupon $coupon, array $data, Staff $staff): Coupon
    {
        return DB::transaction(function () use ($coupon, $data, $staff) {
            $coupon = Coupon::withTrashed()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $attributes = $this->attributes($data);
            // The code is printed on invoices and quoted in the report: once used it stays, and a new offer is a new coupon.
            if ($attributes['code'] !== $coupon->code && $coupon->redemptions()->exists()) {
                throw ValidationException::withMessages(['code' => 'This coupon has been used, so it keeps its code (it is on bookings and invoices). Make a new coupon for a new code.']);
            }

            $coupon->fill($attributes + ['updated_by_staff_id' => $staff->id]);
            // Field names only: a passport number never goes into the audit log.
            $fields = array_values(array_diff(array_keys($coupon->getDirty()), ['updated_by_staff_id', 'passport_number_hash']));
            $coupon->save();
            $packages = $coupon->packages()->sync($this->packageIds($data));
            if ($packages['attached'] !== [] || $packages['detached'] !== []) {
                $fields[] = 'packages';
            }
            if ($fields !== []) {
                $this->audit->record('coupon.updated', $staff, $coupon, ['code' => $coupon->code, 'fields' => $fields]);
            }

            return $coupon->refresh();
        });
    }

    public function setActive(Coupon $coupon, bool $active, Staff $staff): Coupon
    {
        if ($coupon->is_active !== $active) {
            $coupon->forceFill(['is_active' => $active, 'updated_by_staff_id' => $staff->id])->save();
            $this->audit->record($active ? 'coupon.activated' : 'coupon.deactivated', $staff, $coupon, ['code' => $coupon->code]);
        }

        return $coupon;
    }

    /**
     * Deleted outright when nothing ever used it (a mistake, and its code is free again); otherwise archived — kept for the
     * bookings, invoices and report that name it, and no longer usable.
     *
     * @return 'deleted'|'archived'
     */
    public function delete(Coupon $coupon, Staff $staff): string
    {
        return DB::transaction(function () use ($coupon, $staff) {
            $coupon = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if ($coupon->redemptions()->exists()) {
                $coupon->delete();
                $this->audit->record('coupon.archived', $staff, $coupon, ['code' => $coupon->code]);

                return 'archived';
            }

            $this->audit->record('coupon.deleted', $staff, $coupon, ['code' => $coupon->code]);
            $coupon->packages()->detach();
            $coupon->forceDelete();

            return 'deleted';
        });
    }

    public function restore(Coupon $coupon, Staff $staff): Coupon
    {
        if ($coupon->trashed()) {
            $coupon->restore();
            $this->audit->record('coupon.restored', $staff, $coupon, ['code' => $coupon->code]);
        }

        return $coupon;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $passport = $data['kind'] === Coupon::PASSPORT;
        $percent = $data['discount_type'] === Coupon::PERCENT;

        return [
            'code' => Coupon::normalizeCode($data['code']),
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'channel' => $data['channel'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            // A cap means something only for a percentage.
            'max_discount_amount' => $percent ? ($data['max_discount_amount'] ?? null) : null,
            'min_booking_amount' => $data['min_booking_amount'] ?? null,
            'starts_at' => self::fromDhaka($data['starts_at'] ?? null),
            'ends_at' => self::fromDhaka($data['ends_at'] ?? null),
            'usage_limit' => $data['usage_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'applies_to' => $data['applies_to'],
            'passport_number' => $passport ? $data['passport_number'] : null,
            'holder_name' => $passport && filled($data['holder_name'] ?? null) ? trim($data['holder_name']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ];
    }

    /** @return list<int> */
    private function packageIds(array $data): array
    {
        return $data['applies_to'] === Coupon::APPLIES_PACKAGES ? array_map('intval', $data['package_ids'] ?? []) : [];
    }

    /** "2026-10-01T10:00" as the admin typed it, in Dhaka time → UTC. */
    public static function fromDhaka(?string $local): ?Carbon
    {
        // "!" zeroes what the format doesn't name (seconds), instead of taking them from the clock.
        return $local === null ? null : Carbon::createFromFormat('!Y-m-d\TH:i', $local, 'Asia/Dhaka')->utc();
    }
}
