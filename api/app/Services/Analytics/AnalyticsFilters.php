<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The report's period and filters (docs/visitor-analytics.md): Dhaka days from–to, at most 400 days, and optionally
 * one country, device, source or page. Every value is checked against a fixed list or shape; nothing typed reaches the
 * SQL except as a bound value.
 */
final class AnalyticsFilters
{
    public const MAX_DAYS = 400;

    public const DEVICES = ['desktop', 'mobile', 'tablet'];

    public const SOURCES = ['direct', 'search', 'social', 'referral', 'campaign'];

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $country,
        public readonly ?string $device,
        public readonly ?string $source,
        public readonly ?string $page,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // A two-letter code, or "unknown" for visits whose country Cloudflare didn't give.
            'country' => ['nullable', 'regex:/^([A-Z]{2}|unknown)$/'],
            'device' => ['nullable', Rule::in(self::DEVICES)],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            // `path`, not `page`: `page` is the table's page number.
            'path' => ['nullable', 'string', 'max:255', 'starts_with:/'],
        ]);
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], 'Asia/Dhaka') : $today;
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], 'Asia/Dhaka') : $to->subDays(29);
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => __('analytics.range_too_long', ['days' => self::MAX_DAYS])]);
        }

        return new self($from->startOfDay(), $to->endOfDay(), $data['country'] ?? null, $data['device'] ?? null, $data['source'] ?? null, $data['path'] ?? null);
    }

    /** The same filters over the equal period just before, for "compared with the previous period". */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to->startOfDay()) + 1;

        return new self($this->from->subDays($days), $this->from->subSecond(), $this->country, $this->device, $this->source, $this->page);
    }

    /** Days in the period, from the first. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->startOfDay()) + 1;
    }

    /** Sessions that started in the period, filtered. Times are stored in UTC. */
    public function applyToSessions(Builder $query, string $table = 'analytics_sessions'): Builder
    {
        $query->whereBetween("{$table}.started_at", [$this->from->utc(), $this->to->utc()]);
        if ($this->country !== null) {
            $this->country === 'unknown' ? $query->whereNull("{$table}.country_code") : $query->where("{$table}.country_code", $this->country);
        }
        if ($this->device !== null) {
            $query->where("{$table}.device", $this->device);
        }
        if ($this->source !== null) {
            $query->where("{$table}.source", $this->source);
        }
        if ($this->page !== null) {
            $query->whereExists(fn ($events) => $events->selectRaw('1')->from('analytics_events as page_filter')
                ->whereColumn('page_filter.session_id', "{$table}.id")->where('page_filter.type', 'page_view')->where('page_filter.path', $this->page));
        }

        return $query;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'country' => $this->country, 'device' => $this->device, 'source' => $this->source, 'path' => $this->page];
    }
}
