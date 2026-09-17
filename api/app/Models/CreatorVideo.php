<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;

/** A YouTube video staff picked to show under the travel host's cards on the home page. */
class CreatorVideo extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    /** Every shape a video link is shared in — watch, youtu.be, shorts, live, embed — with the video id captured. */
    public const URL = '#^https://(?:(?:www\.|m\.)?youtube\.com/(?:watch\?(?:[^\#]*&)?v=|shorts/|live/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#';

    protected $fillable = ['youtube_id', 'title_bn', 'title_en', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'sort_order' => 'integer'];
    }

    public static function idFromUrl(?string $url): ?string
    {
        return preg_match(self::URL, trim((string) $url), $found) === 1 ? $found[1] : null;
    }

    public function watchUrl(): string
    {
        return "https://www.youtube.com/watch?v={$this->youtube_id}";
    }

    /** YouTube's own still at the size every video has; maxresdefault is missing on older uploads. */
    public function thumbnailUrl(): string
    {
        return "https://i.ytimg.com/vi/{$this->youtube_id}/hqdefault.jpg";
    }
}
