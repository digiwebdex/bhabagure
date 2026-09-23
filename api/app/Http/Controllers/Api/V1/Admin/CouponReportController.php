<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCoupon;
use App\Models\CouponRedemption;
use App\Services\Coupons\CouponReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin → Marketing → Coupon report (docs/coupons.md §2.7); coupons.view or coupons.manage. */
class CouponReportController extends Controller
{
    public function __invoke(Request $request, CouponReport $report): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'coupon_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(CouponRedemption::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'passport' => ['nullable', 'string', 'max:20'],
        ]);
        if (isset($filters['from'], $filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'The end date is before the start date.']);
        }
        $filters['coupon_id'] = isset($filters['coupon_id']) ? (int) $filters['coupon_id'] : null;

        $result = $report->build($filters);
        $uses = $result['uses'];

        return response()->json([
            'data' => [
                'totals' => $result['totals'],
                'coupons' => $result['coupons'],
                'campaigns' => $result['campaigns'],
                'passport_uses' => $result['passport_uses']->map(AdminCoupon::use(...))->values(),
                'uses' => collect($uses->items())->map(AdminCoupon::use(...))->values(),
            ],
            'meta' => ['current_page' => $uses->currentPage(), 'last_page' => $uses->lastPage(), 'total' => $uses->total()],
        ]);
    }
}
