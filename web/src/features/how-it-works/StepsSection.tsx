import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import { formattersFor } from '@/lib/formatters';

type Step = { title: string; desc: string };

export async function StepsSection({ locale }: { locale: AppLocale }) {
  const t = await getTranslations({ locale });
  const steps = t.raw('how.items') as Step[];
  const { number } = formattersFor(locale);

  return (
    <section id="how" className="border-y border-hairline-soft bg-paper-alt">
      <div className="mx-auto flex max-w-site flex-col gap-8 px-section-x py-section-y">
        <SectionHeading heading={t('sections.how.heading')} lede={t('sections.how.lede')} />
        <ol className="grid-auto-fit-220 grid gap-4.5">
          {steps.map((step, i) => (
            <li key={step.title} data-reveal className="flex flex-col gap-2.5 rounded-20 border border-hairline bg-white p-6">
              <span className="font-display text-13 font-extrabold tracking-step text-orange">{t('how.step', { n: number(i + 1) })}</span>
              <span className="text-18 leading-1.3 font-semibold">{step.title}</span>
              <span className="text-14 leading-1.625 text-muted">{step.desc}</span>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
