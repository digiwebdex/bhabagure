import { getTranslations } from 'next-intl/server';

import { SectionHeading } from '@/components/ui/SectionHeading';
import type { AppLocale } from '@/i18n/routing';
import type { SiteViews } from '@/lib/content/views';
import { formattersFor } from '@/lib/formatters';

import { packageLabels } from '../packages/package-labels';
import { BookSeatButton } from './BookSeatButton';

/** Upcoming group departures with seats-left bars. Renders nothing until real departures exist. */
export async function DeparturesSection({ locale, departures }: { locale: AppLocale; departures: SiteViews['departures'] }) {
  if (departures.length === 0) return null;
  const t = await getTranslations({ locale });
  const tp = await getTranslations({ locale, namespace: 'packages' });
  const f = formattersFor(locale);

  return (
    <section id="departures" className="bg-ink-deep text-white">
      <div className="mx-auto flex max-w-site flex-col gap-7 px-section-x py-section-y">
        <SectionHeading heading={t('sections.departures.heading')} lede={t('sections.departures.lede')} tone="dark" />
        <div className="grid-auto-fit-260 grid gap-4">
          {departures.map((d) => {
            const almostFull = d.seatsLeft <= 3;
            const booked = d.seatsTotal - d.seatsLeft;
            const labels = packageLabels({ ...d, code: '', groupMode: 'any', minPax: null, departureMode: 'any_date' }, tp, f);
            const dateText = d.departsOn ? f.date(d.departsOn) : d.dateLabel;
            return (
              <div
                key={`${d.packageSlug}-${d.departsOn ?? dateText}`}
                data-reveal
                className="flex flex-col gap-3 rounded-18 border border-white/12 bg-white/5 p-5 transition duration-250 ease-lift hover:-translate-y-1 hover:bg-white/9"
              >
                <div className="flex items-start justify-between gap-2.5">
                  <span className="font-display text-13 font-bold tracking-caps-print text-orange-soft uppercase">{dateText}</span>
                  {almostFull || d.isGuaranteed ? (
                    <span
                      className={`rounded-pill px-2.25 py-0.75 text-11 font-bold whitespace-nowrap ${
                        almostFull ? 'bg-orange/22 text-orange-soft' : 'bg-green/20 text-green-soft'
                      }`}
                    >
                      {almostFull ? t('departures.almostFull') : t('departures.guaranteed')}
                    </span>
                  ) : null}
                </div>
                <div className="text-17 leading-1.3 font-semibold">{d.packageTitle}</div>
                <div className="text-13 text-on-navy">
                  {labels.duration} · {labels.air}
                </div>
                <div className="mt-auto flex flex-col gap-1.5">
                  <div className="h-1.5 overflow-hidden rounded-3 bg-white/14" role="progressbar" aria-valuemin={0} aria-valuemax={d.seatsTotal} aria-valuenow={booked}>
                    <div className="h-full bg-linear-to-r/srgb from-blue to-orange-bright" style={{ width: `${Math.round((booked / d.seatsTotal) * 100)}%` }} />
                  </div>
                  <div className="flex justify-between gap-2 text-12 text-on-navy">
                    <span>{t('departures.seatsLeft', { leftText: f.number(d.seatsLeft), totalText: f.number(d.seatsTotal) })}</span>
                    <span className="font-display text-15 font-bold text-white">{f.bdt(d.listPrice)}</span>
                  </div>
                </div>
                <BookSeatButton packageSlug={d.packageSlug} departsOn={d.departsOn} label={t('departures.book')} />
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}
