import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { ReviewView, SiteViews } from '@/lib/content/views';
import { initialsOf } from '@/lib/initials';

import { ReviewForm } from './ReviewForm';
import { ReviewPhotos } from './ReviewPhotos';

/**
 * "What travellers say": reviews staff wrote and customers' reviews staff approved, with their trip photos
 * (docs/customer-reviews.md). Always shown, so the first customer can write one; the cards appear once there are some.
 */
export async function ReviewsSection({ locale, reviews }: { locale: AppLocale; reviews: SiteViews['reviews'] }) {
  const t = await getTranslations({ locale, namespace: 'sections.reviews' });
  const tf = await getTranslations({ locale, namespace: 'reviewForm' });

  return (
    <section id="reviews" className="mx-auto flex w-full max-w-site flex-col gap-8 px-section-x py-section-y">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <SectionHeading heading={t('heading')} lede={t('lede')} />
        <ReviewForm />
      </div>
      {reviews.length === 0 ? (
        <p className="rounded-20 border border-dashed border-hairline bg-paper-soft px-6 py-8 text-center text-15 text-muted">{tf('beFirst')}</p>
      ) : (
        <ReviewCards reviews={reviews} verifiedLabel={tf('verified')} />
      )}
    </section>
  );
}

/** The review cards, on the home page and on a package's page. */
export function ReviewCards({ reviews, verifiedLabel }: { reviews: ReviewView[]; verifiedLabel: string }) {
  return (
    <div className="grid-auto-fit-280 grid gap-4.5" data-testid="review-cards">
      {reviews.map((review, index) => (
        <figure key={`${index}-${review.reviewerName}-${review.tripLabel}`} data-reveal className="flex flex-col gap-3.5 rounded-20 border border-hairline bg-white p-6">
          <span role="img" aria-label={`${review.rating}/5`} className="text-15 tracking-stars text-orange">
            {'★'.repeat(review.rating)}
          </span>
          <blockquote className="text-16 leading-1.6 text-pretty whitespace-pre-line">{review.quote}</blockquote>
          <ReviewPhotos photos={review.photos} reviewer={review.reviewerName} />
          <figcaption className="mt-auto flex items-center gap-3">
            <span aria-hidden className="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-135/srgb from-blue to-orange font-display font-extrabold text-white">
              {initialsOf(review.reviewerName)}
            </span>
            <span className="flex min-w-0 flex-col leading-1.25">
              <span className="flex flex-wrap items-center gap-x-2 text-15 font-semibold">
                {review.reviewerName}
                {review.verified ? (
                  <span className="rounded-pill bg-green-tint px-2 py-0.5 text-11 font-semibold text-green-deep" data-testid="review-verified">
                    ✓ {verifiedLabel}
                  </span>
                ) : null}
              </span>
              <span className="text-13 text-muted">{review.tripLabel}</span>
            </span>
          </figcaption>
        </figure>
      ))}
    </div>
  );
}
