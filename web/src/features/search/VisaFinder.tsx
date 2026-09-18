'use client';

import { useLocale, useTranslations } from 'next-intl';
import { useState } from 'react';

import { useSiteContent } from '@/components/providers/SiteContentProvider';
import { buttonClass } from '@/components/ui/button';
import { controlClass, Field } from '@/components/ui/Field';
import { DownloadButton } from '@/features/downloads/DownloadButton';
import { Link } from '@/i18n/navigation';
import { visaPath, whatsappUrl } from '@/lib/links';
import { useFormatters } from '@/lib/use-formatters';

import { searchCardClass } from './styles';

/** The search panel's Visa tab: pick a country, see its visa types with price and processing time (Phase 8 §4.C). */
export function VisaFinder() {
  const t = useTranslations('visa');
  const td = useTranslations('download');
  const locale = useLocale();
  const { visaCountries, settings } = useSiteContent();
  const f = useFormatters();
  const [key, setKey] = useState(visaCountries[0]?.key ?? '');
  const country = visaCountries.find((c) => c.key === key) ?? visaCountries[0];
  if (!country) return null;

  return (
    <div className={searchCardClass} aria-label={t('finderLabel')} role="group">
      <div className="grid-auto-fit-200 grid items-end gap-3">
        <Field label={t('country')}>
          <select value={country.key} onChange={(e) => setKey(e.target.value)} className={controlClass()}>
            {visaCountries.map((c) => (
              <option key={c.key} value={c.key}>
                {c.country}
              </option>
            ))}
          </select>
        </Field>
        <a
          href={whatsappUrl(settings.contact.whatsapp, t('askMessage', { visa: country.services[0].visaType, country: country.country }))}
          target="_blank"
          rel="noopener noreferrer"
          className={buttonClass('outlineDark', 'none', 'h-11 justify-self-start px-5 text-14')}
        >
          {t('ask')}
        </a>
      </div>
      <ul className="m-0 grid-auto-fit-260 grid list-none gap-3 p-0" data-testid="visa-finder-results">
        {country.services.map((visa) => (
          <li key={visa.slug} className="flex flex-col gap-1.5 rounded-14 border border-hairline bg-white p-4">
            <span className="text-15 font-semibold">{visa.visaType}</span>
            <span className="font-display text-17 font-bold text-orange-deep">
              {visa.price === null ? t('priceOnRequest') : f.bdt(visa.price)}
              {visa.price === null ? null : <span className="ml-1 font-sans text-12 font-normal text-muted">{t('perPerson')}</span>}
            </span>
            <span className="text-13 text-muted">{t('processingIn', { time: visa.processing ?? t('processingAsk') })}</span>
            <span className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
              <Link href={visaPath(visa.slug)} className="text-13 font-semibold">
                {t('details')}
              </Link>
              <DownloadButton
                path={`portal/downloads/visas/${visa.slug}?locale=${locale}`}
                filename={`bhabaghure-visa-${visa.slug}.pdf`}
                label={td('pdf')}
                className="inline-flex cursor-pointer items-center gap-1 text-13 font-semibold text-blue hover:text-orange disabled:opacity-50"
              />
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
