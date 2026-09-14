<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\InquiryType;
use App\Events\InquiryReceived;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\NewsletterSubscriber;
use App\Models\TourPackage;
use App\Support\NewsletterUnsubscribeToken;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website forms. Rate-limited per IP (route middleware `throttle:public-forms`). Every form carries a
 * hidden `company` field: people never see it, bots fill it in. A filled honeypot gets the same 202 as a
 * real submission, so a bot learns nothing, but nothing is stored.
 */
class PublicFormController extends Controller
{
    public function inquiry(Request $request): JsonResponse
    {
        if ($this->isBot($request)) {
            return $this->accepted();
        }

        $this->normalizePhone($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'regex:/^8801[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'package_slug' => ['nullable', 'string', 'max:190'],
            'travellers' => ['nullable', 'integer', 'min:1', 'max:99'],
            'message' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);

        $package = empty($data['package_slug']) ? null
            : TourPackage::query()->published()->where('slug', $data['package_slug'])->first();

        $inquiry = Inquiry::query()->create([
            'type' => InquiryType::Contact,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'tour_package_id' => $package?->id,
            'pax' => $data['travellers'] ?? null,
            'details' => array_filter(['message' => $data['message'] ?? null]) ?: null,
            'locale' => $data['locale'] ?? 'bn',
            'customer_id' => $this->existingCustomerId($data['phone']),
            'ip' => $request->ip(),
        ]);
        InquiryReceived::dispatch($inquiry);

        return $this->accepted();
    }

    public function airQuote(Request $request): JsonResponse
    {
        if ($this->isBot($request)) {
            return $this->accepted();
        }

        $this->normalizePhone($request);
        $data = $request->validate([
            'from_place' => ['required', 'string', 'max:120'],
            'to_place' => ['required', 'string', 'max:120'],
            'depart_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'return_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:depart_on'],
            'passengers' => ['required', 'integer', 'min:1', 'max:99'],
            'cabin_class' => ['required', Rule::in(['economy', 'premium', 'business', 'first'])],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'regex:/^8801[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);

        $inquiry = Inquiry::query()->create([
            'type' => InquiryType::AirQuote,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'pax' => $data['passengers'],
            'details' => [
                'from' => $data['from_place'],
                'to' => $data['to_place'],
                'departOn' => $data['depart_on'],
                'returnOn' => $data['return_on'] ?? null,
                'cabinClass' => $data['cabin_class'],
            ],
            'locale' => $data['locale'] ?? 'bn',
            'customer_id' => $this->existingCustomerId($data['phone']),
            'ip' => $request->ip(),
        ]);
        InquiryReceived::dispatch($inquiry);

        return $this->accepted();
    }

    public function subscribe(Request $request): JsonResponse
    {
        if ($this->isBot($request)) {
            return $this->accepted();
        }

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);

        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $data['email']]);
        if (! $subscriber->exists || $subscriber->status !== 'subscribed') {
            $subscriber->fill([
                'locale' => $data['locale'] ?? 'bn',
                'status' => 'subscribed',
                'unsubscribe_token' => $subscriber->unsubscribe_token ?? Str::random(40),
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ])->save();
        }

        // Same answer whether or not the address was already on the list: the form can't be used to test addresses.
        return $this->accepted();
    }

    /** Shows what the link would do. Unsubscribing itself is a POST, so mail scanners that open links can't trigger it. */
    public function unsubscribeStatus(string $token): JsonResponse
    {
        $subscriber = $this->subscriberFor($token);

        return response()->json(['data' => [
            'email' => Str::mask($subscriber->email, '*', 2, max(1, strpos($subscriber->email, '@') - 2)),
            'status' => $subscriber->status,
        ]]);
    }

    public function unsubscribe(string $token): JsonResponse
    {
        $subscriber = $this->subscriberFor($token);
        if ($subscriber->status !== 'unsubscribed') {
            $subscriber->forceFill(['status' => 'unsubscribed', 'unsubscribed_at' => now()])->save();
        }

        return response()->json(['data' => ['status' => 'unsubscribed']]);
    }

    /** A signed token from the unsubscribe link (NewsletterUnsubscribeToken); no login needed. */
    private function subscriberFor(string $token): NewsletterSubscriber
    {
        return NewsletterUnsubscribeToken::resolve($token)
            ?? abort(response()->json(['message' => __('forms.invalid_unsubscribe_link'), 'code' => 'invalid_link'], Response::HTTP_FORBIDDEN));
    }

    private function isBot(Request $request): bool
    {
        return filled($request->input('company'));
    }

    private function normalizePhone(Request $request): void
    {
        $request->merge(['phone' => Phone::normalizeBdMobile($request->input('phone')) ?? $request->input('phone')]);
    }

    private function existingCustomerId(string $phone): ?int
    {
        return Customer::query()->where('phone', $phone)->value('id');
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'received']], Response::HTTP_ACCEPTED);
    }
}
