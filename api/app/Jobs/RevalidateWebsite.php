<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Tells the website which cached content changed (web/src/app/api/revalidate/route.ts), so an editor
 * sees their save on the next page load. Dispatched after the database transaction commits.
 */
class RevalidateWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Cache tags the website knows. */
    public const TAGS = ['packages', 'departures', 'posts', 'team', 'reviews', 'gallery', 'visas', 'settings'];

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    /** @param list<string> $tags */
    public function __construct(public array $tags)
    {
        $this->tags = array_values(array_intersect(self::TAGS, $tags));
        $this->afterCommit();
    }

    public function handle(): void
    {
        $url = config('bhabaghure.web_revalidate_url');
        if (blank($url) || $this->tags === []) {
            return;
        }

        Http::withToken((string) config('bhabaghure.revalidate_secret'))
            ->acceptJson()
            ->timeout(10)
            ->post($url, ['tags' => $this->tags])
            ->throw();
    }
}
