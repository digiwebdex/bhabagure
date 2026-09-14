<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Public\SiteSettingKeys;
use App\Http\Controllers\Controller;
use App\Jobs\RevalidateWebsite;
use App\Models\SiteSetting;
use App\Services\AuditLogger;
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
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => (object) SiteSetting::query()->whereIn('key', SiteSettingKeys::PUBLIC)->pluck('value', 'key')->all()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, SiteSettingKeys::PUBLIC, true), 404);

        $validated = $request->validate(self::RULES[$key]);
        $setting = SiteSetting::query()->updateOrCreate(['key' => $key], [
            'value' => $validated['value'],
            'updated_by_staff_id' => $request->user('staff')->id,
        ]);

        // No auditable subject: settings are keyed by string and audit_logs.auditable_id is numeric.
        $this->audit->record('cms.setting.updated', $request->user('staff'), changes: ['key' => $key, 'value' => $setting->value]);
        RevalidateWebsite::dispatch(['settings']);

        return response()->json(['data' => [$key => $setting->value]]);
    }
}
