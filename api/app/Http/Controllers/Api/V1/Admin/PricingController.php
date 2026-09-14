<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\Addon;
use App\Models\PricingSlab;
use App\Models\SiteSetting;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Group-slab tiers, the single-room supplement, service charge and add-ons: the inputs to the website's
 * pricing package (packages/pricing) and to the server's booking calculation (Phase 3).
 */
class PricingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /**
     * Body: { slabs: [{min_pax, discount_percent}], single_room_supplement_percent, service_charge_percent, max_travellers }.
     * The slab list is replaced as a whole; it must start at 1 traveller and its discounts must not decrease.
     */
    public function update(Request $request): JsonResponse
    {
        $data = validator($request->all(), [
            'slabs' => ['required', 'array', 'min:1', 'max:10'],
            'slabs.*.min_pax' => ['required', 'integer', 'between:1,99', 'distinct'],
            'slabs.*.discount_percent' => ['required', 'numeric', 'between:0,50', 'decimal:0,2'],
            'single_room_supplement_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'service_charge_percent' => ['required', 'numeric', 'between:0,25', 'decimal:0,2'],
            'max_travellers' => ['required', 'integer', 'between:1,99'],
            // 0 = the company absorbs the gateway fee. Otherwise shown to the customer as its own line before paying.
            'online_payment_charge_percent' => ['required', 'numeric', 'between:0,10', 'decimal:0,2'],
        ])->after(function (Validator $validator) {
            $slabs = collect($validator->getData()['slabs'] ?? [])->sortBy('min_pax')->values();
            if ($slabs->isEmpty() || (int) ($slabs->first()['min_pax'] ?? 0) !== 1) {
                $validator->errors()->add('slabs', 'The first slab must start at 1 traveller.');
            }
            $previous = -1.0;
            foreach ($slabs as $slab) {
                if ((float) ($slab['discount_percent'] ?? 0) < $previous) {
                    $validator->errors()->add('slabs', 'A larger group can never get a smaller discount.');
                    break;
                }
                $previous = (float) ($slab['discount_percent'] ?? 0);
            }
        })->validate();

        DB::transaction(function () use ($data, $request) {
            PricingSlab::query()->delete();
            foreach ($data['slabs'] as $slab) {
                PricingSlab::query()->create($slab);
            }
            SiteSetting::query()->updateOrCreate(['key' => SiteSettingKeys::PRICING], [
                'value' => [
                    'singleRoomSupplementPercent' => Money::toNumber($data['single_room_supplement_percent']),
                    'serviceChargePercent' => Money::toNumber($data['service_charge_percent']),
                    'maxTravellers' => (int) $data['max_travellers'],
                    'onlinePaymentChargePercent' => Money::toNumber($data['online_payment_charge_percent']),
                ],
                'updated_by_staff_id' => $request->user('staff')->id,
            ]);
        });

        $this->audit->record('pricing.updated', $request->user('staff'), changes: $data);
        RevalidateWebsite::dispatch(['settings', 'packages']);

        return response()->json(['data' => $this->payload()]);
    }

    public function addons(): JsonResponse
    {
        return response()->json(['data' => Addon::query()->orderBy('sort_order')->orderBy('id')->get()->map(AdminContent::addon(...))]);
    }

    public function storeAddon(Request $request): JsonResponse
    {
        $addon = Addon::query()->create([...$this->validatedAddon($request), 'sort_order' => (int) Addon::query()->max('sort_order') + 1]);

        return $this->addonSaved($request, $addon, 'created', 201);
    }

    public function updateAddon(Request $request, int $id): JsonResponse
    {
        $addon = Addon::query()->findOrFail($id);
        $addon->update($this->validatedAddon($request, $addon));

        return $this->addonSaved($request, $addon, 'updated');
    }

    private function validatedAddon(Request $request, ?Addon $addon = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('addons', 'code')->ignore($addon?->id)],
            'name_bn' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'unit' => ['required', Rule::in(['per_person', 'per_booking'])],
            // Add-ons are retired, not deleted: past bookings reference them.
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function addonSaved(Request $request, Addon $addon, string $action, int $status = 200): JsonResponse
    {
        $this->audit->record("pricing.addon.{$action}", $request->user('staff'), $addon, $addon->getChanges() ?: null);
        RevalidateWebsite::dispatch(['settings']);

        return response()->json(['data' => AdminContent::addon($addon)], $status);
    }

    private function payload(): array
    {
        $settings = SiteSetting::get(SiteSettingKeys::PRICING, []);

        return [
            'slabs' => PricingSlab::query()->orderBy('min_pax')->get()
                ->map(fn (PricingSlab $slab) => ['min_pax' => $slab->min_pax, 'discount_percent' => Money::toNumber($slab->discount_percent)]),
            'single_room_supplement_percent' => $settings['singleRoomSupplementPercent'] ?? 0,
            'service_charge_percent' => $settings['serviceChargePercent'] ?? 0,
            'max_travellers' => $settings['maxTravellers'] ?? 20,
            'online_payment_charge_percent' => $settings['onlinePaymentChargePercent'] ?? 0,
        ];
    }
}
