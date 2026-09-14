import { getTranslations } from 'next-intl/server';

import { CountUp } from '@/components/motion/CountUp';
import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';

type Trust = { icon: string; title: string; desc: string };

/** Four count-up stats (two derived from the catalogue) and four trust points. */
export async function WhyUsSection({ locale, stats, settings }: { locale: AppLocale; stats: SiteViews['stats']; settings: SiteViews['settings'] }) {
  const t = await getTranslations({ locale });
  const tEn = await getTranslations({ locale: 'en' });
  const trust = t.raw('whyUs.trust') as Trust[];

  const counters = [
    { key: 'packages', to: stats.packages, suffix: '' },
    { key: 'destinations', to: stats.destinations, suffix: '' },
    { key: 'reelViews', to: settings.stats.topReelViewsThousands, suffix: 'K' },
    { key: 'bangla', to: settings.stats.banglaSupportPercent, suffix: '%' },
  ] as const;

  return (
    <section id="why" className="mx-auto flex w-full max-w-site flex-col gap-8 px-section-x py-section-y">
      <SectionHeading heading={t('sections.whyUs.heading')} lede={t('sections.whyUs.lede')} />
      <div className="grid-auto-fit-half-180 grid gap-4.5">
        {counters.map((c) => (
          <div key={c.key} data-reveal className="flex flex-col gap-1 rounded-20 border border-hairline bg-white p-6">
            <CountUp to={c.to} suffix={c.suffix} className="font-display text-fluid-30-42 leading-1 font-extrabold text-blue" />
            <span className="text-15 font-semibold">{t(`whyUs.${c.key}`)}</span>
            {locale === 'bn' ? <span className="font-display text-13 text-muted">{tEn(`whyUs.${c.key}`)}</span> : null}
          </div>
        ))}
      </div>
      <div className="grid-auto-fit-240 grid gap-4.5">
        {trust.map((item) => (
          <div key={item.title} data-reveal className="flex items-start gap-3.5">
            <span aria-hidden className="flex size-9.5 shrink-0 items-center justify-center rounded-11 bg-linear-135/srgb from-blue to-blue-deep text-16 text-white">
              {item.icon}
            </span>
            <span className="flex min-w-0 flex-col gap-0.75">
              <span className="text-16 font-semibold">{item.title}</span>
              <span className="text-14 leading-1.62 text-muted">{item.desc}</span>
            </span>
          </div>
        ))}
      </div>
    </section>
  );
}
