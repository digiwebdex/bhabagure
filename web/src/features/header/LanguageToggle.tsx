'use client';

import { useLocale, useTranslations } from 'next-intl';

import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import { packagePath } from '@/lib/links';
import { useSiteUi } from '@/state/site-ui';

/**
 * বাং | EN pill. `pathname` is the page's own path without a locale prefix, passed in rather than
 * read from the URL (prerendered pages reached through the proxy rewrite would mismatch on
 * hydration). While a package modal is open the toggle follows its /packages/<slug> URL.
 */
export function LanguageToggle({ pathname }: { pathname: string }) {
  const locale = useLocale() as AppLocale;
  const t = useTranslations('common');
  const openPackage = useSiteUi((state) => state.packageSlug);
  const target: AppLocale = locale === 'bn' ? 'en' : 'bn';

  return (
    <Link
      href={openPackage ? packagePath(openPackage) : pathname}
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
