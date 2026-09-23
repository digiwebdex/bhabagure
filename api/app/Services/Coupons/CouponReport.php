<?php

namespace App\Services\Coupons;

use App\Models\BookingTraveller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Marketing → Coupon report (docs/coupons.md §2.7). Every figure comes from the uses (coupon_redemptions): used
 * = confirmed bookings, pending = reserved by open ones, released = given back. Discount given and revenue count used
 * ones only; revenue is those bookings' totals after the discount.
 */
final class CouponReport
{
    /** Figures summed over a set of uses, in one pass. */
    private const SUMS = "SUM(r.status = 'used') AS used, SUM(r.status = 'reserved') AS pending, SUM(r.status = 'released') AS released,
        COALESCE(SUM(CASE WHEN r.status = 'used' THEN r.discount_amount END), 0) AS discount_given,
        COALESCE(SUM(CASE WHEN r.status = 'used' THEN r.final_total END), 0) AS revenue";

    /**
     * @param  array{from?: ?string, to?: ?string, coupon_id?: ?int, status?: ?string, search?: ?string, passport?: ?string}  $filters
     * @return array{totals: array<string, int|float>, coupons: list<array<string, mixed>>, campaigns: list<array<string, mixed>>, passport_uses: Collection<int, CouponRedemption>, uses: LengthAwarePaginator}
     */
    public function build(array $filters): array
    {
        $now = now();
        $sums = DB::query()->fromSub($this->uses($filters)->toBase()->select('coupon_redemptions.*'), 'r')->selectRaw(self::SUMS)->first();

        // Coupon by coupon — every live coupon, and an archived one only when it has uses in the period.
        $perCoupon = DB::query()->fromSub($this->uses($filters)->toBase()->select('coupon_redemptions.*'), 'r')
            ->groupBy('r.coupon_id')->selectRaw('r.coupon_id, '.self::SUMS);
        $coupons = Coupon::withTrashed()
            ->select('coupons.*', 'u.used', 'u.pending', 'u.released', 'u.discount_given', 'u.revenue')
            ->withLiveUses()
            ->leftJoinSub($perCoupon, 'u', 'u.coupon_id', '=', 'coupons.id')
            ->when($filters['coupon_id'] ?? null, fn (Builder $q, int $id) => $q->where('coupons.id', $id))
            ->where(fn (Builder $q) => $q->whereNull('coupons.deleted_at')->orWhereNotNull('u.coupon_id'))
            ->orderByDesc(DB::raw('COALESCE(u.used, 0)'))->orderBy('coupons.code')
            ->get()
            ->map(fn (Coupon $coupon) => [
                'id' => $coupon->id, 'code' => $coupon->code, 'name' => $coupon->name, 'kind' => $coupon->kind, 'channel' => $coupon->channel,
                'status' => $coupon->status($now),
                'used' => (int) $coupon->used, 'pending' => (int) $coupon->pending, 'released' => (int) $coupon->released,
                'discount_given' => Money::toNumber($coupon->discount_given ?? 0), 'revenue' => Money::toNumber($coupon->revenue ?? 0),
            ]);

        return [
            'totals' => [
                // The catalogue as it stands, whatever the filters.
                'coupons' => Coupon::query()->count(),
                'active' => Coupon::query()->inStatus('active', $now)->count(),
                'expired' => Coupon::query()->inStatus('expired', $now)->count(),
                'used' => (int) ($sums->used ?? 0),
                'pending' => (int) ($sums->pending ?? 0),
                'released' => (int) ($sums->released ?? 0),
                'discount_given' => Money::toNumber($sums->discount_given ?? 0),
                'revenue' => Money::toNumber($sums->revenue ?? 0),
            ],
            'coupons' => $coupons->values()->all(),
            // A campaign is every coupon that shares its name (a Facebook code and an SMS code for one Eid offer, say).
            'campaigns' => $coupons->groupBy('name')->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'channels' => $group->pluck('channel')->filter()->unique()->values()->all(),
                'coupons' => $group->count(),
                'used' => $group->sum('used'),
                'pending' => $group->sum('pending'),
                'discount_given' => $group->sum('discount_given'),
                'revenue' => $group->sum('revenue'),
            ])->sortByDesc('used')->values()->all(),
            'passport_uses' => $this->uses($filters)->where('coupon_redemptions.kind', Coupon::PASSPORT)
                ->with(['booking', 'customer', 'coupon'])->latest('applied_at')->latest('id')->limit(50)->get(),
            'uses' => $this->uses($filters)->with(['booking', 'customer', 'coupon'])->latest('applied_at')->latest('id')->paginate(30),
        ];
    }

    /**
     * The uses the filters describe: applied in the Dhaka date range, of one coupon, in one status, matching a booking
     * number, customer name or phone, or code, and — matched on its HMAC — one passport.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<CouponRedemption>
     */
    private function uses(array $filters): Builder
    {
        $day = fn (string $date, bool $end) => Carbon::parse($date, 'Asia/Dhaka')->{$end ? 'endOfDay' : 'startOfDay'}()->utc();
        $passport = strtoupper((string) preg_replace('/\s+/', '', (string) ($filters['passport'] ?? '')));

        return CouponRedemption::query()
            ->when($filters['coupon_id'] ?? null, fn (Builder $q, int $id) => $q->where('coupon_redemptions.coupon_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('coupon_redemptions.status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('coupon_redemptions.applied_at', '>=', $day($from, false)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('coupon_redemptions.applied_at', '<=', $day($to, true)))
            ->when($passport !== '', fn (Builder $q) => $q->where('coupon_redemptions.passport_number_hash', BookingTraveller::passportHash($passport)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('coupon_redemptions.code', 'like', '%'.Coupon::normalizeCode($search).'%')
                ->orWhereHas('booking', fn (Builder $b) => $b->where('reference', 'like', "%{$search}%"))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))));
    }
}
