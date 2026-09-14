import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { initialsOf } from '@/lib/initials';

/** Real traveller reviews from the CMS. Renders nothing until the client adds some. */
export async function ReviewsSection({ locale, reviews }: { locale: AppLocale; reviews: SiteViews['reviews'] }) {
  if (reviews.length === 0) return null;
  const t = await getTranslations({ locale, namespace: 'sections.reviews' });

  return (
    <section id="reviews" className="mx-auto flex w-full max-w-site flex-col gap-8 px-section-x py-section-y">
      <SectionHeading heading={t('heading')} lede={t('lede')} />
      <div className="grid-auto-fit-280 grid gap-4.5">
        {reviews.map((review) => (
          <figure key={`${review.reviewerName}-${review.tripLabel}`} data-reveal className="flex flex-col gap-3.5 rounded-20 border border-hairline bg-white p-6">
            <span role="img" aria-label={`${review.rating}/5`} className="text-15 tracking-stars text-orange">
              {'★'.repeat(review.rating)}
            </span>
            <blockquote className="text-16 leading-1.6 text-pretty">{review.quote}</blockquote>
            <figcaption className="mt-auto flex items-center gap-3">
              <span aria-hidden className="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-135/srgb from-blue to-orange font-display font-extrabold text-white">
                {initialsOf(review.reviewerName)}
              </span>
              <span className="flex min-w-0 flex-col leading-1.25">
                <span className="text-15 font-semibold">{review.reviewerName}</span>
                <span className="text-13 text-muted">{review.tripLabel}</span>
              </span>
            </figcaption>
          </figure>
        ))}
      </div>
    </section>
  );
}
