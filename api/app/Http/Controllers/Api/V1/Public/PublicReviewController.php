<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Reviews\ReviewSubmissions;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website's "Share your trip" form (docs/customer-reviews.md): stars, a review and up to five photos. It waits for
 * staff and shows once approved. Rate-limited per address (`throttle:public-forms`) and per mobile number; the hidden
 * `company` field catches bots, which get the same answer and store nothing.
 */
class PublicReviewController extends Controller
{
    public function store(Request $request, ReviewSubmissions $reviews): JsonResponse
    {
        if (filled($request->input('company'))) {
            return $this->received();
        }

        $request->merge(['phone' => Phone::normalizeBdMobile((string) $request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'regex:/^8801[3-9]\d{8}$/'],
            // A package on the website, or the trip in the customer's own words.
            'package_slug' => ['nullable', 'string', 'max:190'],
            'trip' => ['nullable', 'required_without:package_slug', 'string', 'max:160'],
            'travelled_month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.now('Asia/Dhaka')->format('Y-m')],
            'rating' => ['required', 'integer', 'between:1,5'],
            'review' => ['required', 'string', 'min:20', 'max:1500'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'photos' => ['nullable', 'array', 'max:'.ReviewSubmissions::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:'.ReviewSubmissions::PHOTO_MAX_KB],
        ]);

        $today = Review::query()->where('phone', $data['phone'])->where('created_at', '>=', now()->subDay())->count();
        if ($today >= ReviewSubmissions::PER_PHONE_PER_DAY) {
            throw ValidationException::withMessages(['phone' => __('cms.review_limit')]);
        }

        $reviews->submit($data, $request->file('photos') ?? []);

        return $this->received();
    }

    private function received(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'received']], Response::HTTP_ACCEPTED);
    }
}
