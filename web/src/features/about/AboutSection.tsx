import Image from 'next/image';
import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';
import { initialsOf } from '@/lib/initials';

interface AboutSectionProps {
  locale: AppLocale;
  team: SiteViews['team'];
  stats: SiteViews['stats'];
  settings: SiteViews['settings'];
}

/** Company description, four fact cards and the CMS-managed team grid. */
export async function AboutSection({ locale, team, stats, settings }: AboutSectionProps) {
  const t = await getTranslations({ locale });
  const f = formattersFor(locale);
  const licence = f.digits(settings.civilAviationNo);
  const paragraphs = (t.raw('about.paras') as string[]).map((_, i) => t(`about.paras.${i}`, { licence }));

  const facts = [
    { value: f.number(stats.packages), label: t('about.facts.packages') },
    { value: f.number(stats.destinations), label: t('about.facts.destinations') },
    { value: licence, label: t('about.facts.licence') },
    { value: `${f.number(settings.hours.opens)}–${f.number(settings.hours.closes - 12)}`, label: t('about.facts.hours') },
  ];

  return (
    <section id="about" className="border-t border-hairline-soft bg-white">
      <div className="mx-auto flex max-w-site flex-col gap-8 px-section-x py-section-y">
        <SectionHeading heading={t('sections.about.heading')} lede={t('sections.about.lede')} />
        <div className="grid-auto-fit-280 grid items-start gap-fluid-20-32">
          <div data-reveal className="flex min-w-0 flex-col gap-3.5">
            {paragraphs.map((text) => (
              <p key={text} className="text-15 leading-1.75 text-muted text-pretty">
                {text}
              </p>
            ))}
            <dl className="grid-auto-fit-half-130 mt-1 grid gap-3.5">
              {facts.map((fact) => (
                <div key={fact.label} className="flex flex-col-reverse gap-0.5 rounded-14 border border-hairline bg-paper-alt px-4 py-3.5">
                  <dt className="text-12.5 leading-1.4 text-muted">{fact.label}</dt>
                  <dd className="font-display text-20 font-extrabold tracking-heading text-blue">{fact.value}</dd>
                </div>
              ))}
            </dl>
          </div>

          <div className="flex min-w-0 flex-col gap-4">
            <h3 className="text-19 font-bold tracking-heading">{t('about.team')}</h3>
            <ul className="grid-auto-fit-190 grid gap-4">
              {team.map((member) => (
                <li key={member.employeeCode} data-reveal className="flex flex-col overflow-hidden rounded-20 border border-hairline bg-white">
                  <div className="relative flex aspect-square w-full items-center justify-center bg-image-placeholder">
                    {member.photo ? (
                      <Image src={member.photo.url} alt={member.photo.alt || member.name} fill sizes="(min-width: 1200px) 270px, 50vw" className="object-cover" />
                    ) : (
                      <span aria-hidden className="font-display text-40 font-extrabold text-muted-label/40">
                        {initialsOf(member.name)}
                      </span>
                    )}
                  </div>
                  <div className="flex flex-col gap-1 p-4">
                    <strong className="text-16 leading-1.3 font-semibold">{member.name}</strong>
                    <span className="text-13 text-muted">{locale === 'bn' ? `${member.role} · ${member.roleEn}` : member.role}</span>
                    <span className="mt-0.5 font-display text-12 font-bold tracking-caps-print text-blue">
                      {t('about.idLabel')} {member.employeeCode}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </section>
  );
}
