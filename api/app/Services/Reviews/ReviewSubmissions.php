<?php

namespace App\Services\Reviews;

use App\Enums\BookingStatus;
use App\Enums\ContentStatus;
use App\Jobs\RevalidateWebsite;
use App\Models\Booking;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\Staff;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use App\Services\Media\ImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reviews customers send from the website (docs/customer-reviews.md). A review waits for staff and shows only once
 * approved; its photos go through the media library (WebP, the phone's location data removed). When the customer's
 * mobile matches a booking, the review is marked as from someone who travelled with the agency.
 */
final class ReviewSubmissions
{
    public const MAX_PHOTOS = 5;

    public const PHOTO_MAX_KB = 8192;

    /** Reviews one mobile number may send in a day: a real traveller writes one, not a stream. */
    public const PER_PHONE_PER_DAY = 3;

    public function __construct(private readonly ImageUploader $uploader, private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, phone: string, package_slug?: ?string, trip?: ?string, travelled_month?: ?string, rating: int, review: string, locale: string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function submit(array $data, array $photos): Review
    {
        $package = filled($data['package_slug'] ?? null) ? TourPackage::query()->published()->where('slug', $data['package_slug'])->first() : null;
        $text = trim($data['review']);
        $trip = trim((string) ($data['trip'] ?? ''));

        // Photos first: a picture the library can't read refuses the whole review before anything is saved.
        $media = [];
        try {
            foreach (array_values($photos) as $index => $photo) {
                $media[] = $this->uploader->store($photo, null, [
                    'alt_bn' => "{$data['name']}-এর ট্রিপের ছবি ".($index + 1),
                    'alt_en' => 'Trip photo '.($index + 1)." from {$data['name']}",
                ]);
            }

            return DB::transaction(function () use ($data, $package, $text, $trip, $media) {
                $review = Review::query()->create([
                    // Shown as written, whichever language it is in; staff may add the other language.
                    'quote_bn' => $text,
                    'quote_en' => $text,
                    'reviewer_name' => trim($data['name']),
                    'trip_label_bn' => $package?->title_bn ?: ($package?->title_en ?? ($trip ?: null)),
                    'trip_label_en' => $package?->title_en ?? ($trip ?: null),
                    'rating' => (int) $data['rating'],
                    'travelled_on' => filled($data['travelled_month'] ?? null) ? "{$data['travelled_month']}-01" : null,
                    'tour_package_id' => $package?->id,
                    'status' => ContentStatus::Draft,
                    'sort_order' => (int) Review::query()->max('sort_order') + 1,
                    'source' => Review::CUSTOMER,
                    'phone' => $data['phone'],
                    'booking_id' => self::bookingFor($data['phone'])?->id,
                ]);
                foreach ($media as $index => $item) {
                    ReviewPhoto::query()->create(['review_id' => $review->id, 'media_id' => $item->id, 'sort_order' => $index]);
                }
                $this->audit->record('review.submitted', null, $review, ['photos' => count($media), 'booking_id' => $review->booking_id]);

                return $review;
            });
        } catch (Throwable $e) {
            foreach ($media as $item) {
                $this->uploader->delete($item);
            }
            throw $e;
        }
    }

    /** The customer's latest booking that went ahead — confirmed or completed — by the lead's or the account's number. */
    public static function bookingFor(string $phone): ?Booking
    {
        return Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->where(fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('phone', $phone))->orWhereHas('travellers', fn ($t) => $t->where('phone', $phone)))
            ->latest('id')->first();
    }

    public function approve(Review $review, Staff $by): Review
    {
        return $this->decide($review, $by, function (Review $locked) {
            $locked->forceFill(['status' => ContentStatus::Published, 'reviewed_at' => now()]);
        }, 'review.approved');
    }

    public function reject(Review $review, ?string $reason, Staff $by): Review
    {
        return $this->decide($review, $by, function (Review $locked) use ($reason) {
            $locked->forceFill(['status' => ContentStatus::Draft, 'reviewed_at' => now(), 'rejected_at' => now(), 'reject_reason' => $reason]);
        }, 'review.rejected', ['reason' => $reason]);
    }

    /** Hide or show one photo of a review, e.g. one that shows someone who didn't want to be on the website. */
    public function showPhoto(ReviewPhoto $photo, bool $shown, Staff $by): ReviewPhoto
    {
        $photo->forceFill(['is_shown' => $shown])->save();
        $this->audit->record($shown ? 'review.photo_shown' : 'review.photo_hidden', $by, $photo->review, ['photo_id' => $photo->id]);
        RevalidateWebsite::dispatch(['reviews']);

        return $photo;
    }

    /**
     * @param  callable(Review): void  $change
     * @param  array<string, mixed>  $meta
     *
     * @throws ReviewAlreadyDecided
     */
    private function decide(Review $review, Staff $by, callable $change, string $action, array $meta = []): Review
    {
        $decided = DB::transaction(function () use ($review, $by, $change, $action, $meta) {
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new ReviewAlreadyDecided;
            }
            $change($locked);
            $locked->forceFill(['reviewed_by_staff_id' => $by->id])->save();
            $this->audit->record($action, $by, $locked, $meta);

            return $locked;
        });
        // Package pages show their reviews from the same list, so this tag refreshes them too.
        RevalidateWebsite::dispatch(['reviews']);

        return $decided;
    }
}
