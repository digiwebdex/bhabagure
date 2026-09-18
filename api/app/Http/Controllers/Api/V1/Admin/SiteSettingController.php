<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Http\Controllers\Controller;
use App\Jobs\RevalidateWebsite;
use App\Models\SiteSetting;
use App\Services\AuditLogger;
use App\Support\Payments\PaymentOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company details, contact channels, opening hours and the website's social stats. */
class SiteSettingController extends Controller
{
    /** Validation per key; each value is saved whole. Shapes: web/src/lib/content/types.ts SiteSettings. */
    private const RULES = [
        'company' => [
            'value.name.bn' => ['required', 'string', 'max:160'], 'value.name.en' => ['required', 'string', 'max:160'],
            'value.brand.bn' => ['required', 'string', 'max:120'], 'value.brand.en' => ['required', 'string', 'max:120'],
        ],
        'contact' => [
            'value.phone' => ['required', 'string', 'regex:/^\+8801[3-9]\d{8}$/'],
            'value.phoneAlt' => ['nullable', 'string', 'regex:/^\+8801[3-9]\d{8}$/'],
            'value.whatsapp' => ['required', 'string', 'regex:/^\+8801[3-9]\d{8}$/'],
            // The number automated WhatsApp messages come from, published beside the main line so customers can check it.
            'value.notificationsWhatsapp' => ['nullable', 'string', 'regex:/^\+8801[3-9]\d{8}$/', 'different:value.phone', 'different:value.whatsapp'],
            'value.email' => ['required', 'email', 'max:190'],
            'value.facebook' => ['nullable', 'url:https', 'max:255'],
            'value.instagram' => ['nullable', 'url:https', 'max:255'],
            'value.website' => ['nullable', 'url:https', 'max:255'],
        ],
        'address' => ['value' => ['required', 'string', 'max:500']],
        'civilAviationNo' => ['value' => ['required', 'string', 'max:40']],
        'hours' => [
            'value.opens' => ['required', 'integer', 'between:0,23'],
            'value.closes' => ['required', 'integer', 'between:1,24', 'gt:value.opens'],
        ],
        'stats' => [
            'value.topReelViewsThousands' => ['required', 'integer', 'min:0'],
            'value.banglaSupportPercent' => ['required', 'integer', 'between:0,100'],
        ],
        // Phase 8 §4.F. Each method is optional; a method that is filled in needs all its details.
        'payment' => [
            'value' => ['present', 'array'],
            // Up to three bank accounts (a second, BRAC Bank, was asked for on 2026-09-19); each needs all its details.
            'value.banks' => ['nullable', 'array', 'max:'.PaymentOptions::MAX_BANKS],
            'value.banks.*.bankName' => ['required', 'string', 'max:120'],
            'value.banks.*.accountName' => ['required', 'string', 'max:160'],
            'value.banks.*.accountNumber' => ['required', 'string', 'regex:/^\d{6,20}$/'],
            'value.banks.*.branch' => ['required', 'string', 'max:120'],
            'value.banks.*.routingNumber' => ['required', 'string', 'regex:/^\d{9}$/'],
            'value.banks.*.transferType' => ['required', 'string', 'max:20'],
            'value.link' => ['nullable', 'url:https', 'max:500'],
            'value.bkash' => ['nullable', 'array'],
            'value.bkash.number' => ['required_with:value.bkash', 'string', 'regex:/^\+8801[3-9]\d{8}$/'],
            'value.bkash.chargePercent' => ['required_with:value.bkash', 'numeric', 'between:0,10', 'decimal:0,2'],
        ],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $settings = SiteSetting::query()->whereIn('key', SiteSettingKeys::STAFF_EDITABLE)->pluck('value', 'key')->all();
        // A payment setting saved before 2026-09-19 holds one `bank`; the screen edits the list.
        if (is_array($settings[SiteSettingKeys::PAYMENT] ?? null)) {
            $settings[SiteSettingKeys::PAYMENT] = PaymentOptions::normalize($settings[SiteSettingKeys::PAYMENT]);
        }

        return response()->json(['data' => (object) $settings]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, SiteSettingKeys::STAFF_EDITABLE, true), 404);

        $validated = $request->validate(self::RULES[$key]);
        $setting = SiteSetting::query()->updateOrCreate(['key' => $key], [
            'value' => $key === SiteSettingKeys::PAYMENT ? self::paymentValue($validated['value']) : $validated['value'],
            'updated_by_staff_id' => $request->user('staff')->id,
        ]);

        // No auditable subject: settings are keyed by string and audit_logs.auditable_id is numeric.
        $this->audit->record('cms.setting.updated', $request->user('staff'), changes: ['key' => $key, 'value' => $setting->value]);
        RevalidateWebsite::dispatch(['settings']);

        return response()->json(['data' => [$key => $setting->value]]);
    }

    /**
     * Only the known fields, trimmed; a method left empty is saved as null, and no banks as an empty list.
     *
     * @param  array<string, mixed>  $value
     * @return array{banks: list<array<string, string>>, link: string|null, bkash: array{number: string, chargePercent: float}|null}
     */
    private static function paymentValue(array $value): array
    {
        return PaymentOptions::normalize(['banks' => $value['banks'] ?? []] + $value);
    }
}
