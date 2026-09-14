import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';

/** Most-watched reels and photos from the Facebook page. Renders nothing until the CMS has items. */
export async function GallerySection({ locale, items }: { locale: AppLocale; items: SiteViews['gallery'] }) {
  if (items.length === 0) return null;
  const t = await getTranslations({ locale });
  const { number } = formattersFor(locale);

  return (
    <section id="gallery" className="mx-auto w-full max-w-site px-section-x py-section-y">
      <SectionHeading heading={t('sections.gallery.heading')} lede={t('sections.gallery.lede')} className="mb-9" />
      <div className="grid-auto-fill-half-200 grid gap-3.5">
        {items.map((item) => (
          <a
            key={item.url}
            href={item.url}
            target="_blank"
            rel="noopener noreferrer"
            data-reveal
            aria-label={`${item.caption ?? t('gallery.openOnFacebook')} — ${t('gallery.openOnFacebook')}`}
            className="relative block aspect-9/12 overflow-hidden rounded-18 bg-image-placeholder transition-transform duration-300 ease-lift hover:scale-103"
          >
            {item.thumbnail ? <Image src={item.thumbnail.url} alt={item.thumbnail.alt} fill sizes="(min-width: 1200px) 220px, 48vw" className="object-cover" /> : null}
            <span className="pointer-events-none absolute bottom-2.5 left-2.5 rounded-pill bg-linear-135/srgb from-orange-bright to-orange-deep px-2.5 py-1 font-display text-12 font-semibold text-white">
              {item.kind === 'reel' && item.viewsThousands != null ? t('gallery.views', { viewsText: number(item.viewsThousands) }) : t('gallery.photo')}
            </span>
          </a>
        ))}
      </div>
    </section>
  );
}
