import { getTranslations } from 'next-intl/server';

import { buttonClass } from '@/components/ui/button';
import { SectionHeading } from '@/components/ui/SectionHeading';
import { DownloadButton } from '@/features/downloads/DownloadButton';
import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { visaPath, whatsappUrl } from '@/lib/links';

import { CountryCode } from './CountryCode';

/**
 * The visas the agency processes, one card per country (docs/phase-8-visa-quotes-pricing-downloads.md §4.C): each visa
 * type with its price and processing time, and a link to its requirements. Renders nothing until the CMS has one.
 */
export async function VisaSection({ locale, views }: { locale: AppLocale; views: Pick<SiteViews, 'visaCountries' | 'settings'> }) {
  if (views.visaCountries.length === 0) return null;
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);

  return (
    <section id="visa" className="border-t border-hairline-soft bg-paper-soft">
      <div className="mx-auto flex max-w-site flex-col gap-7 px-section-x py-section-y">
        <SectionHeading heading={t('sections.visa.heading')} lede={t('sections.visa.lede')} />
        <div className="grid-auto-fit-260 grid gap-4" data-testid="visa-countries">
          {views.visaCountries.map((country) => (
            <article key={country.key} data-reveal className="flex flex-col gap-3.5 rounded-18 border border-hairline bg-white p-5 shadow-card">
              <h3 className="flex items-center gap-2.5 text-19 font-semibold">
                {country.countryCode ? <CountryCode code={country.countryCode} /> : null}
                {country.country}
              </h3>
              <ul className="m-0 flex list-none flex-col gap-3 p-0">
                {country.services.map((visa) => (
                  <li key={visa.slug} className="flex flex-col gap-1 border-t border-hairline-faint pt-3 first:border-t-0 first:pt-0">
                    <span className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                      <span className="text-15 font-semibold">{visa.visaType}</span>
                      <span className="font-display text-15 font-bold text-orange-deep">
                        {visa.price === null ? t('visa.priceOnRequest') : f.bdt(visa.price)}
                        {visa.price === null ? null : <span className="ml-1 font-sans text-12 font-normal text-muted">{t('visa.perPerson')}</span>}
                      </span>
                    </span>
                    <span className="text-13 text-muted">{t('visa.processingIn', { time: visa.processing ?? t('visa.processingAsk') })}</span>
                    <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                      <Link href={visaPath(visa.slug)} className="text-13 font-semibold">
                        {t('visa.details')}
                      </Link>
                      <DownloadButton
                        path={`portal/downloads/visas/${visa.slug}?locale=${locale}`}
                        filename={`bhabaghure-visa-${visa.slug}.pdf`}
                        label={t('download.pdf')}
                        className="inline-flex cursor-pointer items-center gap-1 text-13 font-semibold text-blue hover:text-orange disabled:opacity-50"
                      />
                    </span>
                  </li>
                ))}
              </ul>
              <a
                href={whatsappUrl(views.settings.contact.whatsapp, t('visa.askMessage', { visa: country.services[0].visaType, country: country.country }))}
                target="_blank"
                rel="noopener noreferrer"
                className={buttonClass('outlineDark', 'sm', 'mt-auto self-start')}
              >
                {t('visa.ask')}
              </a>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
