<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSession;
use App\Services\Analytics\AnalyticsSettings;
use Illuminate\Console\Command;

/**
 * Deletes visits older than the retention period (Settings, 13 months by default — docs/visitor-analytics.md), their
 * events with them. Nightly; in batches, so the tables stay usable while it runs.
 */
class PruneAnalyticsCommand extends Command
{
    protected $signature = 'analytics:prune';

    protected $description = 'Delete visitor analytics older than the retention period';

    public function handle(): int
    {
        $months = AnalyticsSettings::get()['retentionMonths'];
        $before = now()->subMonthsNoOverflow($months);
        $deleted = 0;
        do {
            $ids = AnalyticsSession::query()->where('started_at', '<', $before)->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : AnalyticsSession::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);
        $this->info("Deleted {$deleted} visits started before {$before->toDateString()} ({$months} months kept).");

        return self::SUCCESS;
    }
}
