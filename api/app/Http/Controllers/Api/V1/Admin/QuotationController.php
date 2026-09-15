<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminQuotation;
use App\Models\Addon;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Staff;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\Booking\BookingFormOptions;
use App\Services\Booking\PriceChanged;
use App\Services\Booking\SeatsUnavailable;
use App\Services\Quotations\QuotationInput;
use App\Services\Quotations\QuotationPdf;
use App\Services\Quotations\QuotationRefused;
use App\Services\Quotations\QuotationService;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Pricing\PricingConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Quotations (docs/phase-5-admin-core.md §4.5). Staff see their own, or all with quotations.view_all; every action
 * also needs quotations.manage (or .convert) and a quotation the staff member can see. State rules live in
 * QuotationService; a refused action answers 409 with a code.
 */
class QuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Quotation::FILTERS)],
            'owner' => ['nullable', Rule::in(['mine'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $staff = $request->user('staff');

        $page = Quotation::query()->visibleTo($staff)->filtered($filters, $staff)->with(AdminQuotation::RELATIONS)->latest('id')->paginate(30);
        // Chip counts under the same search and owner filters, whatever status is picked.
        $base = fn () => Quotation::query()->visibleTo($staff)->filtered(['status' => null] + $filters, $staff);
        $stored = $base()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $expired = $base()->expired()->count();

        return response()->json([
            'data' => collect($page->items())->map(fn (Quotation $quotation) => AdminQuotation::row($quotation, $staff)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'status_counts' => [
                    'draft' => (int) ($stored[Quotation::DRAFT] ?? 0),
                    'sent' => (int) ($stored[Quotation::SENT] ?? 0) - $expired,
                    'expiring' => $base()->expiringSoon()->count(),
                    'expired' => $expired,
                    'accepted' => (int) ($stored[Quotation::ACCEPTED] ?? 0),
                    'converted' => (int) ($stored[Quotation::CONVERTED] ?? 0),
                    'declined' => (int) ($stored[Quotation::DECLINED] ?? 0),
                    'withdrawn' => (int) ($stored[Quotation::WITHDRAWN] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * The KPIs, from the quotations the staff member can see. Conversion and average value count offers sent in the
     * last 90 days, leaving out those replaced by a sent revision, so revising a quote doesn't count it twice.
     */
    public function summary(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        $visible = fn () => Quotation::query()->visibleTo($staff);
        $offers = fn () => $visible()->where('sent_at', '>=', now()->subDays(90))
            ->whereDoesntHave('revisions', fn (Builder $revision) => $revision->whereNotNull('sent_at'));
        $sent = $offers()->count();
        $converted = $offers()->where('status', Quotation::CONVERTED)->count();

        return response()->json(['data' => [
            'open' => ['count' => $visible()->open()->count(), 'total' => Money::toNumber($visible()->open()->sum('total_amount'))],
            'expiring' => $visible()->expiringSoon()->count(),
            'conversion' => ['sent' => $sent, 'converted' => $converted, 'percent' => $sent > 0 ? round($converted * 100 / $sent, 1) : null],
            'average_value' => $sent > 0 ? round((float) $offers()->avg('total_amount')) : null,
        ]]);
    }

    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user('staff')->can('quotations.manage'), 403, __('auth.forbidden'));

        return response()->json(['data' => BookingFormOptions::data() + [
            'vat_rates' => BookingFormOptions::vatRates(),
            'validity_days' => Quotation::VALIDITY_DAYS,
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->one($request, $this->find($request, $id));
    }

    public function store(Request $request, QuotationService $service): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('quotations.manage'), 403, __('auth.forbidden'));
        $data = $this->validated($request, creating: true);
        // A customer record the staff member can see, never free text: messages look the number up server-side.
        $customer = Customer::query()->visibleTo($staff)->findOrFail($data['customer_id']);

        try {
            $quotation = $service->create($this->input($data), $customer, $staff);
        } catch (PriceChanged $e) {
            return $this->priceChanged($e);
        }

        return $this->one($request, $quotation, Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id, QuotationService $service): JsonResponse
    {
        $quotation = $this->find($request, $id, 'quotations.manage');
        $data = $this->validated($request, creating: false);

        try {
            $quotation = $service->update($quotation, $this->input($data), $request->user('staff'));
        } catch (PriceChanged $e) {
            return $this->priceChanged($e);
        } catch (QuotationRefused $e) {
            return $this->refused($e);
        }

        return $this->one($request, $quotation);
    }

    public function destroy(Request $request, int $id, QuotationService $service): JsonResponse
    {
        $quotation = $this->find($request, $id, 'quotations.manage');
        try {
            $service->delete($quotation, $request->user('staff'));
        } catch (QuotationRefused $e) {
            return $this->refused($e);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** send · accept · revise, and decline · withdraw with an optional reason. */
    public function transition(Request $request, int $id, string $action, QuotationService $service): JsonResponse
    {
        $quotation = $this->find($request, $id, 'quotations.manage');
        $staff = $request->user('staff');
        $reason = in_array($action, ['decline', 'withdraw'], true)
            ? $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null
            : null;

        try {
            $result = match ($action) {
                'send' => $service->send($quotation, $staff),
                'accept' => $service->accept($quotation, $staff),
                'decline' => $service->decline($quotation, $staff, $reason),
                'withdraw' => $service->withdraw($quotation, $staff, $reason),
                'revise' => $service->revise($quotation, $staff),
            };
        } catch (QuotationRefused $e) {
            return $this->refused($e);
        }

        return $this->one($request, $result, $action === 'revise' && $result->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** Into an inquiry booking at the frozen price. Converting again answers with the booking made the first time. */
    public function convert(Request $request, int $id, QuotationService $service): JsonResponse
    {
        $quotation = $this->find($request, $id, 'quotations.convert');
        if (is_array($request->input('travellers'))) {
            $request->merge(['travellers' => array_map(fn ($t) => is_array($t) && isset($t['phone']) ? ['phone' => Phone::normalizeBdMobile($t['phone']) ?? $t['phone']] + $t : $t, $request->input('travellers'))]);
        }
        $data = $request->validate([
            'travel_date' => [$quotation->travel_date ? 'nullable' : 'required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'travellers' => ['required', 'array', "size:{$quotation->pax_count}"],
            'travellers.*.name' => ['required', 'string', 'max:160'],
            'travellers.*.passport_number' => ['nullable', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'travellers.*.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'travellers.*.passport_expiry' => ['nullable', 'date_format:Y-m-d'],
            'travellers.*.phone' => ['nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'travellers.*.email' => ['nullable', 'email', 'max:190'],
        ]);
        $quotation->loadMissing('customer');
        $travellers = array_values($data['travellers']);
        // The lead traveller is reachable at the customer's number unless another was given.
        $travellers[0]['phone'] ??= $quotation->customer->phone;
        $travellers[0]['email'] ??= $quotation->customer->email;

        try {
            ['booking' => $booking, 'created' => $created] = $service->convert($quotation, $data['travel_date'] ?? null, array_map(fn (array $t) => [
                'name' => trim($t['name']),
                'passportNumber' => $t['passport_number'] ?? null,
                'dateOfBirth' => $t['date_of_birth'] ?? null,
                'passportExpiry' => $t['passport_expiry'] ?? null,
                'phone' => $t['phone'] ?? null,
                'email' => $t['email'] ?? null,
            ], $travellers), $request->user('staff'));
        } catch (QuotationRefused $e) {
            return $this->refused($e);
        } catch (SeatsUnavailable $e) {
            return response()->json(['message' => __('booking.seats_unavailable', ['count' => $e->available]), 'code' => 'seats_unavailable', 'available' => $e->available], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => [
                'booking' => ['id' => $booking->id, 'reference' => $booking->reference],
                'quotation' => AdminQuotation::detail($quotation->fresh(), $request->user('staff')),
            ],
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function assign(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $quotation = $this->find($request, $id, 'records.assign');
        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            $ownership->assign($quotation, Staff::query()->find($data['staff_id']), $request->user('staff'), $data['reason']);
        } catch (OwnershipRefused $e) {
            return response()->json(['message' => __("ownership.{$e->reason}"), 'code' => $e->reason], 422);
        }

        return $this->one($request, $quotation->fresh());
    }

    /** The quotation as it prints (header on or off, either language). */
    public function print(Request $request, int $id, QuotationPdf $pdf): Response
    {
        [$quotation, $header, $locale] = $this->printable($request, $id);

        return response($pdf->html($quotation, $header, $locale))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com");
    }

    public function pdf(Request $request, int $id, QuotationPdf $pdf): Response
    {
        [$quotation, $header, $locale] = $this->printable($request, $id);

        return response($pdf->pdf($quotation, $header, $locale))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$quotation->number}".($header ? '' : '-pad').'.pdf"')
            ->header('Cache-Control', 'no-store');
    }

    /** @return array{0: Quotation, 1: bool, 2: string} */
    private function printable(Request $request, int $id): array
    {
        $quotation = $this->find($request, $id);
        $options = $request->validate(['header' => ['nullable', 'boolean'], 'lang' => ['nullable', Rule::in(['bn', 'en'])]]);

        return [$quotation, (bool) ($options['header'] ?? true), $options['lang'] ?? $quotation->locale];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $max = PricingConfig::current()->maxTravellers;

        return $request->validate([
            'customer_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'package_slug' => ['required', 'string', Rule::exists('tour_packages', 'slug')->where('status', 'published')],
            'travel_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'pax' => ['required', 'integer', 'min:1', "max:{$max}"],
            'room' => ['required', Rule::in(['twin', 'triple', 'single'])],
            'hotel_category' => ['nullable', Rule::in(['3', '4', '5'])],
            'addons' => ['array', 'max:20'],
            'addons.*' => ['string', 'distinct', Rule::exists(Addon::class, 'code')->where('is_active', true)],
            'discount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'vat_rate' => ['required', 'numeric', Rule::in(BookingFormOptions::vatRates())],
            'validity_days' => ['required', 'integer', Rule::in(Quotation::VALIDITY_DAYS)],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_total' => ['required', 'numeric', 'min:0'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function input(array $data): QuotationInput
    {
        return new QuotationInput(
            packageSlug: $data['package_slug'],
            travelDate: $data['travel_date'] ?? null,
            pax: (int) $data['pax'],
            room: $data['room'],
            addonCodes: $data['addons'] ?? [],
            discount: Money::toNumber($data['discount']),
            vatRate: Money::toNumber($data['vat_rate']),
            validityDays: (int) $data['validity_days'],
            locale: $data['locale'],
            notes: $data['notes'] ?? null,
            expectedTotal: $data['expected_total'],
            hotelCategory: $data['hotel_category'] ?? null,
        );
    }

    private function find(Request $request, int $id, ?string $permission = null): Quotation
    {
        $staff = $request->user('staff');
        abort_if($permission !== null && ! $staff->can($permission), 403, __('auth.forbidden'));

        return Quotation::query()->visibleTo($staff)->findOrFail($id);
    }

    private function one(Request $request, Quotation $quotation, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json(['data' => AdminQuotation::detail($quotation, $request->user('staff'))], $status);
    }

    private function refused(QuotationRefused $e): JsonResponse
    {
        return response()->json(['message' => __("quotations.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
    }

    private function priceChanged(PriceChanged $e): JsonResponse
    {
        return response()->json(['message' => __('booking.price_changed'), 'code' => 'price_changed', 'quote' => $e->quote], Response::HTTP_CONFLICT);
    }
}
