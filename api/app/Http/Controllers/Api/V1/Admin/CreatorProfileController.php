<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\SiteSetting;
use App\Services\AuditLogger;
use App\Support\CreatorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The travel host on the home page (docs/travel-host.md): name, a short bio, and a card each for their Facebook page and
 * YouTube channel with the follower count and photos. The videos under the cards are CreatorVideoController's.
 */
class CreatorProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => AdminContent::creatorProfile(SiteSetting::get(CreatorProfile::KEY))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_bn' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'bio_bn' => ['nullable', 'string', 'max:600'],
            'bio_en' => ['nullable', 'string', 'max:600'],
            // A card without a link goes nowhere, so at least one of the two.
            'facebook_url' => ['nullable', 'required_without:youtube_url', 'url:https', 'max:255', 'regex:'.CreatorProfile::FACEBOOK_URL],
            'facebook_followers' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'facebook_photo_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'facebook_cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'youtube_url' => ['nullable', 'required_without:facebook_url', 'url:https', 'max:255', 'regex:'.CreatorProfile::YOUTUBE_URL],
            'youtube_subscribers' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'youtube_video_count' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'youtube_photo_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'youtube_cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ], [
            'facebook_url.required_without' => __('cms.creator_link'),
            'youtube_url.required_without' => __('cms.creator_link'),
            'facebook_url.regex' => __('cms.creator_facebook_url'),
            'youtube_url.regex' => __('cms.creator_youtube_url'),
        ]);

        $value = CreatorProfile::fromForm($data);
        SiteSetting::query()->updateOrCreate(['key' => CreatorProfile::KEY], ['value' => $value, 'updated_by_staff_id' => $request->user('staff')?->id]);

        // No auditable subject: settings are keyed by string and audit_logs.auditable_id is numeric.
        $this->audit->record('cms.creator.updated', $request->user('staff'), changes: ['key' => CreatorProfile::KEY, 'value' => $value]);
        RevalidateWebsite::dispatch(['creator']);

        return response()->json(['data' => AdminContent::creatorProfile($value)]);
    }
}
