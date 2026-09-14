import { getTranslations } from 'next-intl/server';

import type { AppLocale } from '@/i18n/routing';

import { HeroVideo } from './HeroVideo';

/** Full-bleed video hero with a dark scrim and the outlined one-line headline at the bottom. */
export async function HeroSection({ locale }: { locale: AppLocale }) {
  const t = await getTranslations({ locale, namespace: 'hero' });
  const size = locale === 'bn' ? 'text-hero' : 'text-hero-sm';

  return (
    <section id="top" className="relative flex min-h-hero-min overflow-hidden bg-blue-ink text-white">
      <HeroVideo />
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
