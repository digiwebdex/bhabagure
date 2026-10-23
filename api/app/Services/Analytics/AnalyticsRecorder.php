<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records the website's visits (docs/visitor-analytics.md). Best effort and silent: a failure here is logged and
 * never reaches the visitor or the booking that called it. The IP is read once, for the day's visitor code and
 * nothing else, and never stored.
 */
final class AnalyticsRecorder
{
    /** A session older than this takes no more events: a tab can't keep one session for days. */
    private const MAX_SESSION_HOURS = 12;

    /** The header the website sends its session id in, on bookings and forms, for conversions. */
    public const SESSION_HEADER = 'X-Analytics-Session';

    /**
     * The website's batch: page views and steps, and the heartbeat of a visible tab.
     *
     * @param  array{session: string, events?: list<array<string, mixed>>, heartbeat?: int|null, referrer?: string|null, utm?: array<string, string|null>|null, locale?: string|null}  $payload
     */
    public function record(Request $request, array $payload): void
    {
        $settings = AnalyticsSettings::get();
        if (! $settings['enabled']) {
            return;
        }
        $agent = UserAgent::classify($request->userAgent(), (bool) config('bhabaghure.analytics.allow_headless'));
        if ($agent['bot']) {
            return;
        }

        try {
            DB::transaction(function () use ($request, $payload, $settings, $agent) {
                $session = AnalyticsSession::query()->where('token', $payload['session'])->lockForUpdate()->first();
                if ($session !== null && $session->started_at->lt(now()->subHours(self::MAX_SESSION_HOURS))) {
                    return;
                }
                foreach ($payload['events'] ?? [] as $event) {
                    $path = Paths::normalize($event['path'] ?? null, $settings['excludedPaths']);
                    if ($path === null) {
                        continue;
                    }
                    $session ??= $this->start($request, $payload, $path, $agent);
                    $this->store($session, (string) $event['key'], (string) $event['type'], $path, $event['subject'] ?? null);
                }
                if ($session !== null && ($payload['heartbeat'] ?? null) !== null) {
                    // At most a minute per beat: a sleeping laptop's late beat doesn't add hours.
                    $session->engaged_seconds += max(0, min(60, (int) $payload['heartbeat']));
                }
                if ($session !== null) {
                    $session->last_seen_at = now();
                    $session->save();
                }
            });
        } catch (Throwable $e) {
            Log::warning('analytics: an event was not recorded', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A conversion, when the action itself has succeeded (a booking saved, an enquiry received): recorded on the
     * website session that sent it, once per `$key`. Without a known session — a staff booking, a visitor whose
     * browser sends no analytics — nothing is recorded.
     */
    public function conversion(Request $request, string $type, string $key, ?string $subject = null): void
    {
        $token = (string) $request->header(self::SESSION_HEADER);
        if (! Str::isUuid($token) || ! in_array($type, AnalyticsEvent::CONVERSIONS, true) || ! AnalyticsSettings::get()['enabled']) {
            return;
        }
        try {
            $session = AnalyticsSession::query()->where('token', $token)->first();
            if ($session === null) {
                return;
            }
            $this->store($session, hash('sha256', "conversion|{$type}|{$key}"), $type, $session->last_path, $subject);
            $session->forceFill(['last_seen_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('analytics: a conversion was not recorded', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{bot: bool, device: string, browser: string, os: string}  $agent
     */
    private function start(Request $request, array $payload, string $path, array $agent): AnalyticsSession
    {
        $utm = is_array($payload['utm'] ?? null) ? $payload['utm'] : [];
        $clean = fn ($value) => is_string($value) && trim($value) !== '' ? mb_substr(strip_tags(trim($value)), 0, 100) : null;
        $utm = ['source' => $clean($utm['source'] ?? null), 'medium' => $clean($utm['medium'] ?? null), 'campaign' => $clean($utm['campaign'] ?? null)];
        $from = Referrers::classify($payload['referrer'] ?? null, $utm);
        $locale = in_array($payload['locale'] ?? null, ['bn', 'en'], true) ? $payload['locale'] : null;

        return AnalyticsSession::query()->create([
            'token' => $payload['session'],
            'visitor_hash' => self::visitorHash((string) $request->ip(), (string) $request->userAgent()),
            'started_at' => now(),
            'last_seen_at' => now(),
            'landing_path' => $path,
            'last_path' => $path,
            'locale' => $locale,
            'source' => $from['source'],
            'referrer_domain' => $from['domain'],
            'utm_source' => $utm['source'],
            'utm_medium' => $utm['medium'],
            'utm_campaign' => $utm['campaign'],
            'device' => $agent['device'],
            'browser' => $agent['browser'],
            'os' => $agent['os'],
        ] + VisitorLocation::fromRequest($request));
    }

    private function store(AnalyticsSession $session, string $key, string $type, string $path, ?string $subject): void
    {
        $subject = $subject !== null ? mb_substr(preg_replace('/[^a-z0-9:_-]/', '', mb_strtolower($subject)) ?? '', 0, 120) : null;
        try {
            AnalyticsEvent::query()->create([
                'session_id' => $session->id,
                'event_key' => mb_substr($key, 0, 64),
                'type' => $type,
                'path' => $path,
                'subject' => $subject ?: ($type === AnalyticsEvent::PAGE_VIEW ? Paths::subject($path) : null),
                'occurred_at' => now(),
            ]);
        } catch (QueryException $e) {
            // The same event again (a retried request): stored once already.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return;
            }
            throw $e;
        }
        if ($type === AnalyticsEvent::PAGE_VIEW) {
            $session->page_views++;
            $session->last_path = $path;
        }
    }

    /**
     * "The same browser today", without keeping who: a SHA-256 of the day's random salt, the IP and the browser. The
     * salt lives two days in the cache and is then gone, so yesterday's codes can't be recomputed or linked to today's.
     */
    public static function visitorHash(string $ip, string $agent): string
    {
        $day = now('Asia/Dhaka')->toDateString();
        $salt = Cache::remember("analytics:salt:{$day}", now()->addDays(2), fn () => bin2hex(random_bytes(32)));

        return hash('sha256', "{$salt}|{$day}|{$ip}|{$agent}");
    }
}
