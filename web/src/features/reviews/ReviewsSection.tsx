import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';

import { ReviewCards } from './ReviewCarousel';
import { ReviewForm } from './ReviewForm';

/**
 * "What travellers say": reviews staff wrote and customers' reviews staff approved, with their trip photos
 * (docs/customer-reviews.md). Always shown, so the first customer can write one; the cards appear once there are some,
 * two rows at a time (ReviewCards).
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
        <ReviewCards reviews={reviews} />
      )}
    </section>
  );
}
