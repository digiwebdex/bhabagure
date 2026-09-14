import { getTranslations } from 'next-intl/server';

import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';

interface LanguageToggleProps {
  locale: AppLocale;
  /** The page's own path without a locale prefix, e.g. "/" or "/packages/nepal-01".
   *  Passed in rather than read from the URL: pages are prerendered and reached
   *  through the proxy rewrite, where usePathname() would cause a hydration mismatch. */
  pathname: string;
}

/** Website header pill: বাং | EN, the inactive language at 40% (Bhabaghure Website.dc.html). */
export async function LanguageToggle({ locale, pathname }: LanguageToggleProps) {
  const t = await getTranslations({ locale, namespace: 'common' });
  const target: AppLocale = locale === 'bn' ? 'en' : 'bn';

  return (
    <Link
      href={pathname}
      locale={target}
      hrefLang={target}
      title={t('language')}
      aria-label={t('switchLanguage')}
      className="flex shrink-0 items-center gap-1.75 rounded-pill border border-input bg-white px-3.5 py-1.75 text-13 font-bold text-blue-deep transition-colors hover:border-orange hover:text-orange"
    >
      <span className={locale === 'bn' ? undefined : 'opacity-40'}>বাং</span>
      <span aria-hidden className="h-3 w-px bg-input" />
      <span className={locale === 'en' ? undefined : 'opacity-40'}>EN</span>
    </Link>
  );
}
