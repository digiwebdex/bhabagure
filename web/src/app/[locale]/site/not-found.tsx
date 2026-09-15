'use client';

import Image from 'next/image';
import { useTranslations } from 'next-intl';

import { buttonClass } from '@/components/ui/button';
import { Link } from '@/i18n/navigation';

/**
 * The website's 404: an unknown address, or a package or post that no longer exists. A client component on purpose: it
 * takes the language from the layout's provider. Reading it on the server (getLocale) made every page of the site
 * dynamic, and without next-intl's middleware a dynamic render can't see the locale, so /en rendered in Bangla.
 */
export default function SiteNotFound() {
  const t = useTranslations();

  return (
    <main className="flex min-h-dvh flex-col items-center justify-center bg-ink-deep px-section-x py-section-y text-white">
      <div className="flex w-full max-w-narrow flex-col items-start gap-4">
        <Link href="/" title={t('common.homeLink')}>
          <Image src="/brand/logo-wordmark-light.png" alt={t('common.brand')} width={852} height={378} className="h-12 w-auto" priority />
        </Link>
        <span aria-hidden className="mt-6 h-0.5 w-7 bg-orange-deep" />
        <p className="font-display text-14 font-bold tracking-caps-print text-orange-light">404</p>
        <h1 className="text-fluid-30-42 leading-1.18 font-bold tracking-display text-pretty">{t('meta.notFoundTitle')}</h1>
        <p className="text-16 leading-1.75 text-white/85 text-pretty">{t('common.notFoundNote')}</p>
        <div className="mt-2 flex flex-wrap gap-3">
          <Link href="/" className={buttonClass('cta', 'lg')}>
            {t('common.backToHome')}
          </Link>
          <Link href="/#packages" className={buttonClass('outlineInk', 'lg')}>
            {t('nav.packages')}
          </Link>
        </div>
      </div>
    </main>
  );
}
