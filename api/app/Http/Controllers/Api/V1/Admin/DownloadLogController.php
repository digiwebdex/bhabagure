<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Download;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The Downloads screen (docs/phase-8-visa-quotes-pricing-downloads.md §4.E, permission downloads.view — the Super Admin, or
 * whoever the Roles screen grants it): every brochure and visa PDF a signed-in customer downloaded, with who they are,
 * what they chose, and whether a booking or quotation is already in progress — so staff can follow up the ones who
 * never got in touch.
 */
class DownloadLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in([Download::PACKAGE, Download::VISA])],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'follow_up' => ['nullable', 'boolean'],
        ]);
        $query = self::filtered($filters);

        $totals = (clone $query)->toBase()->selectRaw('count(*) as downloads, count(distinct customer_id) as customers')
            ->selectRaw("sum(kind = 'package') as packages, sum(kind = 'visa') as visas")->first();
        $page = $query->with(['customer:id,name,phone,email,stage,assigned_staff_id', 'customer.assignedStaff:id,name', 'package:id,slug', 'visa:id,slug'])
            ->latest('created_at')->latest('id')->paginate(30);

        $customerIds = collect($page->items())->pluck('customer_id')->unique()->values();
        $bookings = Booking::query()->whereIn('customer_id', $customerIds)->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Confirmed])
            ->pluck('customer_id')->unique()->flip();
        $quotations = Quotation::query()->whereIn('customer_id', $customerIds)->where(fn (Builder $q) => $q->open()->orWhere('status', Quotation::DRAFT))
            ->pluck('customer_id')->unique()->flip();
        $counts = Download::query()->whereIn('customer_id', $customerIds)->groupBy('customer_id')->selectRaw('customer_id, count(*) as n')->pluck('n', 'customer_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Download $download) => [
                'id' => $download->id,
                'created_at' => $download->created_at->toIso8601String(),
                'kind' => $download->kind,
                'title' => $download->title,
                'slug' => $download->kind === Download::PACKAGE ? $download->package?->slug : $download->visa?->slug,
                'hotel_category' => $download->hotel_category,
                'pax' => $download->pax,
                'locale' => $download->locale,
                'customer' => [
                    'id' => $download->customer->id, 'name' => $download->customer->name, 'phone' => $download->customer->phone, 'email' => $download->customer->email,
                    'stage' => $download->customer->stage, 'owner' => $download->customer->assignedStaff?->name,
                    'downloads' => (int) ($counts[$download->customer_id] ?? 1),
                ],
                'in_progress' => ['booking' => $bookings->has($download->customer_id), 'quotation' => $quotations->has($download->customer_id)],
            ]),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'totals' => ['downloads' => (int) $totals->downloads, 'customers' => (int) $totals->customers, 'packages' => (int) $totals->packages, 'visas' => (int) $totals->visas],
            ],
        ]);
    }

    /** @param array<string, mixed> $filters */
    public static function filtered(array $filters): Builder
    {
        $dhaka = fn (string $date, bool $end) => Carbon::parse($date, 'Asia/Dhaka')->{$end ? 'endOfDay' : 'startOfDay'}()->utc();

        return Download::query()
            ->when($filters['kind'] ?? null, fn (Builder $q, string $kind) => $q->where('kind', $kind))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $dhaka($from, false)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', $dhaka($to, true)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('title', 'like', "%{$search}%")
                // A number is matched by its digits ("01711-000401"); a name alone must not match every phone.
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")
                    ->when(preg_replace('/\D/', '', $search), fn (Builder $p, string $digits) => $p->orWhere('phone', 'like', "%{$digits}%")))))
            // Nobody has booked or been quoted yet: the people to follow up.
            ->when(filter_var($filters['follow_up'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q
                ->whereDoesntHave('customer.bookings', fn (Builder $b) => $b->whereIn('status', [BookingStatus::Inquiry, BookingStatus::Confirmed]))
                ->whereDoesntHave('customer.quotations', fn (Builder $b) => $b->where(fn (Builder $open) => $open->open()->orWhere('status', Quotation::DRAFT))));
    }
}
