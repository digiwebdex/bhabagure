import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import { Link } from '@/i18n/navigation';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { teamPath } from '@/lib/links';

import { aboutFacts } from './facts';
import { TeamGrid } from './TeamGrid';

interface AboutSectionProps {
  locale: AppLocale;
  team: SiteViews['team'];
  stats: SiteViews['stats'];
  settings: SiteViews['settings'];
}

/** The home page introduces the two who lead the agency; the rest of the team is on /ourteam. */
const LEADS = 2;

/**
 * Company description with the four fact cards, and beside them the two people who lead the agency, with the way to
 * the full team page.
 */
export async function AboutSection({ locale, team, stats, settings }: AboutSectionProps) {
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);
  const licence = f.digits(settings.civilAviationNo);
  const paragraphs = (t.raw('about.paras') as string[]).map((_, i) => t(`about.paras.${i}`, { licence }));
  const facts = aboutFacts(t, f, stats, settings);
  const leads = team.slice(0, LEADS);

  return (
    <section id="about" className="border-t border-hairline-soft bg-white">
      <div className="mx-auto flex max-w-site flex-col gap-8 px-section-x py-section-y">
        <SectionHeading heading={t('sections.about.heading')} lede={t('sections.about.lede')} />
        {/* The story and its figures fill the wider column; the two leads stand beside them, and under them on a phone. */}
        <div className="grid items-start gap-fluid-20-32 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
          <div className="flex min-w-0 flex-col gap-5">
            <div data-reveal className="flex flex-col gap-3.5">
              {paragraphs.map((text) => (
                <p key={text} className="text-15 leading-1.75 text-muted text-pretty">
                  {text}
                </p>
              ))}
            </div>

            {/* Two up on a phone, four across from a tablet on. */}
            <dl data-reveal className="grid min-w-0 grid-cols-2 gap-3.5 md:grid-cols-4">
              {facts.map((fact) => (
                <div key={fact.label} className="flex flex-col-reverse gap-0.5 rounded-14 border border-hairline bg-paper-alt px-4 py-3.5">
                  <dt className="text-12.5 leading-1.4 text-muted">{fact.label}</dt>
                  <dd className="font-display text-20 font-extrabold tracking-heading text-blue">{fact.value}</dd>
                </div>
              ))}
            </dl>
          </div>

          <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <h3 className="text-19 font-bold tracking-heading">{t('about.team')}</h3>
              <Link href={teamPath} className="text-14 font-semibold whitespace-nowrap hover:text-orange">
                {t('about.seeTeam')}
              </Link>
            </div>
            <TeamGrid locale={locale} team={leads} />
          </div>
        </div>
      </div>
    </section>
  );
}
