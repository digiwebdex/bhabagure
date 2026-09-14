'use client';

import Image from 'next/image';
import { useTranslations } from 'next-intl';

import type { ImageView } from '@/lib/content/views';
import { useFormatters } from '@/lib/use-formatters';
import { useSiteUi } from '@/state/site-ui';

/** 16:9 photo with credit and counter, and a strip of thumbnails beneath. */
export function PhotoGallery({ images, title }: { images: ImageView[]; title: string }) {
  const t = useTranslations('detail');
  const { number } = useFormatters();
  const photoIndex = useSiteUi((state) => state.photoIndex);
  const setPhotoIndex = useSiteUi((state) => state.setPhotoIndex);
  const index = Math.min(photoIndex, Math.max(images.length - 1, 0));
  const current = images[index];

  return (
    <div className="flex flex-col gap-2.5">
      <div className="relative aspect-video w-full overflow-hidden rounded-16 bg-image-placeholder">
        {current ? (
          <Image key={current.url} src={current.url} alt={current.alt || title} fill sizes="(min-width: 820px) 764px, 100vw" className="object-cover" priority />
        ) : null}
        {current?.credit ? (
          <a
            href={current.creditUrl ?? undefined}
            target="_blank"
            rel="noopener noreferrer"
            className="absolute bottom-2.5 left-3 rounded-pill bg-scrim/62 px-2.25 py-0.75 text-10.5 text-white hover:text-white"
          >
            {current.credit}
          </a>
        ) : null}
        {images.length > 1 ? (
          <span className="pointer-events-none absolute right-3 bottom-3 rounded-pill bg-scrim/72 px-3 py-1.25 font-display text-12 font-semibold text-white">
            {t('photoCounter', { current: number(index + 1), total: number(images.length) })}
          </span>
        ) : null}
      </div>
      {images.length > 1 ? (
        <div className="flex gap-2 overflow-x-auto pb-0.5">
          {images.map((img, i) => (
            <button
              key={img.url}
              type="button"
              onClick={() => setPhotoIndex(i)}
              title={t('viewPhoto', { n: number(i + 1) })}
              aria-label={t('viewPhoto', { n: number(i + 1) })}
              aria-pressed={i === index}
              className={`relative h-14.5 w-19.5 shrink-0 cursor-pointer overflow-hidden rounded-11 border-2 bg-image-placeholder p-0 transition-[opacity,border-color] duration-200 ${
                i === index ? 'border-orange-deep opacity-100' : 'border-hairline opacity-62'
              }`}
            >
              <Image src={img.url} alt="" fill sizes="78px" className="object-cover" />
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}
