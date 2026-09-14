<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\Auth\RefreshTokens;
use App\Services\Customers\LoginCodes;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer portal sign-in by one-time code — no passwords (docs/phase-6-customer-portal.md §0.1, §3.1).
 *
 *   POST code    { phone }             → 202 whatever the number: nothing says whether it belongs to a customer.
 *   POST verify  { phone, code, name? } → signed in. The first code on a number with a record claims that record;
 *                                         a new number also needs a name and becomes a lead.
 *
 * Sessions are the existing ones: a 15-minute access token, and a rotating httpOnly refresh cookie on the API host.
 */
class CustomerAuthController extends Controller
{
    use IssuesTokens;

    public function __construct(private readonly AuditLogger $audit) {}

    protected function guardName(): string
    {
        return 'customer';
    }

    public function sendCode(Request $request, LoginCodes $codes): JsonResponse
    {
        $data = $this->validatePhone($request, ['locale' => ['nullable', Rule::in(['bn', 'en'])]]);
        $result = $codes->send($data['phone'], $data['locale'] ?? $request->header('X-Locale', 'bn'), $request->ip());

        return match ($result['outcome']) {
            'throttled' => response()->json(['message' => __('auth.code_throttled', ['seconds' => $result['retry_after']]), 'code' => 'throttled', 'retry_after' => $result['retry_after']], Response::HTTP_TOO_MANY_REQUESTS),
            // Only the gateways decide this, not the number: no customer is revealed.
            'undeliverable' => response()->json(['message' => __('auth.code_undeliverable'), 'code' => 'code_undeliverable'], Response::HTTP_SERVICE_UNAVAILABLE),
            default => response()->json(['data' => ['status' => 'sent', 'expires_in' => LoginCodes::MINUTES * 60, 'retry_after' => $result['retry_after']]], Response::HTTP_ACCEPTED),
        };
    }

    public function verify(Request $request, LoginCodes $codes): JsonResponse
    {
        $data = $this->validatePhone($request, [
            'code' => ['required', 'digits:6'],
            'name' => ['nullable', 'string', 'min:2', 'max:160'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);

        $match = $codes->match($data['phone'], $data['code']);
        if ($match === null) {
            return response()->json(['message' => __('auth.code_invalid'), 'code' => 'invalid_code'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $customer = Customer::query()->where('phone', $data['phone'])->first();
        if ($customer === null && blank($data['name'] ?? null)) {
            // The code stays valid: the person proved the number and only has to say who they are.
            return response()->json(['message' => __('auth.name_required'), 'code' => 'name_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($customer?->portal_disabled_at !== null) {
            $codes->consume($match);
            $this->audit->record('auth.customer.login_blocked', $customer, $customer);

            return response()->json(['message' => __('auth.portal_disabled'), 'code' => 'portal_disabled'], Response::HTTP_FORBIDDEN);
        }

        try {
            $customer = DB::transaction(function () use ($customer, $data, $codes, $match) {
                $codes->consume($match);
                $customer ??= Customer::query()->create([
                    'name' => trim($data['name']), 'phone' => $data['phone'], 'stage' => 'lead',
                    'source' => LeadSource::WebsiteForm->value, 'locale' => $data['locale'] ?? 'bn',
                ]);
                $claimed = $customer->portal_claimed_at === null;
                $customer->forceFill([
                    'phone_verified_at' => $customer->phone_verified_at ?? now(),
                    'portal_claimed_at' => $customer->portal_claimed_at ?? now(),
                    'last_login_at' => now(),
                ])->save();
                $this->audit->record($claimed ? 'auth.customer.portal_claimed' : 'auth.customer.login', $customer, $customer, ['channel' => $match->channel]);

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            // Two first sign-ins for a new number at the same moment: the other one created the record.
            return response()->json(['message' => __('auth.code_invalid'), 'code' => 'invalid_code'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->tokenResponse($customer, $request, ['customer' => new CustomerResource($customer)]);
    }

    public function refresh(Request $request, RefreshTokens $tokens): JsonResponse
    {
        $rotated = $tokens->rotate('customer', $request->cookie(RefreshTokens::cookieName('customer')), $request);
        $customer = $rotated === null ? null : Customer::query()->find($rotated['subject_id']);

        if ($customer === null || $customer->portal_disabled_at !== null) {
            return $this->refreshFailed();
        }

        return $this->tokenResponse($customer, $request, ['customer' => new CustomerResource($customer)], refreshToken: $rotated['token']);
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->endSession($request);
    }

    public function me(Request $request): CustomerResource
    {
        return new CustomerResource($request->user('customer'));
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validatePhone(Request $request, array $rules): array
    {
        // 01711-000001, +880 1711… and Bangla digits all mean 8801711000001.
        $request->merge(['phone' => Phone::normalizeBdMobile((string) $request->input('phone')) ?? $request->input('phone')]);

        return $request->validate(['phone' => ['required', 'string', 'regex:/^8801[3-9]\d{8}$/']] + $rules);
    }
}
