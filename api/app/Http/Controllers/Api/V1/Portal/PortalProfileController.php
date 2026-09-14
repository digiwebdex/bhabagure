<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\AuditLogger;
use App\Services\Customers\LoginCodes;
use App\Services\Customers\ProfileChanges;
use App\Services\Portal\NpsSurvey;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile (docs/phase-6-customer-portal.md §3.6): name, address, language and WhatsApp messages on or off; a new email or
 * phone number is confirmed with a code sent to it. And the one NPS question after a completed trip (§0.4).
 */
class PortalProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::profile(PortalTripController::customer($request))]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'address' => ['nullable', 'string', 'max:300'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'whatsapp_opted_out' => ['required', 'boolean'],
        ]);

        $customer->fill(['name' => trim($data['name']), 'address' => filled($data['address'] ?? null) ? trim($data['address']) : null, 'locale' => $data['locale']]);
        if ($data['whatsapp_opted_out'] !== ($customer->whatsapp_opted_out_at !== null)) {
            $customer->forceFill(['whatsapp_opted_out_at' => $data['whatsapp_opted_out'] ? now() : null]);
        }
        $changed = array_keys($customer->getDirty());
        if ($changed !== []) {
            $customer->save();
            $audit->record('customer.profile_updated', $customer, $customer, ['fields' => $changed]);
        }

        return response()->json(['data' => self::profile($customer)]);
    }

    public function requestEmail(Request $request, ProfileChanges $changes): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $email = mb_strtolower(trim((string) $request->validate(['email' => ['required', 'email', 'max:190']])['email']));
        if ($email === mb_strtolower((string) $customer->email)) {
            return response()->json(['message' => __('portal.same_email'), 'code' => 'same_email'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $changes->requestEmail($customer, $email, app()->getLocale()) === 'throttled'
            ? response()->json(['message' => __('portal.too_many_codes'), 'code' => 'throttled'], Response::HTTP_TOO_MANY_REQUESTS)
            : response()->json(['data' => ['status' => 'sent', 'expires_in' => ProfileChanges::EMAIL_MINUTES * 60]], Response::HTTP_ACCEPTED);
    }

    public function confirmEmail(Request $request, ProfileChanges $changes): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];

        return self::changeOutcome($changes->confirmEmail($customer, $code), 'email_unavailable', $customer);
    }

    public function requestPhoneCode(Request $request, LoginCodes $codes): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $phone = self::phone($request);
        if ($phone === $customer->phone) {
            return response()->json(['message' => __('portal.same_phone'), 'code' => 'same_phone'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $result = $codes->send($phone, app()->getLocale(), $request->ip(), LoginCodes::CHANGE_PHONE);

        return match ($result['outcome']) {
            'throttled' => response()->json(['message' => __('auth.code_throttled', ['seconds' => $result['retry_after']]), 'code' => 'throttled', 'retry_after' => $result['retry_after']], Response::HTTP_TOO_MANY_REQUESTS),
            'undeliverable' => response()->json(['message' => __('auth.code_undeliverable'), 'code' => 'code_undeliverable'], Response::HTTP_SERVICE_UNAVAILABLE),
            default => response()->json(['data' => ['status' => 'sent', 'expires_in' => LoginCodes::MINUTES * 60, 'retry_after' => $result['retry_after']]], Response::HTTP_ACCEPTED),
        };
    }

    public function changePhone(Request $request, ProfileChanges $changes): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $phone = self::phone($request);
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];

        return self::changeOutcome($changes->changePhone($customer, $phone, $code), 'phone_unavailable', $customer);
    }

    public function nps(Request $request): JsonResponse
    {
        $booking = NpsSurvey::pending(PortalTripController::customer($request));
        $en = app()->getLocale() === 'en';

        return response()->json(['data' => ['prompt' => $booking ? [
            'reference' => $booking->reference,
            'title' => $en ? $booking->package_title_en : ($booking->package_title_bn ?: $booking->package_title_en),
            'travelEnd' => $booking->travel_end?->toDateString(),
        ] : null]]);
    }

    public function answerNps(Request $request, string $reference, NpsSurvey $survey): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $booking = PortalTripController::bookingsOf($customer)->where('reference', $reference)->where('status', 'completed')->first();
        abort_if(! $booking instanceof Booking, Response::HTTP_NOT_FOUND, __('portal.not_found'));
        $data = $request->validate(['score' => ['required', 'integer', 'between:0,10'], 'comment' => ['nullable', 'string', 'max:1000']]);

        $response = $survey->record($booking, $customer, (int) $data['score'], filled($data['comment'] ?? null) ? trim($data['comment']) : null);
        if ($response === null) {
            return response()->json(['message' => __('portal.nps_answered'), 'code' => 'already_answered'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => [
            'score' => $response->score,
            'reviewUrl' => $response->score >= $response::PROMOTER_FROM ? NpsSurvey::reviewUrl() : null,
            'followUp' => $response->score <= $response::DETRACTOR_UP_TO,
        ]], Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private static function profile(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'locale' => $customer->locale ?? 'bn',
            'whatsappOptedOut' => $customer->whatsapp_opted_out_at !== null,
        ];
    }

    private static function changeOutcome(string $outcome, string $unavailableCode, Customer $customer): JsonResponse
    {
        return match ($outcome) {
            'changed' => response()->json(['data' => self::profile($customer->refresh())]),
            'unavailable' => response()->json(['message' => __("portal.{$unavailableCode}"), 'code' => $unavailableCode], Response::HTTP_CONFLICT),
            default => response()->json(['message' => __('auth.code_invalid'), 'code' => 'invalid_code'], Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }

    private static function phone(Request $request): string
    {
        $request->merge(['phone' => Phone::normalizeBdMobile((string) $request->input('phone')) ?? $request->input('phone')]);

        return $request->validate(['phone' => ['required', 'string', 'regex:/^8801[3-9]\d{8}$/']])['phone'];
    }
}
