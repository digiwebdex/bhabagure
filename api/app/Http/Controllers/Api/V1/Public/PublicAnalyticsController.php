<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Services\Analytics\AnalyticsRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website's analytics beacon (docs/visitor-analytics.md): POST public/analytics/events, a batch of up to ten page
 * views or steps and the visible tab's heartbeat. Always 202 once valid, recorded or not (a bot, tracking switched
 * off), so the answer says nothing about what is counted. Rate-limited per address (`analytics`).
 */
class PublicAnalyticsController extends Controller
{
    /** Bytes: a batch is a few hundred; anything near this is not the website. */
    private const MAX_BODY = 8192;

    public function store(Request $request, AnalyticsRecorder $recorder): JsonResponse
    {
        abort_if(strlen((string) $request->getContent()) > self::MAX_BODY, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        $data = $request->validate([
            'session' => ['required', 'uuid'],
            'events' => ['nullable', 'array', 'max:10'],
            'events.*.key' => ['required', 'uuid', 'distinct'],
            'events.*.type' => ['required', Rule::in(AnalyticsEvent::FROM_WEBSITE)],
            'events.*.path' => ['required', 'string', 'max:500'],
            'events.*.subject' => ['nullable', 'string', 'max:120'],
            'heartbeat' => ['nullable', 'integer', 'between:0,600'],
            'referrer' => ['nullable', 'string', 'max:1000'],
            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:200'],
            'utm.medium' => ['nullable', 'string', 'max:200'],
            'utm.campaign' => ['nullable', 'string', 'max:200'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ]);
        $recorder->record($request, $data);

        return response()->json(['data' => null], Response::HTTP_ACCEPTED);
    }
}
