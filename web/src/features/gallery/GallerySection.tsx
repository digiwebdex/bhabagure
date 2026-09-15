import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { facebookVideoEmbedUrl } from '@/lib/links';

/** Facebook's player at the size its reel embed uses (9:16); the iframe gets exactly this size so nothing is cropped. */
const REEL_WIDTH = 260;
const REEL_HEIGHT = 462;

/**
 * Most-watched reels and photos from the Facebook page. Reels play in Facebook's embedded player, loaded only as the
 * section nears the screen; photos are tiles linking to the post. Renders nothing until the CMS has items.
 */
export async function GallerySection({ locale, items, facebook }: { locale: AppLocale; items: SiteViews['gallery']; facebook: string }) {
  if (items.length === 0) return null;
  const t = await getTranslations({ locale });
  const { number } = formattersFor(locale);
  const reels = items.filter((item) => item.kind === 'reel');
  const photos = items.filter((item) => item.kind === 'photo');

  return (
    <section id="gallery" className="mx-auto w-full max-w-site px-section-x py-section-y">
      <SectionHeading heading={t('sections.gallery.heading')} lede={t('sections.gallery.lede')} className="mb-9" />
      {reels.length > 0 ? (
        // One row that scrolls sideways on narrow screens rather than stacking four tall players.
        <ul className="-mx-section-x flex snap-x scroll-px-section-x gap-3.5 overflow-x-auto px-section-x pb-2" data-testid="gallery-reels">
          {reels.map((item, index) => (
            <li key={item.url} data-reveal className="flex w-65 shrink-0 snap-start flex-col gap-2">
              <iframe
                src={facebookVideoEmbedUrl(item.url, REEL_WIDTH, REEL_HEIGHT)}
                width={REEL_WIDTH}
                height={REEL_HEIGHT}
                loading="lazy"
                title={item.caption ?? t('gallery.reelTitle', { n: number(index + 1) })}
                allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
                allowFullScreen
                className="h-115.5 w-65 overflow-hidden rounded-18 border-0 bg-image-placeholder"
              />
              <span className="flex flex-wrap items-center justify-between gap-2 px-1 text-12.5">
                {item.viewsThousands != null ? (
                  <span className="rounded-pill bg-linear-135/srgb from-orange-bright to-orange-deep px-2.5 py-1 font-display text-12 font-semibold text-white">
                    {t('gallery.views', { viewsText: number(item.viewsThousands) })}
                  </span>
                ) : (
                  <span />
                )}
                <a href={item.url} target="_blank" rel="noopener noreferrer" className="font-semibold text-blue hover:underline">
                  {t('gallery.openOnFacebook')} ↗
                </a>
              </span>
            </li>
          ))}
        </ul>
      ) : null}
      {photos.length > 0 ? (
        <div className={`grid-auto-fill-half-200 grid gap-3.5 ${reels.length > 0 ? 'mt-6' : ''}`}>
          {photos.map((item) => (
            <a
              key={item.url}
              href={item.url}
              target="_blank"
              rel="noopener noreferrer"
              data-reveal
              aria-label={`${item.caption ?? t('gallery.photo')} — ${t('gallery.openOnFacebook')}`}
              className="relative block aspect-9/12 overflow-hidden rounded-18 bg-image-placeholder transition-transform duration-300 ease-lift hover:scale-103"
            >
              {item.thumbnail ? <Image src={item.thumbnail.url} alt={item.thumbnail.alt} fill sizes="(min-width: 1200px) 220px, 48vw" className="object-cover" /> : null}
              <span className="pointer-events-none absolute bottom-2.5 left-2.5 rounded-pill bg-linear-135/srgb from-orange-bright to-orange-deep px-2.5 py-1 font-display text-12 font-semibold text-white">
                {t('gallery.photo')}
              </span>
            </a>
          ))}
        </div>
      ) : null}
      <p className="mt-6 text-center text-14">
        <a href={facebook} target="_blank" rel="noopener noreferrer" className="font-semibold text-blue hover:underline">
          {t('gallery.morePage')} ↗
        </a>
      </p>
    </section>
  );
}
