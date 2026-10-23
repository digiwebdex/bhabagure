<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Locale;

/**
 * The figures behind Admin → Visitor analytics (docs/visitor-analytics.md, "What the numbers mean"). Sessions are
 * counted by when they started; visitors are distinct daily visitor codes (someone back on three days counts three
 * times); page views and conversions belong to the sessions in the period.
 */
final class AnalyticsReports
{
    /** Session breakdowns: the report's name → the columns it groups by. Fixed: nothing from the request is a column. */
    public const SESSION_DIMENSIONS = [
        'country' => ['country_code'],
        'region' => ['country_code', 'region'],
        'city' => ['country_code', 'region', 'city'],
        'landing' => ['landing_path'],
        'exit' => ['last_path'],
        'source' => ['source'],
        'referrer' => ['referrer_domain'],
        'campaign' => ['utm_source', 'utm_medium', 'utm_campaign'],
        'device' => ['device'],
        'browser' => ['browser'],
        'os' => ['os'],
        'language' => ['locale'],
    ];

    /** Event breakdowns: pages viewed, tours and visas looked at, conversions by kind. */
    public const EVENT_DIMENSIONS = ['page', 'tour', 'conversion'];

    public const PER_PAGE = 25;

    /** UTC offset of Dhaka (no daylight saving), for grouping by the office's day. */
    private const DHAKA = '+06:00';

    /** @return array<string, mixed> */
    public function summary(AnalyticsFilters $filters): array
    {
        $current = $this->totals($filters);
        $previous = $this->totals($filters->previous());

        return [
            'filters' => $filters->toArray(),
            'totals' => $current,
            'previous' => $previous,
            'series' => $this->series($filters),
            'active' => $this->activeCount(),
            'activeMinutes' => AnalyticsSettings::get()['activeMinutes'],
            'countryCoverage' => $current['sessions'] > 0 ? round($current['locatedSessions'] / $current['sessions'] * 100, 1) : null,
        ];
    }

    /** @return array{sessions: int, visitors: int, pageViews: int, viewsPerSession: float, engagedSeconds: int, bounceRate: float|null, countries: int, conversions: int, convertingSessions: int, conversionRate: float|null, locatedSessions: int} */
    public function totals(AnalyticsFilters $filters): array
    {
        $row = $filters->applyToSessions(AnalyticsSession::query())
            ->selectRaw('COUNT(*) AS sessions, COUNT(DISTINCT visitor_hash) AS visitors, COALESCE(SUM(page_views), 0) AS page_views')
            ->selectRaw('COALESCE(AVG(engaged_seconds), 0) AS engaged, SUM(page_views <= 1) AS bounces, COUNT(DISTINCT country_code) AS countries, SUM(country_code IS NOT NULL) AS located')
            ->toBase()->first();
        $sessions = (int) $row->sessions;
        $conversions = AnalyticsEvent::query()->whereIn('type', AnalyticsEvent::CONVERSIONS)
            ->whereIn('session_id', $filters->applyToSessions(AnalyticsSession::query())->select('id'))
            ->selectRaw('COUNT(*) AS conversions, COUNT(DISTINCT session_id) AS sessions')->toBase()->first();

        return [
            'sessions' => $sessions,
            'visitors' => (int) $row->visitors,
            'pageViews' => (int) $row->page_views,
            'viewsPerSession' => $sessions > 0 ? round($row->page_views / $sessions, 2) : 0.0,
            'engagedSeconds' => (int) round((float) $row->engaged),
            'bounceRate' => $sessions > 0 ? round($row->bounces / $sessions * 100, 1) : null,
            'countries' => (int) $row->countries,
            'conversions' => (int) $conversions->conversions,
            'convertingSessions' => (int) $conversions->sessions,
            'conversionRate' => $sessions > 0 ? round($conversions->sessions / $sessions * 100, 2) : null,
            'locatedSessions' => (int) $row->located,
        ];
    }

