import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getTranslations, setRequestLocale } from 'next-intl/server';

import { buttonClass } from '@/components/ui/button';
import { DownloadButton } from '@/features/downloads/DownloadButton';
import { SiteChrome } from '@/features/SiteChrome';
import { CountryCode } from '@/features/visa/CountryCode';
import { Link } from '@/i18n/navigation';
import { routing, type AppLocale } from '@/i18n/routing';
import { getSiteViews, siteUrl } from '@/lib/content';
import { loadContent } from '@/lib/content/source';
import { formattersFor } from '@/lib/formatters';
import { localizedPath, visaPath, whatsappUrl } from '@/lib/links';

export async function generateStaticParams() {
  const { visas } = await loadContent();
  return routing.locales.flatMap((locale) => visas.map((v) => ({ locale, slug: v.slug })));
}

export async function generateMetadata({ params }: PageProps<'/[locale]/site/visa/[slug]'>): Promise<Metadata> {
  const { locale, slug } = await params;
  const views = await getSiteViews(locale as AppLocale);
  const visa = views.visas.find((v) => v.slug === slug);
  if (!visa) return {};
  const t = await getTranslations({ locale });
  const path = visaPath(slug);
  const title = `${visa.country} · ${visa.visaType} · ${t('common.brand')}`;
  const description = t('visa.metaDescription', { visa: visa.visaType, country: visa.country });
  return {
    title,
    description,
    metadataBase: new URL(siteUrl()),
    alternates: { canonical: localizedPath(locale as AppLocale, path), languages: { bn: path, en: localizedPath('en', path) } },
    openGraph: { title, description, type: 'website', locale: locale === 'bn' ? 'bn_BD' : 'en_US' },
  };
}

/** A visa service on its own URL: its price, processing time, stay, requirements and notes (Phase 8 §4.C). */
export default async function VisaPage({ params }: PageProps<'/[locale]/site/visa/[slug]'>) {
  const { locale: param, slug } = await params;
  const locale = param as AppLocale;
  setRequestLocale(locale);
  const views = await getSiteViews(locale);
  const visa = views.visas.find((v) => v.slug === slug);
  if (!visa) notFound();
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);
  const others = views.visas.filter((v) => v.slug !== visa.slug && (v.countryCode ?? v.country) === (visa.countryCode ?? visa.country));
  const facts: [string, string][] = [
    [t('visa.price'), visa.price === null ? t('visa.priceOnRequest') : `${f.bdt(visa.price)} ${t('visa.perPerson')}`],
    ...(visa.processing ? [[t('visa.processing'), visa.processing] as [string, string]] : []),
    ...(visa.stay ? [[t('visa.stay'), visa.stay] as [string, string]] : []),
  ];

  return (
    <SiteChrome locale={locale} views={views} pathname={visaPath(slug)}>
      <article className="mx-auto flex max-w-modal-md flex-col gap-6 px-section-x py-section-y">
        <Link href="/#visa" className="text-14 font-semibold">
          {t('visa.backToVisas')}
        </Link>
        <header className="flex flex-col gap-2">
          <span className="flex items-center gap-2.5 font-display text-13 font-bold tracking-caps-print text-orange-deep uppercase">
            {visa.countryCode ? <CountryCode code={visa.countryCode} /> : null}
            {visa.country}
          </span>
          <h1 className="text-fluid-30-42 leading-1.18 font-bold tracking-display text-pretty">{t('visa.heading', { visa: visa.visaType, country: visa.country })}</h1>
        </header>

        <dl className="m-0 grid-auto-fit-200 grid gap-3 rounded-16 border border-hairline bg-paper-soft p-4">
          {facts.map(([label, value]) => (
            <div key={label} className="flex flex-col gap-0.5">
              <dt className="text-12 font-semibold text-muted">{label}</dt>
              <dd className="m-0 text-16 font-semibold">{value}</dd>
            </div>
          ))}
        </dl>

        <section aria-labelledby="visa-requirements" className="flex flex-col gap-3">
          <h2 id="visa-requirements" className="text-19 font-semibold">
            {t('visa.requirements')}
          </h2>
          <ol className={`m-0 flex flex-col gap-2 pl-5 text-15 leading-1.6 ${locale === 'bn' ? '[list-style-type:bengali]' : 'list-decimal'}`} data-testid="visa-requirements">
            {visa.requirements.map((requirement, index) => (
              <li key={index}>{requirement}</li>
            ))}
          </ol>
        </section>

        {visa.notes ? (
          <section aria-labelledby="visa-notes" className="flex flex-col gap-2 rounded-14 bg-orange-tint px-4 py-3.5">
            <h2 id="visa-notes" className="text-15 font-semibold text-amber">
              {t('visa.notes')}
            </h2>
            <p className="m-0 text-14 leading-1.6 whitespace-pre-line">{visa.notes}</p>
          </section>
        ) : null}

        <div className="flex flex-wrap items-center justify-between gap-3 rounded-16 border border-hairline bg-paper-soft px-fluid-18-28 py-4">
          <span className="text-14 text-muted">{t('visa.askNote')}</span>
          <div className="flex flex-wrap items-start gap-2.5">
            <DownloadButton
              path={`portal/downloads/visas/${visa.slug}?locale=${locale}`}
              filename={`bhabaghure-visa-${visa.slug}.pdf`}
              label={t('download.requirements')}
              className={buttonClass('outlineInk', 'md')}
              testId="download-visa"
            />
            <a
              href={whatsappUrl(views.settings.contact.whatsapp, t('visa.askMessage', { visa: visa.visaType, country: visa.country }))}
              target="_blank"
              rel="noopener noreferrer"
              className={buttonClass('success', 'md')}
            >
              {t('visa.ask')}
            </a>
          </div>
        </div>

        {others.length > 0 ? (
          <nav aria-label={t('visa.otherTypes', { country: visa.country })} className="flex flex-col gap-2">
            <h2 className="text-15 font-semibold">{t('visa.otherTypes', { country: visa.country })}</h2>
            <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
              {others.map((other) => (
                <li key={other.slug}>
                  <Link href={visaPath(other.slug)} className={buttonClass('outlineInk', 'sm')}>
                    {other.visaType}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        ) : null}
      </article>
    </SiteChrome>
  );
}
