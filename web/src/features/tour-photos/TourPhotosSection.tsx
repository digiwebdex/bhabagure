import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';

import { TourPhotoSlideshow } from './TourPhotoSlideshow';

/**
 * The group tour gallery (docs/group-tour-gallery.md): the agency's travellers on their trips, after "What travellers
 * say". Renders nothing until Admin → Group tour photos has a published photo.
 */
export async function TourPhotosSection({ locale, photos }: { locale: AppLocale; photos: SiteViews['tourPhotos'] }) {
  if (photos.length === 0) return null;
  const t = await getTranslations({ locale });

  return (
    <section id="tour-photos" className="mx-auto w-full max-w-site px-section-x py-section-y">
      <SectionHeading heading={t('sections.tourPhotos.heading')} lede={t('sections.tourPhotos.lede')} className="mb-9" />
      <TourPhotoSlideshow photos={photos} />
    </section>
  );
}