    /** @return list<array{date: string, sessions: int, visitors: int, pageViews: int}> */
    public function series(AnalyticsFilters $filters): array
    {
        $rows = $filters->applyToSessions(AnalyticsSession::query())
            ->selectRaw("DATE(CONVERT_TZ(started_at, '+00:00', '".self::DHAKA."')) AS day, COUNT(*) AS sessions, COUNT(DISTINCT visitor_hash) AS visitors, COALESCE(SUM(page_views), 0) AS page_views")
            ->groupBy('day')->toBase()->get()->keyBy('day');
        $days = [];
        for ($day = $filters->from; $day->lte($filters->to); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $days[] = ['date' => $day->toDateString(), 'sessions' => (int) ($row->sessions ?? 0), 'visitors' => (int) ($row->visitors ?? 0), 'pageViews' => (int) ($row->page_views ?? 0)];
        }

        return $days;
    }

    /**
     * One breakdown, biggest first, a page of rows at a time.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, perPage: int, sessions: int}
     */
    public function breakdown(AnalyticsFilters $filters, string $dimension, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page);
        $all = $this->totals($filters)['sessions'];

        if (isset(self::SESSION_DIMENSIONS[$dimension])) {
            $columns = self::SESSION_DIMENSIONS[$dimension];
            $grouped = $filters->applyToSessions(AnalyticsSession::query())
                // Campaigns are the visits that carried utm tags; the rest aren't a campaign.
                ->when($dimension === 'campaign', fn (Builder $query) => $query->whereNotNull('utm_source'))
                ->select($columns)
                ->selectRaw('COUNT(*) AS sessions, COUNT(DISTINCT visitor_hash) AS visitors, COALESCE(SUM(page_views), 0) AS page_views')
                ->selectRaw('SUM(EXISTS (SELECT 1 FROM analytics_events AS conversion WHERE conversion.session_id = analytics_sessions.id AND conversion.type IN ('.implode(',', array_fill(0, count(AnalyticsEvent::CONVERSIONS), '?')).'))) AS converting', AnalyticsEvent::CONVERSIONS)
                ->groupBy($columns);
            $total = DB::query()->fromSub((clone $grouped)->toBase(), 'grouped')->count();
            // Biggest first; among equals, "Unknown" (no value) after the named ones.
            $rows = $grouped->orderByDesc('sessions')->orderByRaw("{$columns[0]} IS NULL")->orderBy($columns[0])->forPage($page, $perPage)->toBase()->get()
                ->map(fn ($row) => [
                    'key' => array_combine($columns, array_map(fn (string $column) => $row->{$column}, $columns)),
                    'label' => $this->label($dimension, $row),
                    'sessions' => (int) $row->sessions,
                    'visitors' => (int) $row->visitors,
                    'pageViews' => (int) $row->page_views,
                    'convertingSessions' => (int) $row->converting,
                    'share' => $all > 0 ? round($row->sessions / $all * 100, 1) : 0.0,
                ])->all();

            return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'sessions' => $all];
        }

        $events = AnalyticsEvent::query()->whereIn('session_id', $filters->applyToSessions(AnalyticsSession::query())->select('id'));
        [$column, $events] = match ($dimension) {
            'page' => ['path', $events->where('type', AnalyticsEvent::PAGE_VIEW)],
            'tour' => ['subject', $events->whereIn('type', [AnalyticsEvent::PAGE_VIEW, AnalyticsEvent::PACKAGE_VIEW, AnalyticsEvent::BOOKING_STARTED, 'booking_completed'])->whereNotNull('subject')->where(fn (Builder $q) => $q->where('subject', 'like', 'package:%')->orWhere('subject', 'like', 'visa:%'))],
            'conversion' => ['type', $events->whereIn('type', AnalyticsEvent::CONVERSIONS)],
        };
        $grouped = (clone $events)->select($column)
            ->selectRaw('COUNT(*) AS events, COUNT(DISTINCT session_id) AS sessions')
            ->selectRaw("SUM(type IN ('page_view', 'package_view')) AS views, SUM(type = 'booking_started') AS started, SUM(type = 'booking_completed') AS booked")
            ->groupBy($column);
        $total = DB::query()->fromSub((clone $grouped)->toBase(), 'grouped')->count();
        $rows = $grouped->orderByDesc($dimension === 'tour' ? 'views' : 'events')->orderBy($column)->forPage($page, $perPage)->toBase()->get()
            ->map(fn ($row) => [
                'key' => [$column => $row->{$column}],
                'label' => (string) $row->{$column},
                'events' => (int) $row->events,
                'sessions' => (int) $row->sessions,
                'views' => (int) $row->views,
                'bookingsStarted' => (int) $row->started,
                'bookings' => (int) $row->booked,
                'share' => $all > 0 ? round($row->sessions / $all * 100, 1) : 0.0,
            ])->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'sessions' => $all];
    }

    /**
     * Sessions seen in the active window (Settings, five minutes by default) — "live" means that, never an old visit —
     * and the latest page views of the last day.
     *
     * @return array<string, mixed>
     */
    public function live(): array
    {
        $minutes = AnalyticsSettings::get()['activeMinutes'];
        $sessions = AnalyticsSession::query()->where('last_seen_at', '>=', now()->subMinutes($minutes))->orderByDesc('last_seen_at')->limit(100)->get()
            ->map(fn (AnalyticsSession $session) => [
                'country' => $session->country_code,
                'countryName' => self::countryName($session->country_code),
                'region' => $session->region,
                'city' => $session->city,
                'page' => $session->last_path,
                'landing' => $session->landing_path,
                'pageViews' => $session->page_views,
                'startedAt' => $session->started_at->toIso8601String(),
                'lastSeenAt' => $session->last_seen_at->toIso8601String(),
                'device' => $session->device,
                'source' => $session->source,
                'referrer' => $session->referrer_domain,
            ])->all();
        $recent = AnalyticsEvent::query()->join('analytics_sessions', 'analytics_sessions.id', '=', 'analytics_events.session_id')
            ->where('analytics_events.occurred_at', '>=', now()->subDay())->whereIn('analytics_events.type', [AnalyticsEvent::PAGE_VIEW, ...AnalyticsEvent::CONVERSIONS])
            ->orderByDesc('analytics_events.id')->limit(50)
            ->get(['analytics_events.type', 'analytics_events.path', 'analytics_events.occurred_at', 'analytics_sessions.country_code', 'analytics_sessions.city', 'analytics_sessions.device'])
            ->map(fn ($event) => [
                'type' => $event->type,
                'page' => $event->path,
                'at' => CarbonImmutable::parse($event->getRawOriginal('occurred_at'), 'UTC')->toIso8601String(),
                'country' => $event->country_code,
                'countryName' => self::countryName($event->country_code),
                'city' => $event->city,
                'device' => $event->device,
            ])->all();

        return ['activeMinutes' => $minutes, 'sessions' => $sessions, 'recent' => $recent];
    }

    public function activeCount(): int
    {
        return AnalyticsSession::query()->where('last_seen_at', '>=', now()->subMinutes(AnalyticsSettings::get()['activeMinutes']))->count();
    }

    /** The country's English name from its ISO code ("BD" → "Bangladesh"); null for unknown. */
    public static function countryName(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, 'en') : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }

    private function label(string $dimension, object $row): string
    {
        $unknown = 'Unknown';

        return match ($dimension) {
            'country' => self::countryName($row->country_code) ?? $unknown,
            'region' => implode(', ', array_filter([$row->region, self::countryName($row->country_code)])) ?: $unknown,
            'city' => implode(', ', array_filter([$row->city, $row->region, self::countryName($row->country_code)])) ?: $unknown,
            'referrer' => $row->referrer_domain ?? '(none)',
            'campaign' => implode(' / ', array_map(fn ($value) => $value ?? '—', [$row->utm_source, $row->utm_medium, $row->utm_campaign])),
            'language' => match ($row->locale) {
                'bn' => 'বাংলা', 'en' => 'English', default => $unknown
            },
            default => (string) ($row->{self::SESSION_DIMENSIONS[$dimension][0]} ?? $unknown),
        };
    }
}
