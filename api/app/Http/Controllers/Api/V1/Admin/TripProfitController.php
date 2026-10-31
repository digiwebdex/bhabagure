<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TripProfit;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance → Trip profit (docs/trip-profit.md): each trip's flight, land package and other costs, what was collected
 * and the profit — entered by hand, on its own, never read from or written to bookings, invoices or the books.
 * Seeing and downloading needs trip_profit.view; adding, editing and deleting trip_profit.manage.
 */
class TripProfitController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET admin/trip-profits?period=all|YYYY|YYYY-MM&search=&type=&page=: the trips, newest date first, the filter's
     * totals, and every month that has a trip (for the period picker).
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $page = (clone $query)->orderByDesc('trip_date')->orderByDesc('id')->paginate(self::PER_PAGE);
        $totals = (clone $query)->toBase()->selectRaw('count(*) as trips, coalesce(sum(pax), 0) as pax, coalesce(sum(total_collected_amount), 0) as collected, coalesce(sum(total_cost), 0) as cost, coalesce(sum(profit), 0) as profit')->first();

        return response()->json([
            'data' => collect($page->items())->map(self::trip(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'summary' => [
                'trips' => (int) $totals->trips,
                'pax' => (int) $totals->pax,
                'collected' => Money::toNumber((string) $totals->collected),
                'cost' => Money::toNumber((string) $totals->cost),
                'profit' => Money::toNumber((string) $totals->profit),
            ],
            'months' => TripProfit::query()->toBase()->selectRaw("distinct date_format(trip_date, '%Y-%m') as month")->orderByDesc('month')->pluck('month'),
        ]);
    }

    /** The filtered list as CSV for Excel: every trip (not one page), each cost, and a totals row. */
    public function export(Request $request): StreamedResponse
    {
        $period = $this->period($request);
        $trips = $this->filtered($request)->orderByDesc('trip_date')->orderByDesc('id')->get();
        $this->audit->record('trip_profit.exported', $request->user('staff'), null, ['period' => $period ?? 'all', 'rows' => $trips->count()]);
        $safe = fn ($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
        $sum = fn (string $field) => TripProfit::taka($trips->sum(fn (TripProfit $trip) => TripProfit::paisa($trip->{$field})));

        return response()->streamDownload(function () use ($trips, $safe, $sum) {
            $out = fopen('php://output', 'w');
            // Excel reads a Bangla title right only with the BOM.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Trip', 'Type', 'Date', 'Pax', 'Flight cost', 'Land package cost', 'Other cost', 'Total cost', 'Collected', 'Profit', 'Notes']);
            foreach ($trips as $trip) {
                fputcsv($out, array_map($safe, [
                    $trip->title, $trip->trip_type ? str_replace('_', ' ', $trip->trip_type) : '', $trip->trip_date->toDateString(), $trip->pax,
                    $trip->flight_cost, $trip->land_package_cost, $trip->other_cost, $trip->total_cost, $trip->total_collected_amount, $trip->profit, $trip->notes ?? '',
                ]));
            }
            fputcsv($out, ['Total', '', '', $trips->sum('pax'), $sum('flight_cost'), $sum('land_package_cost'), $sum('other_cost'), $sum('total_cost'), $sum('total_collected_amount'), $sum('profit'), '']);
            fclose($out);
        }, 'bhabaghure-trip-profit-'.($period ?? 'all-time').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        $trip = TripProfit::query()->create($this->validated($request) + ['created_by_staff_id' => $staff->id, 'updated_by_staff_id' => $staff->id]);
        $this->audit->record('trip_profit.created', $staff, $trip, self::audited($trip));

        return response()->json(['data' => self::trip($trip)], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $trip = TripProfit::query()->findOrFail($id);
        $before = self::audited($trip);
        $trip->update($this->validated($request) + ['updated_by_staff_id' => $request->user('staff')->id]);
        $this->audit->record('trip_profit.updated', $request->user('staff'), $trip, ['before' => $before, 'after' => self::audited($trip)]);

        return response()->json(['data' => self::trip($trip)]);
    }

    /** Deleted from the list and the totals; the row and the audit log keep what it was. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $trip = TripProfit::query()->findOrFail($id);
        $trip->delete();
        $this->audit->record('trip_profit.deleted', $request->user('staff'), $trip, self::audited($trip));

        return response()->json(['data' => null]);
    }

    private function filtered(Request $request): Builder
    {
        $period = $this->period($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(TripProfit::TYPES)],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        return TripProfit::query()
            ->when($period !== null && strlen($period) === 4, fn (Builder $q) => $q->whereYear('trip_date', (int) $period))
            ->when($period !== null && strlen($period) === 7, fn (Builder $q) => $q->whereBetween('trip_date', ["{$period}-01", date('Y-m-t', (int) strtotime("{$period}-01"))]))
            ->when($search !== '', fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when(isset($data['type']), fn (Builder $q) => $q->where('trip_type', $data['type']));
    }

    /** 'YYYY-MM' (a month), 'YYYY' (a year) or null (all time). */
    private function period(Request $request): ?string
    {
        $period = $request->validate(['period' => ['nullable', 'string', 'regex:/^(all|\d{4}|\d{4}-(0[1-9]|1[0-2]))$/']])['period'] ?? 'all';

        return $period === 'all' ? null : $period;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'trip_type' => ['nullable', Rule::in(TripProfit::TYPES)],
            'trip_date' => ['required', 'date_format:Y-m-d', 'after:1999-12-31', 'before:2100-01-01'],
            'pax' => ['required', 'integer', 'min:1', 'max:9999'],
            'flight_cost' => $money,
            'land_package_cost' => $money,
            'other_cost' => $money,
            'total_collected_amount' => ['required', ...array_slice($money, 1)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        // The whole trip is sent each time: a cost or type left empty is cleared, not kept from before.
        foreach (TripProfit::COSTS as $field) {
            $data[$field] = $data[$field] ?? 0;
        }
        $data['trip_type'] = $data['trip_type'] ?? null;
        $data['title'] = trim($data['title']);
        $data['notes'] = isset($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private static function trip(TripProfit $trip): array
    {
        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'trip_type' => $trip->trip_type,
            'trip_date' => $trip->trip_date->toDateString(),
            'pax' => $trip->pax,
            'flight_cost' => Money::toNumber($trip->flight_cost),
            'land_package_cost' => Money::toNumber($trip->land_package_cost),
            'other_cost' => Money::toNumber($trip->other_cost),
            'total_cost' => Money::toNumber($trip->total_cost),
            'total_collected_amount' => Money::toNumber($trip->total_collected_amount),
            'profit' => Money::toNumber($trip->profit),
            'notes' => $trip->notes,
            'updated_at' => $trip->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> what the audit log keeps of a trip */
    private static function audited(TripProfit $trip): array
    {
        return $trip->only(['title', 'trip_type', 'pax', 'flight_cost', 'land_package_cost', 'other_cost', 'total_cost', 'total_collected_amount', 'profit'])
            + ['trip_date' => $trip->trip_date?->toDateString()];
    }
}
