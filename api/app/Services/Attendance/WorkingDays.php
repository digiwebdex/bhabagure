<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRule;
use App\Models\Holiday;
use Carbon\CarbonImmutable;

/** How many working days fall between two dates: not a weekly off day under that month's rules, and not a holiday. */
final class WorkingDays
{
    public static function between(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }
        $holidays = Holiday::query()->whereBetween('date', [$from, $to])->pluck('date')->map(fn ($date) => $date->toDateString())->flip();
        $rules = [];
        $count = 0;

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $month = $day->format('Y-m');
            $rules[$month] ??= AttendanceRule::forMonth($month);
            if (! in_array($day->dayOfWeekIso, $rules[$month]->weekly_off_days, true) && ! $holidays->has($day->toDateString())) {
                $count++;
            }
        }

        return $count;
    }
}
