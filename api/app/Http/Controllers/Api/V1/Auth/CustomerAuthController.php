<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\Auth\RefreshTokens;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CustomerAuthController extends Controller
{
    use IssuesTokens;

    /** Decided 2026-09-13: the portal holds passport numbers and travel documents. web/src/features/auth uses the same value. */
    public const MIN_PASSWORD = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    protected function guardName(): string
    {
        return 'customer';
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'phone' => Phone::normalizeBdMobile($request->input('phone')) ?? $request->input('phone'),
            'email' => $request->filled('email') ? mb_strtolower(trim((string) $request->input('email'))) : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'regex:/^8801[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD, 'max:255'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);

        // Checked only after everything else is valid, and answered without saying who the number belongs to.
        if ($field = $this->alreadyOnFile($data['phone'], $data['email'])) {
            return $this->contactUs($field);
        }

        try {
            $customer = Customer::query()->create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => $data['password'],
                'stage' => 'lead',
                'source' => 'website_form',
                'locale' => $data['locale'] ?? 'bn',
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->contactUs('phone'); // Two registrations for the same number at the same moment.
        }

        $customer->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.customer.registered', $customer, $customer);

        return $this->tokenResponse($customer, $request, ['customer' => new CustomerResource($customer)], Response::HTTP_CREATED);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $identifier = trim($data['identifier']);
        $key = $this->throttleKey($identifier, $request);
        $this->ensureNotRateLimited($key);

        $phone = Phone::normalizeBdMobile($identifier);
        $customer = Customer::query()
            ->when($phone !== null,
                fn ($query) => $query->where('phone', $phone),
                fn ($query) => $query->where('email', mb_strtolower($identifier)))
            ->first();

        if ($customer === null || $customer->password === null || ! Hash::check($data['password'], $customer->password)) {
            RateLimiter::hit($key, 60);
            $this->audit->record('auth.customer.login_failed', subject: $customer, changes: ['identifier' => $identifier]);

            return $this->invalidCredentials();
        }

        RateLimiter::clear($key);
        $customer->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.customer.login', $customer, $customer);

        return $this->tokenResponse($customer, $request, ['customer' => new CustomerResource($customer)]);
    }

    public function refresh(Request $request, RefreshTokens $tokens): JsonResponse
    {
        $rotated = $tokens->rotate('customer', $request->cookie(RefreshTokens::cookieName('customer')), $request);
        $customer = $rotated === null ? null : Customer::query()->find($rotated['subject_id']);

        if ($customer === null) {
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
     * A phone or email already on file — often a lead staff recorded from WhatsApp — can't be claimed by
     * registering: without an SMS code there is no proof the person owns it.
     */
    private function alreadyOnFile(string $phone, ?string $email): ?string
    {
        if (Customer::query()->where('phone', $phone)->exists()) {
            return 'phone';
        }

        return $email !== null && Customer::query()->where('email', $email)->exists() ? 'email' : null;
    }

    /**
     * Deliberately neutral: it must not confirm that the number or address belongs to one of our customers.
     * The website shows it with a WhatsApp button.
     */
    private function contactUs(string $field): JsonResponse
    {
        return response()->json([
            'message' => __("auth.register_contact_us_{$field}"),
            'code' => 'contact_us',
            'field' => $field,
        ], Response::HTTP_CONFLICT);
    }
}
