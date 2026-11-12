<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site settings → Security (docs/two-step-sign-in.md): the super admin's switch that requires two-step sign-in for
 * everyone with a login, and how many have it on. Super admin only, read and write: it is no one else's to see.
 */
class SecurityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return response()->json(['data' => self::state()]);
    }

    public function update(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $by = $this->ensureSuperAdmin($request);
        $data = $request->validate(['two_factor_required' => ['required', 'boolean']]);
        $twoFactor->setRequired((bool) $data['two_factor_required'], $by);

        return response()->json(['data' => self::state()]);
    }

    /** @return array{two_factor_required: bool, with_login: int, two_factor_on: int} */
    private static function state(): array
    {
        $withLogin = Staff::query()->where('software_access', true)->where('status', '!=', StaffStatus::Suspended->value);

        return [
            'two_factor_required' => TwoFactor::required(),
            'with_login' => (clone $withLogin)->count(),
            'two_factor_on' => (clone $withLogin)->whereNotNull('two_factor_confirmed_at')->count(),
        ];
    }

    private function ensureSuperAdmin(Request $request): Staff
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        abort_unless($staff->isSuperAdmin(), Response::HTTP_FORBIDDEN, __('auth.forbidden'));

        return $staff;
    }
}
