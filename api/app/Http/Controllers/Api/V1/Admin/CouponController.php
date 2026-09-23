<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCoupon;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\TourPackage;
use App\Services\Coupons\CouponManager;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Marketing → Coupons (docs/coupons.md §2.6). Reading needs coupons.view (or coupons.manage), every change
 * coupons.manage (routes/api.php). Staff messages are English, like the rest of the staff API.
 */
class CouponController extends Controller
{
    public function __construct(private readonly CouponManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Coupon::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = $filters['search'] ?? null;
        $base = fn () => Coupon::query()->when($search, fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
            ->where('code', 'like', '%'.Coupon::normalizeCode($s).'%')->orWhere('name', 'like', "%{$s}%")->orWhere('holder_name', 'like', "%{$s}%")));

        $page = $base()->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->inStatus($status))
            ->with(['packages', 'createdBy'])->withLiveUses()->withExists('redemptions as ever_used')
            ->latest('id')->paginate(30);
        $usage = self::usage(collect($page->items())->pluck('id'));
        // The chips' numbers: the same search in each status, by the same rules as the rows (Coupon::scopeInStatus).
        $counts = collect(Coupon::STATUSES)->mapWithKeys(fn (string $status) => [$status => $base()->inStatus($status)->count()]);

        return response()->json([
            'data' => collect($page->items())->map(fn (Coupon $coupon) => AdminCoupon::make($coupon, $request->user('staff'), $usage[$coupon->id] ?? [])),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'status_counts' => ['all' => $base()->count()] + $counts->all(),
            ],
        ]);
    }

    /** What the form offers: every package that isn't archived, and the campaign channels. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'packages' => TourPackage::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'title_en', 'title_bn', 'status'])
                ->map(fn (TourPackage $p) => ['id' => $p->id, 'title' => $p->title_en ?: $p->title_bn, 'published' => $p->status->value === 'published'])->values(),
            'channels' => Coupon::CHANNELS,
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->coupon($request, Coupon::withTrashed()->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $coupon = $this->manager->create($this->validated($request), $request->user('staff'));

        return $this->coupon($request, $coupon, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::query()->findOrFail($id);

        return $this->coupon($request, $this->manager->update($coupon, $this->validated($request, $coupon), $request->user('staff')));
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        return $this->coupon($request, $this->manager->setActive(Coupon::query()->findOrFail($id), true, $request->user('staff')));
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        return $this->coupon($request, $this->manager->setActive(Coupon::query()->findOrFail($id), false, $request->user('staff')));
    }

    /** Deleted when nothing ever used it, otherwise archived: `result` says which. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $result = $this->manager->delete(Coupon::query()->findOrFail($id), $request->user('staff'));

        return response()->json(['data' => ['result' => $result]]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->coupon($request, $this->manager->restore(Coupon::onlyTrashed()->findOrFail($id), $request->user('staff')));
    }

    private function coupon(Request $request, Coupon $coupon, int $status = 200): JsonResponse
    {
        $coupon = Coupon::withTrashed()->with(['packages', 'createdBy'])->withLiveUses()->withExists('redemptions as ever_used')->findOrFail($coupon->id);

        return response()->json(['data' => AdminCoupon::make($coupon, $request->user('staff'), self::usage(collect([$coupon->id]))[$coupon->id] ?? [])], $status);
    }

    /**
     * Uses per coupon, all time: used, pending, released, and the discount given and revenue of the used ones.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, array{used: int, pending: int, released: int, discount_given: int|float, revenue: int|float}>
     */
    private static function usage(Collection $ids): array
    {
        return CouponRedemption::query()->whereIn('coupon_id', $ids)->groupBy('coupon_id')
            ->selectRaw("coupon_id, SUM(status = 'used') AS used, SUM(status = 'reserved') AS pending, SUM(status = 'released') AS released,
                COALESCE(SUM(CASE WHEN status = 'used' THEN discount_amount END), 0) AS discount_given,
                COALESCE(SUM(CASE WHEN status = 'used' THEN final_total END), 0) AS revenue")
            ->toBase()->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->coupon_id => [
                'used' => (int) $row->used, 'pending' => (int) $row->pending, 'released' => (int) $row->released,
                'discount_given' => Money::toNumber($row->discount_given), 'revenue' => Money::toNumber($row->revenue),
            ]])->all();
    }

    /**
     * The form, checked. Codes and passport numbers are compared in capitals without spaces, as they are stored.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $passport = strtoupper((string) preg_replace('/\s+/', '', (string) $request->input('passport_number')));
        $request->merge(['code' => Coupon::normalizeCode((string) $request->input('code')), 'passport_number' => $passport === '' ? null : $passport]);
        $percent = $request->input('discount_type') === Coupon::PERCENT;

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:'.Coupon::CODE_PATTERN, Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Coupon::KINDS)],
            'channel' => ['nullable', Rule::in(Coupon::CHANNELS)],
            'discount_type' => ['required', Rule::in(Coupon::DISCOUNT_TYPES)],
            // A percentage up to 100 (two decimals at most); a fixed discount in whole taka.
            'discount_value' => ['required', 'numeric', 'gt:0', ...($percent ? ['max:100', 'decimal:0,2'] : ['integer', 'max:99999999'])],
            'max_discount_amount' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'min_booking_amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            // Dhaka time, as the form's date-and-time fields send it.
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'applies_to' => ['required', Rule::in([Coupon::APPLIES_ALL, Coupon::APPLIES_PACKAGES])],
            'package_ids' => ['exclude_unless:applies_to,packages', 'required', 'array', 'min:1', 'max:200'],
            'package_ids.*' => ['integer', 'distinct', Rule::exists('tour_packages', 'id')->whereNull('deleted_at')],
            'passport_number' => ['exclude_unless:kind,passport', 'required', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'holder_name' => ['nullable', 'string', 'max:160'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'code.unique' => 'This code is taken — by an archived coupon if it isn\'t in the list. Choose another code.',
            'code.regex' => 'Use letters, digits and dashes, 3 to 30 long.',
            'package_ids.required' => 'Choose at least one package, or let the coupon work on every booking.',
            'passport_number.required' => 'A passport coupon needs the holder\'s passport number.',
            'passport_number.regex' => 'A passport number is two letters and seven digits, or one letter and eight digits.',
        ]);

        if (isset($data['starts_at'], $data['ends_at']) && CouponManager::fromDhaka($data['ends_at'])->lte(CouponManager::fromDhaka($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'The coupon must end after it starts.']);
        }

        return $data;
    }
}
