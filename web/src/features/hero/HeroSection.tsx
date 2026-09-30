import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';
import type { SiteSettings } from '@/lib/content/types';

import { HeroVideo } from './HeroVideo';

/**
 * The video the site ships with, played until staff choose another in Site settings. Kept here, in a server module: a
 * value exported from a 'use client' file reaches a server component only as a client reference, not the value.
 */
const DEFAULT_HERO = { videoUrl: '/media/hero.mp4', posterUrl: '/media/hero-poster.jpg' };

/**
 * Full-bleed video hero with a dark scrim and the outlined one-line headline at the bottom. The video is the one staff
 * set in Site settings, else the one the site ships with (docs/hero-video.md).
 */
export async function HeroSection({ locale, hero }: { locale: AppLocale; hero?: SiteSettings['hero'] }) {
  const t = await getTranslations({ locale, namespace: 'hero' });
  const size = locale === 'bn' ? 'text-hero' : 'text-hero-sm';
  const video = hero ?? DEFAULT_HERO;

  return (
    <section id="top" className="relative flex min-h-hero-min overflow-hidden bg-blue-ink text-white">
      <HeroVideo src={video.videoUrl} poster={video.posterUrl} />
      <div aria-hidden className="absolute inset-0 bg-linear-to-b/srgb from-scrim/55 via-scrim/18 via-40% to-scrim/72" />
      <div className="relative mx-auto flex w-full max-w-site flex-col items-center justify-end px-hero-x pt-fluid-72-110 pb-fluid-32-56 text-center">
        <h1 className={`max-w-full whitespace-nowrap font-bold leading-1.2 drop-shadow-hero text-stroke-hero ${size}`}>
          {t('before')}
          <span className="text-stroke-highlight">{t('highlight')}</span>
          {t('after')}
        </h1>
      </div>
    </section>
  );
}
