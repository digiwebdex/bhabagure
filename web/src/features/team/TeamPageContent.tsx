import { getTranslations } from 'next-intl/server';

import { aboutFacts } from '@/features/about/facts';
import { TeamGrid } from '@/features/about/TeamGrid';
import { ContactSection } from '@/features/contact/ContactSection';
import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';

/**
 * The team page (bhabaghure.com.bd/ourteam): a short navy banner in the home page's heading style with the About facts,
 * every visible team member from the CMS in the home page's cards, then the home page's contact section.
 */
export async function TeamPageContent({ locale, views }: { locale: AppLocale; views: SiteViews }) {
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);
  const facts = aboutFacts(t, f, views.stats, views.settings);
  const lede = t('teamPage.lede');

  return (
    <>
      <section id="top" className="bg-ink-deep text-white">
        <div className="mx-auto flex max-w-site flex-col gap-6 px-section-x py-section-y">
          <Link href="/" className="self-start text-14 font-semibold text-on-navy-soft hover:text-orange-light">
            {t('common.backToHome')}
          </Link>
          <div data-reveal className="flex max-w-narrow flex-col gap-1.5">
            <span aria-hidden className="mb-4 h-0.5 w-7 bg-orange-deep" />
            <h1 className="text-fluid-30-42 leading-1.18 font-bold tracking-display text-pretty">{t('teamPage.title')}</h1>
            {lede ? <p className="font-display text-lede text-on-navy-soft">{lede}</p> : null}
            <p className="mt-3 text-16 leading-1.75 text-white/85 text-pretty">{t('teamPage.intro')}</p>
          </div>
          <dl className="grid-auto-fit-half-150 grid gap-3.5">
            <div className="flex flex-col-reverse gap-0.5 rounded-14 border border-white/12 bg-white/5 px-4 py-3.5">
              <dt className="text-12.5 leading-1.4 text-on-navy-soft">{t('teamPage.people', { count: views.team.length })}</dt>
              <dd className="font-display text-20 font-extrabold tracking-heading text-orange-light">{f.number(views.team.length)}</dd>
            </div>
            {facts.map((fact) => (
              <div key={fact.label} className="flex flex-col-reverse gap-0.5 rounded-14 border border-white/12 bg-white/5 px-4 py-3.5">
                <dt className="text-12.5 leading-1.4 text-on-navy-soft">{fact.label}</dt>
                <dd className="font-display text-20 font-extrabold tracking-heading text-orange-light">{fact.value}</dd>
              </div>
            ))}
          </dl>
        </div>
      </section>

      <section id="team" aria-label={t('teamPage.title')} className="bg-white">
        <div className="mx-auto flex max-w-site flex-col gap-8 px-section-x py-section-y">
          {views.team.length > 0 ? (
            <TeamGrid locale={locale} team={views.team} size="page" />
          ) : (
            <p className="text-15 text-muted">{t('teamPage.empty')}</p>
          )}
        </div>
      </section>

      <ContactSection locale={locale} settings={views.settings} />
    </>
  );
}
